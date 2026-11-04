package executor

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/livezen-technologies/cloudpanel/agent/internal/docker"
	"github.com/livezen-technologies/cloudpanel/agent/internal/ws"
)

func TestTerminalPayloadValidation(t *testing.T) {
	now := time.Date(2026, 10, 1, 12, 0, 0, 0, time.UTC)
	ok := terminalPayload{SessionID: "s1", ContainerID: "abc", ShellUser: "app", Shell: "/bin/sh", ExpiresAt: now.Add(10 * time.Minute).Format(time.RFC3339)}
	if _, err := ok.validate(nil, now); err != nil {
		t.Fatal(err)
	}
	for name, mut := range map[string]func(p *terminalPayload){
		"root":        func(p *terminalPayload) { p.ShellUser = "root" },
		"uid0":        func(p *terminalPayload) { p.ShellUser = "0:0" },
		"empty user":  func(p *terminalPayload) { p.ShellUser = "" },
		"bash -c":     func(p *terminalPayload) { p.Shell = "/bin/sh -c id" },
		"python":      func(p *terminalPayload) { p.Shell = "/usr/bin/python3" },
		"expired":     func(p *terminalPayload) { p.ExpiresAt = now.Add(-time.Second).Format(time.RFC3339) },
		"too long":    func(p *terminalPayload) { p.ExpiresAt = now.Add(5 * time.Hour).Format(time.RFC3339) },
		"bad session": func(p *terminalPayload) { p.SessionID = "../x" },
	} {
		p := ok
		mut(&p)
		if _, err := p.validate(nil, now); err == nil {
			t.Errorf("%s accepted", name)
		}
	}
	if _, err := ok.validate([]string{"/bin/bash"}, now); err == nil {
		t.Error("shell outside configured list accepted")
	}
	if u, _ := relaySessionURL("https://panel.example/relay/", "s1"); u != "wss://panel.example/relay/agent/session/s1" {
		t.Error(u)
	}
	if _, err := relaySessionURL("file:///etc", "s1"); err == nil {
		t.Error("file url accepted")
	}
}

// fakeDockerExec emulates exec create/start (hijacked, raw TTY stream)/resize/inspect.
type fakeDockerExec struct {
	mu      sync.Mutex
	created map[string]any
	resized []string
	probes  []string       // users the agent tried before the shell
	exitFor map[string]int // probe exit code per user (default 0)
	cfgUser string         // the container's own Config.User
	lastBy  map[string]string
}

func (f *fakeDockerExec) handler(t *testing.T) http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("POST /containers/{id}/exec", func(w http.ResponseWriter, r *http.Request) {
		var body map[string]any
		json.NewDecoder(r.Body).Decode(&body)
		f.mu.Lock()
		defer f.mu.Unlock()
		if f.lastBy == nil {
			f.lastBy = map[string]string{}
		}
		if body["Tty"] != true { // a user probe
			u, _ := body["User"].(string)
			f.probes = append(f.probes, u)
			id := fmt.Sprintf("probe%d", len(f.probes))
			f.lastBy[id] = u
			w.WriteHeader(201)
			io.WriteString(w, `{"Id":"`+id+`"}`)
			return
		}
		f.created = body
		f.created["container"] = r.PathValue("id")
		w.WriteHeader(201)
		io.WriteString(w, `{"Id":"exec1"}`)
	})
	mux.HandleFunc("GET /containers/{id}/json", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		io.WriteString(w, `{"Id":"abc123","Config":{"User":"`+f.cfgUser+`"}}`)
	})
	mux.HandleFunc("POST /exec/{id}/start", func(w http.ResponseWriter, r *http.Request) {
		if strings.HasPrefix(r.PathValue("id"), "probe") {
			io.Copy(io.Discard, r.Body)
			w.WriteHeader(200)
			return
		}
		if r.Header.Get("Upgrade") != "tcp" {
			t.Error("exec start without Upgrade: tcp")
		}
		io.Copy(io.Discard, r.Body)
		nc, brw, _ := w.(http.Hijacker).Hijack()
		io.WriteString(nc, "HTTP/1.1 101 UPGRADED\r\nContent-Type: application/vnd.docker.raw-stream\r\nConnection: Upgrade\r\nUpgrade: tcp\r\n\r\n")
		go func() {
			defer nc.Close()
			io.WriteString(nc, "$ ")
			sc := bufio.NewScanner(brw)
			for sc.Scan() {
				if sc.Text() == "exit" {
					return
				}
				io.WriteString(nc, "out:"+sc.Text()+"\r\n$ ")
			}
		}()
	})
	mux.HandleFunc("POST /exec/{id}/resize", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		f.resized = append(f.resized, r.URL.Query().Get("w")+"x"+r.URL.Query().Get("h"))
		f.mu.Unlock()
		w.WriteHeader(201)
	})
	mux.HandleFunc("GET /exec/{id}/json", func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		defer f.mu.Unlock()
		code := f.exitFor[f.lastBy[r.PathValue("id")]]
		fmt.Fprintf(w, `{"Running":false,"ExitCode":%d}`, code)
	})
	return mux
}

func TestOpenTerminalDialsRelayAndBridgesExec(t *testing.T) {
	fd := &fakeDockerExec{}
	sock := filepath.Join(t.TempDir(), "d.sock")
	l, err := net.Listen("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	dsrv := httptest.NewUnstartedServer(fd.handler(t))
	dsrv.Listener = l
	dsrv.Start()
	defer dsrv.Close()

	// Relay stand-in: accepts the agent's dial-back and drives the session.
	got := make(chan string, 1)
	relay := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/relay/agent/session/sess-1" || r.Header.Get("Authorization") != "Bearer s3cret" {
			http.Error(w, "bad", 401)
			return
		}
		c, err := ws.Accept(w, r, nil)
		if err != nil {
			return
		}
		defer c.Close(ws.CloseNormal, "")
		var out bytes.Buffer
		c.SetReadDeadline(time.Now().Add(5 * time.Second))
		for {
			op, d, err := c.ReadMessage()
			if err != nil {
				got <- out.String()
				return
			}
			if op == ws.OpText {
				out.WriteString("[" + string(d) + "]")
				if strings.Contains(string(d), "ready") {
					c.WriteText(`{"type":"resize","cols":132,"rows":43}`)
					c.WriteMessage(ws.OpBinary, []byte("id\n"))
				}
				if strings.Contains(string(d), "exit") {
					got <- out.String()
					return
				}
				continue
			}
			out.Write(d)
			if strings.Contains(out.String(), "out:id") {
				c.WriteMessage(ws.OpBinary, []byte("exit\n"))
			}
		}
	}))
	defer relay.Close()

	e := &Executor{Docker: docker.New(sock), Terminal: &TerminalConfig{Secret: "s3cret"}, Log: log.New(os.Stderr, "", 0)}
	payload, _ := json.Marshal(map[string]any{"session_id": "sess-1", "container_id": "abc123", "shell_user": "app", "shell": "/bin/sh",
		"expires_at": time.Now().Add(time.Minute).UTC().Format(time.RFC3339), "relay_url": relay.URL + "/relay", "cols": 100, "rows": 30})
	if _, err := e.openTerminal(context.Background(), payload); err != nil {
		t.Fatal(err)
	}
	var transcript string
	select {
	case transcript = <-got:
	case <-time.After(5 * time.Second):
		t.Fatal("no session")
	}
	if !strings.Contains(transcript, "out:id") || !strings.Contains(transcript, `"exit"`) {
		t.Fatalf("transcript %q", transcript)
	}
	fd.mu.Lock()
	defer fd.mu.Unlock()
	if fd.created["User"] != "app" || fd.created["Tty"] != true || fd.created["container"] != "abc123" {
		t.Fatalf("exec create %v", fd.created)
	}
	if cmd, _ := json.Marshal(fd.created["Cmd"]); string(cmd) != `["/bin/sh"]` {
		t.Fatalf("cmd %s", cmd)
	}
	if len(fd.resized) == 0 || fd.resized[0] != "132x43" {
		t.Fatalf("resize %v", fd.resized)
	}

	// Disabled terminal config refuses.
	if _, err := (&Executor{}).openTerminal(context.Background(), payload); err == nil {
		t.Fatal("terminal opened without config")
	}
}

func TestTerminalFallsBackToANonRootUserThatExists(t *testing.T) {
	cases := []struct {
		name, cfgUser, want string
		exit                map[string]int
	}{
		{"configured user exists", "", "app", nil},
		{"container's own user", "1000:1000", "1000", map[string]int{"app": 126}},
		{"root falls back", "root", "www-data", map[string]int{"app": 126}},
		{"none work", "", "app", map[string]int{"app": 126, "www-data": 126, "node": 126, "nobody": 126}},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			fd := &fakeDockerExec{exitFor: c.exit, cfgUser: c.cfgUser}
			sock := filepath.Join(t.TempDir(), "d.sock")
			l, err := net.Listen("unix", sock)
			if err != nil {
				t.Fatal(err)
			}
			dsrv := httptest.NewUnstartedServer(fd.handler(t))
			dsrv.Listener = l
			dsrv.Start()
			defer dsrv.Close()
			e := &Executor{Docker: docker.New(sock), Log: log.New(io.Discard, "", 0)}
			got, changed := e.terminalUser(context.Background(), "abc123", "app", "/bin/sh")
			if got != c.want || changed != (c.want != "app") {
				t.Fatalf("got %q changed=%v, want %q (probes %v)", got, changed, c.want, fd.probes)
			}
			for _, p := range fd.probes {
				if IsRootUser(p) {
					t.Fatalf("probed root: %v", fd.probes)
				}
			}
		})
	}
}
