package relay

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"net"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"golang.org/x/crypto/ssh"
)

type fakeSSHAPI struct {
	mu    sync.Mutex
	fp    string
	calls []string
}

func (f *fakeSSHAPI) SSHSession(_ context.Context, fp, target, ip string) (*SSHTarget, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.calls = append(f.calls, target+"@"+ip)
	if fp != f.fp {
		return nil, &APIError{Code: 401, Message: "This SSH key is not registered. Add it under Account → SSH keys."}
	}
	switch target {
	case "list":
		return &SSHTarget{User: "ann@example.com", Targets: []string{"web +acme"}}, nil
	case "web":
		return &SSHTarget{SessionID: "sess-1", Token: "good", Container: "web", User: "ann@example.com"}, nil
	}
	return nil, &APIError{Code: 404, Message: "No running container called " + target + "."}
}

func startGateway(t *testing.T, api *fakeSSHAPI) (string, ssh.Signer) {
	t.Helper()
	relayAPI := &fakeAPI{expires: time.Now().Add(time.Minute)}
	s, srv := newRelay(t, relayAPI)
	fakeAgent(t, srv, "agent-secret")
	waitFor(t, func() bool { return s.ConnectedAgents("srv-1") })
	host, err := LoadOrCreateHostKey(filepath.Join(t.TempDir(), "k", "host"))
	if err != nil {
		t.Fatal(err)
	}
	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { l.Close() })
	gw := &SSHGateway{API: api, HostKey: host, Relay: s.Handler(), Log: tlog{t}}
	go gw.Serve(l)
	_, priv, _ := ed25519.GenerateKey(rand.Reader)
	user, _ := ssh.NewSignerFromKey(priv)
	api.fp = ssh.FingerprintSHA256(user.PublicKey())
	return l.Addr().String(), user
}

func dialSSH(t *testing.T, addr, target string, key ssh.Signer) *ssh.Client {
	t.Helper()
	c, err := ssh.Dial("tcp", addr, &ssh.ClientConfig{User: target, Auth: []ssh.AuthMethod{ssh.PublicKeys(key)}, HostKeyCallback: ssh.InsecureIgnoreHostKey(), Timeout: 5 * time.Second})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { c.Close() })
	return c
}

func TestSSHGatewayShellRoundTrip(t *testing.T) {
	api := &fakeSSHAPI{}
	addr, key := startGateway(t, api)
	sess, err := dialSSH(t, addr, "web", key).NewSession()
	if err != nil {
		t.Fatal(err)
	}
	out := &safeBuf{}
	sess.Stdout, sess.Stderr = out, out
	in, _ := sess.StdinPipe()
	if err := sess.RequestPty("xterm", 40, 120, ssh.TerminalModes{}); err != nil {
		t.Fatal(err)
	}
	if err := sess.Shell(); err != nil {
		t.Fatal(err)
	}
	deadline := time.Now().Add(5 * time.Second)
	for !strings.Contains(out.String(), "Connected as") && time.Now().Before(deadline) {
		time.Sleep(20 * time.Millisecond)
	}
	in.Write([]byte("echo hi\n"))
	for !strings.Contains(out.String(), "ECHO HI") && time.Now().Before(deadline) {
		time.Sleep(20 * time.Millisecond)
	}
	in.Write([]byte("exit\n"))
	if err := sess.Wait(); err != nil {
		t.Fatalf("wait: %v (out %q)", err, out.String())
	}
	if !strings.Contains(out.String(), "ECHO HI") {
		t.Fatalf("output %q", out.String())
	}
	if c := api.calls[0]; c != "web@127.0.0.1" {
		t.Fatalf("api call %q", c)
	}
}

func TestSSHGatewayListRefusalsAndNoExec(t *testing.T) {
	api := &fakeSSHAPI{}
	addr, key := startGateway(t, api)

	run := func(target string, k ssh.Signer, exec string) (string, error) {
		s, err := dialSSH(t, addr, target, k).NewSession()
		if err != nil {
			t.Fatal(err)
		}
		if exec != "" {
			out, err := s.CombinedOutput(exec)
			return string(out), err
		}
		out := &safeBuf{}
		s.Stdout, s.Stderr = out, out
		s.RequestPty("xterm", 24, 80, ssh.TerminalModes{})
		s.Shell()
		err = s.Wait()
		return out.String(), err
	}

	out, err := run("list", key, "")
	if err != nil || !strings.Contains(out, "web +acme") || !strings.Contains(out, "ann@example.com") {
		t.Fatalf("list: %v %q", err, out)
	}
	out, err = run("nope", key, "")
	if err == nil || !strings.Contains(out, "No running container called nope") {
		t.Fatalf("unknown: %v %q", err, out)
	}
	_, other, _ := ed25519.GenerateKey(rand.Reader)
	stranger, _ := ssh.NewSignerFromKey(other)
	out, err = run("web", stranger, "")
	if err == nil || !strings.Contains(out, "not registered") {
		t.Fatalf("unknown key: %v %q", err, out)
	}
	out, err = run("web", key, "cat /etc/passwd")
	if err == nil || !strings.Contains(out, "Only interactive shells") {
		t.Fatalf("exec: %v %q", err, out)
	}
}

func TestHostKeyIsKeptAcrossRestarts(t *testing.T) {
	p := filepath.Join(t.TempDir(), "host")
	a, err := LoadOrCreateHostKey(p)
	if err != nil {
		t.Fatal(err)
	}
	b, err := LoadOrCreateHostKey(p)
	if err != nil {
		t.Fatal(err)
	}
	if ssh.FingerprintSHA256(a.PublicKey()) != ssh.FingerprintSHA256(b.PublicKey()) {
		t.Fatal("host key changed")
	}
}

type safeBuf struct {
	mu sync.Mutex
	b  bytes.Buffer
}

func (s *safeBuf) Write(p []byte) (int, error) { s.mu.Lock(); defer s.mu.Unlock(); return s.b.Write(p) }
func (s *safeBuf) String() string              { s.mu.Lock(); defer s.mu.Unlock(); return s.b.String() }

type tlog struct{ t *testing.T }

func (l tlog) Printf(f string, a ...any) { l.t.Logf(f, a...) }
