package relay

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/binary"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"

	"golang.org/x/crypto/ssh"

	"github.com/livezen-technologies/cloudpanel/agent/internal/ws"
)

// SSHGateway lets people open container shells with PuTTY or any SSH client:
//
//	ssh -p 2222 shop-web@panel.example.com
//
// The SSH user name is the container (or app) name; the key must be one the person
// added under Account → SSH keys. Each login becomes an ordinary terminal session
// (same permissions, approvals, non-root user, time limit, audit and recording),
// bridged through the relay's own browser handler over an in-process connection.
type SSHGateway struct {
	API     SSHAPI
	HostKey ssh.Signer
	Relay   http.Handler // the relay's Handler(), reached in-process
	Log     interface{ Printf(string, ...any) }

	once     sync.Once
	internal *memListener
}

type ctxKey struct{}

// isInternal reports a request made by the SSH gateway over its in-process listener.
func isInternal(r *http.Request) bool {
	v, _ := r.Context().Value(ctxKey{}).(bool)
	return v
}

func (g *SSHGateway) logf(f string, a ...any) {
	if g.Log != nil {
		g.Log.Printf("ssh "+f, a...)
	}
}

func (g *SSHGateway) start() {
	g.once.Do(func() {
		g.internal = newMemListener()
		hs := &http.Server{Handler: g.Relay, ReadHeaderTimeout: 15 * time.Second,
			ConnContext: func(ctx context.Context, _ net.Conn) context.Context { return context.WithValue(ctx, ctxKey{}, true) }}
		go hs.Serve(g.internal)
	})
}

// Serve accepts SSH connections until the listener closes.
func (g *SSHGateway) Serve(l net.Listener) error {
	g.start()
	cfg := &ssh.ServerConfig{
		PublicKeyCallback: func(meta ssh.ConnMetadata, key ssh.PublicKey) (*ssh.Permissions, error) {
			// The key is checked against the API when the shell starts; here only its
			// fingerprint is kept. Passwords and keyboard-interactive are not offered.
			return &ssh.Permissions{Extensions: map[string]string{"fp": ssh.FingerprintSHA256(key)}}, nil
		},
		ServerVersion: "SSH-2.0-WorkSuite-Gateway",
		MaxAuthTries:  6,
	}
	cfg.AddHostKey(g.HostKey)
	for {
		nc, err := l.Accept()
		if err != nil {
			return err
		}
		go g.handle(nc, cfg)
	}
}

func (g *SSHGateway) handle(nc net.Conn, cfg *ssh.ServerConfig) {
	defer nc.Close()
	nc.SetDeadline(time.Now().Add(30 * time.Second))
	conn, chans, reqs, err := ssh.NewServerConn(nc, cfg)
	if err != nil {
		return
	}
	nc.SetDeadline(time.Time{})
	defer conn.Close()
	go ssh.DiscardRequests(reqs)
	ip, _, _ := net.SplitHostPort(nc.RemoteAddr().String())
	for nch := range chans {
		if nch.ChannelType() != "session" {
			nch.Reject(ssh.UnknownChannelType, "only shell sessions are supported")
			continue
		}
		ch, creqs, err := nch.Accept()
		if err != nil {
			continue
		}
		go g.session(conn.User(), conn.Permissions.Extensions["fp"], ip, ch, creqs)
	}
}

type winSize struct{ cols, rows int }

func parseDims(b []byte) (winSize, bool) {
	if len(b) < 8 {
		return winSize{}, false
	}
	c, r := int(binary.BigEndian.Uint32(b)), int(binary.BigEndian.Uint32(b[4:]))
	if c <= 0 || r <= 0 || c > 1000 || r > 1000 {
		return winSize{}, false
	}
	return winSize{c, r}, true
}

// pty-req: string term, uint32 cols, uint32 rows, …
func parsePty(b []byte) (winSize, bool) {
	if len(b) < 4 {
		return winSize{}, false
	}
	n := int(binary.BigEndian.Uint32(b))
	if len(b) < 4+n+8 {
		return winSize{}, false
	}
	return parseDims(b[4+n:])
}

func exitStatus(ch ssh.Channel, code int) {
	b := make([]byte, 4)
	binary.BigEndian.PutUint32(b, uint32(code))
	ch.SendRequest("exit-status", false, b)
}

func (g *SSHGateway) session(target, fp, ip string, ch ssh.Channel, reqs <-chan *ssh.Request) {
	defer ch.Close()
	size := winSize{80, 24}
	resize := make(chan winSize, 4)
	started := make(chan struct{})
	var once sync.Once
	refused := false
	go func() {
		for r := range reqs {
			switch r.Type {
			case "pty-req":
				if s, ok := parsePty(r.Payload); ok {
					size = s
				}
				r.Reply(true, nil)
			case "window-change":
				if s, ok := parseDims(r.Payload); ok {
					select {
					case resize <- s:
					default:
					}
				}
			case "shell":
				r.Reply(true, nil)
				once.Do(func() { close(started) })
			case "env":
				r.Reply(true, nil) // accepted and ignored
			case "exec", "subsystem":
				// Accepted so the client reads the explanation, then refused with exit 1.
				r.Reply(true, nil)
				refused = true
				once.Do(func() { close(started) })
			default: // x11, agent and port forwarding
				r.Reply(false, nil)
			}
		}
	}()
	select {
	case <-started:
	case <-time.After(60 * time.Second):
		return
	}
	if refused {
		fmt.Fprint(ch.Stderr(), "Only interactive shells are available here (no exec, scp or sftp).\r\n")
		exitStatus(ch, 1)
		return
	}

	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	t, err := g.API.SSHSession(ctx, fp, target, ip)
	cancel()
	if err != nil {
		msg := "The gateway could not reach the panel. Try again shortly."
		var ae *APIError
		if errors.As(err, &ae) {
			msg = ae.Message
		}
		fmt.Fprintf(ch.Stderr(), "%s\r\n", msg)
		exitStatus(ch, 1)
		return
	}
	if t.SessionID == "" { // "list"
		fmt.Fprintf(ch, "Signed in as %s. Containers you can open:\r\n", t.User)
		for _, n := range t.Targets {
			fmt.Fprintf(ch, "  %s\r\n", n)
		}
		if len(t.Targets) == 0 {
			fmt.Fprint(ch, "  (none)\r\n")
		}
		fmt.Fprint(ch, "Connect with: ssh -p <port> <name>@<panel host>  (add +org when a name is in several organisations)\r\n")
		exitStatus(ch, 0)
		return
	}
	g.logf("%s → %s (session %s) from %s", t.User, t.Container, t.SessionID, ip)
	g.bridge(t, ip, size, resize, ch)
}

// bridge connects to the relay's browser endpoint in-process and copies both ways.
func (g *SSHGateway) bridge(t *SSHTarget, ip string, size winSize, resize <-chan winSize, ch ssh.Channel) {
	q := url.Values{"token": {t.Token}, "cols": {strconv.Itoa(size.cols)}, "rows": {strconv.Itoa(size.rows)}}
	u := "ws://relay.internal/relay/terminal/" + url.PathEscape(t.SessionID) + "?" + q.Encode()
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	conn, err := ws.Dial(ctx, u, &ws.DialOptions{Header: http.Header{"X-Forwarded-For": {ip}}, NetDial: func(context.Context, string, string) (net.Conn, error) { return g.internal.Dial() }})
	cancel()
	if err != nil {
		g.logf("session %s: internal dial: %v", t.SessionID, err)
		fmt.Fprint(ch.Stderr(), "The terminal could not be opened.\r\n")
		exitStatus(ch, 1)
		return
	}
	defer conn.Close(ws.CloseNormal, "")
	var wmu sync.Mutex
	write := func(op int, b []byte) error { wmu.Lock(); defer wmu.Unlock(); return conn.WriteMessage(op, b) }

	go func() { // keyboard → container
		buf := make([]byte, 32<<10)
		for {
			n, err := ch.Read(buf)
			if n > 0 && write(ws.OpBinary, buf[:n]) != nil {
				return
			}
			if err != nil {
				b, _ := json.Marshal(control{Type: "close"})
				write(ws.OpText, b)
				return
			}
		}
	}()
	go func() {
		for s := range resize {
			b, _ := json.Marshal(control{Type: "resize", Cols: s.cols, Rows: s.rows})
			if write(ws.OpText, b) != nil {
				return
			}
		}
	}()

	code := 0
	for { // container → screen
		op, data, err := conn.ReadMessage()
		if err != nil {
			break
		}
		if op == ws.OpBinary {
			if _, err := ch.Write(data); err != nil {
				break
			}
			continue
		}
		var c control
		if json.Unmarshal(data, &c) != nil {
			continue
		}
		switch c.Type {
		case "ready":
			fmt.Fprintf(ch.Stderr(), "[Connected as %s · recorded · ends after the session time limit]\r\n", c.Message)
		case "status":
			if c.Message != "" {
				fmt.Fprintf(ch.Stderr(), "[%s]\r\n", strings.TrimSpace(c.Message))
			}
		case "error":
			fmt.Fprintf(ch.Stderr(), "%s\r\n", c.Message)
			code = 1
		case "exit":
			if c.Code != nil {
				code = *c.Code
			}
		case "closed":
			exitStatus(ch, code)
			return
		}
	}
	exitStatus(ch, code)
}

// memListener is an in-process net.Listener: the gateway's connections to the relay
// handler never touch the network, so no origin, proxy or port is involved.
type memListener struct {
	conns  chan net.Conn
	closed chan struct{}
	once   sync.Once
}

func newMemListener() *memListener {
	return &memListener{conns: make(chan net.Conn), closed: make(chan struct{})}
}

func (l *memListener) Dial() (net.Conn, error) {
	a, b := net.Pipe()
	select {
	case l.conns <- b:
		return a, nil
	case <-l.closed:
		return nil, io.ErrClosedPipe
	}
}

func (l *memListener) Accept() (net.Conn, error) {
	select {
	case c := <-l.conns:
		return c, nil
	case <-l.closed:
		return nil, net.ErrClosed
	}
}

func (l *memListener) Close() error { l.once.Do(func() { close(l.closed) }); return nil }

type memAddr struct{}

func (memAddr) Network() string { return "mem" }
func (memAddr) String() string  { return "relay.internal" }

func (l *memListener) Addr() net.Addr { return memAddr{} }

// LoadOrCreateHostKey reads the gateway's host key, creating an Ed25519 key (0600)
// on first start so clients see the same fingerprint across restarts.
func LoadOrCreateHostKey(path string) (ssh.Signer, error) {
	if b, err := os.ReadFile(path); err == nil {
		return ssh.ParsePrivateKey(b)
	} else if !errors.Is(err, os.ErrNotExist) {
		return nil, err
	}
	_, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		return nil, err
	}
	blk, err := ssh.MarshalPrivateKey(priv, "dockermgr-relay ssh gateway")
	if err != nil {
		return nil, err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return nil, err
	}
	if err := os.WriteFile(path, pem.EncodeToMemory(blk), 0o600); err != nil {
		return nil, err
	}
	return ssh.NewSignerFromKey(priv)
}
