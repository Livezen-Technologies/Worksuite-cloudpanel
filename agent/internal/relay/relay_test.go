package relay

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/livezen-technologies/cloudpanel/agent/internal/ws"
)

type fakeAPI struct {
	mu        sync.Mutex
	expires   time.Time
	ended     bool
	closed    []string
	recording []byte
	connects  int
}

func (f *fakeAPI) VerifyAgent(_ context.Context, secret string, _ http.Header) (string, error) {
	switch secret {
	case "agent-secret":
		return "srv-1", nil
	case "other-secret":
		return "srv-2", nil
	}
	return "", &APIError{Code: 401, Message: "unknown agent"}
}

func (f *fakeAPI) Connect(_ context.Context, id, token string, cols, rows int, ip string) (*Session, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if token != "good" {
		return nil, &APIError{Code: 401, Message: "The terminal token is invalid or expired."}
	}
	f.connects++
	return &Session{SessionID: id, ServerID: "srv-1", ContainerID: "abc", ContainerName: "web", ShellUser: "app", Shell: "/bin/sh",
		ExpiresAt: f.expires, Recording: true, Command: json.RawMessage(`{"id":"cmd-1","type":"terminal.open"}`)}, nil
}

func (f *fakeAPI) Status(context.Context, string) (*Status, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return &Status{Ended: f.ended, ExpiresAt: f.expires}, nil
}

func (f *fakeAPI) Closed(_ context.Context, _ string, reason string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.closed = append(f.closed, reason)
	return nil
}

func (f *fakeAPI) UploadRecording(_ context.Context, _ string, b []byte) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.recording = b
	return nil
}

func (f *fakeAPI) closeReasons() []string {
	f.mu.Lock()
	defer f.mu.Unlock()
	return append([]string(nil), f.closed...)
}

func wsURL(srv *httptest.Server, path string) string {
	return "ws" + strings.TrimPrefix(srv.URL, "http") + path
}

func auth(secret string) *ws.DialOptions {
	return &ws.DialOptions{Header: http.Header{"Authorization": {"Bearer " + secret}}}
}

// fakeAgent connects the control channel and, for each command, dials back
// and echoes input upper-cased, like a shell would print output.
func fakeAgent(t *testing.T, srv *httptest.Server, secret string) {
	ctx := context.Background()
	ctl, err := ws.Dial(ctx, wsURL(srv, "/relay/agent"), auth(secret))
	if err != nil {
		t.Fatal(err)
	}
	go func() {
		defer ctl.CloseNow()
		for {
			_, data, err := ctl.ReadMessage()
			if err != nil {
				return
			}
			var m struct {
				Type    string `json:"type"`
				Command struct {
					ID string `json:"id"`
				} `json:"command"`
			}
			json.Unmarshal(data, &m)
			if m.Type != "command" {
				continue
			}
			s, err := ws.Dial(ctx, wsURL(srv, "/relay/agent/session/sess-1"), auth(secret))
			if err != nil {
				t.Error(err)
				return
			}
			go func() {
				s.WriteText(`{"type":"ready"}`)
				for {
					op, d, err := s.ReadMessage()
					if err != nil {
						return
					}
					if op == ws.OpBinary {
						if string(d) == "exit\n" {
							s.WriteText(`{"type":"exit","code":0}`)
							continue
						}
						s.WriteMessage(ws.OpBinary, bytes.ToUpper(d))
					}
				}
			}()
		}
	}()
}

func newRelay(t *testing.T, api *fakeAPI) (*Server, *httptest.Server) {
	s := &Server{API: api, PollInterval: 50 * time.Millisecond, AttachTimeout: 2 * time.Second}
	srv := httptest.NewServer(s.Handler())
	t.Cleanup(srv.Close)
	return s, srv
}

func waitFor(t *testing.T, cond func() bool) {
	t.Helper()
	for i := 0; i < 200; i++ {
		if cond() {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
	t.Fatal("condition not met")
}

// readUntil collects browser messages until one matches.
func readUntil(t *testing.T, c *ws.Conn, match func(op int, d []byte) bool) []string {
	t.Helper()
	var seen []string
	c.SetReadDeadline(time.Now().Add(5 * time.Second))
	for {
		op, d, err := c.ReadMessage()
		if err != nil {
			t.Fatalf("read: %v (seen %q)", err, seen)
		}
		seen = append(seen, string(d))
		if match(op, d) {
			return seen
		}
	}
}

func TestTerminalRoundTripRecordingAndExit(t *testing.T) {
	api := &fakeAPI{expires: time.Now().Add(time.Minute)}
	s, srv := newRelay(t, api)
	fakeAgent(t, srv, "agent-secret")
	waitFor(t, func() bool { return s.ConnectedAgents("srv-1") })

	b, err := ws.Dial(context.Background(), wsURL(srv, "/relay/terminal/sess-1?token=good&cols=100&rows=30"), nil)
	if err != nil {
		t.Fatal(err)
	}
	readUntil(t, b, func(op int, d []byte) bool { return strings.Contains(string(d), `"ready"`) })
	b.WriteText(`{"type":"resize","cols":120,"rows":40}`)
	b.WriteMessage(ws.OpBinary, []byte("echo hi\n"))
	readUntil(t, b, func(op int, d []byte) bool { return op == ws.OpBinary && string(d) == "ECHO HI\n" })
	b.WriteMessage(ws.OpBinary, []byte("exit\n"))
	seen := readUntil(t, b, func(op int, d []byte) bool { return strings.Contains(string(d), `"closed"`) })
	if !strings.Contains(strings.Join(seen, ""), `"exit"`) {
		t.Fatalf("no exit event: %q", seen)
	}
	waitFor(t, func() bool { return len(api.closeReasons()) == 1 })
	if r := api.closeReasons()[0]; r != "shell exited (0)" {
		t.Fatal(r)
	}
	api.mu.Lock()
	rec := string(api.recording)
	api.mu.Unlock()
	lines := strings.Split(strings.TrimSpace(rec), "\n")
	if !strings.Contains(lines[0], `"version":2`) || !strings.Contains(lines[0], `"width":100`) {
		t.Fatalf("header %s", lines[0])
	}
	if !strings.Contains(rec, `"o","ECHO HI\n"`) || !strings.Contains(rec, `"r","120x40"`) {
		t.Fatalf("recording %s", rec)
	}
}

func TestExpiryAndEndSessionClose(t *testing.T) {
	api := &fakeAPI{expires: time.Now().Add(400 * time.Millisecond)}
	s, srv := newRelay(t, api)
	fakeAgent(t, srv, "agent-secret")
	waitFor(t, func() bool { return s.ConnectedAgents("srv-1") })
	b, err := ws.Dial(context.Background(), wsURL(srv, "/relay/terminal/sess-1?token=good"), nil)
	if err != nil {
		t.Fatal(err)
	}
	seen := readUntil(t, b, func(op int, d []byte) bool { return strings.Contains(string(d), `"closed"`) })
	if !strings.Contains(seen[len(seen)-1], "expired") {
		t.Fatalf("%q", seen)
	}

	api.mu.Lock()
	api.expires = time.Now().Add(time.Minute)
	api.mu.Unlock()
	b2, err := ws.Dial(context.Background(), wsURL(srv, "/relay/terminal/sess-1?token=good"), nil)
	if err != nil {
		t.Fatal(err)
	}
	readUntil(t, b2, func(op int, d []byte) bool { return strings.Contains(string(d), `"ready"`) })
	api.mu.Lock()
	api.ended = true // "End session" in the UI
	api.mu.Unlock()
	seen = readUntil(t, b2, func(op int, d []byte) bool { return strings.Contains(string(d), `"closed"`) })
	if !strings.Contains(seen[len(seen)-1], `"ended"`) {
		t.Fatalf("%q", seen)
	}
}

func TestRefusals(t *testing.T) {
	api := &fakeAPI{expires: time.Now().Add(time.Minute)}
	_, srv := newRelay(t, api)
	ctx := context.Background()

	// Bad browser token: error message then close 4401.
	b, err := ws.Dial(ctx, wsURL(srv, "/relay/terminal/sess-1?token=bad"), nil)
	if err != nil {
		t.Fatal(err)
	}
	_, d, _ := b.ReadMessage()
	if !strings.Contains(string(d), "invalid or expired") {
		t.Fatal(string(d))
	}
	_, _, err = b.ReadMessage()
	var ce *ws.CloseError
	if !errors.As(err, &ce) || ce.Code != 4401 {
		t.Fatal(err)
	}

	// Unknown agent secret.
	var he *ws.HandshakeError
	if _, err := ws.Dial(ctx, wsURL(srv, "/relay/agent"), auth("nope")); !errors.As(err, &he) || he.Status != 401 {
		t.Fatal(err)
	}
	// Dial-back without a pending session, and by an agent of another server.
	if _, err := ws.Dial(ctx, wsURL(srv, "/relay/agent/session/sess-1"), auth("agent-secret")); !errors.As(err, &he) || he.Status != 404 {
		t.Fatal(err)
	}
	b3, _ := ws.Dial(ctx, wsURL(srv, "/relay/terminal/sess-1?token=good"), nil)
	readUntil(t, b3, func(op int, d []byte) bool { return strings.Contains(string(d), "heartbeat") })
	if _, err := ws.Dial(ctx, wsURL(srv, "/relay/agent/session/sess-1"), auth("other-secret")); !errors.As(err, &he) || he.Status != 404 {
		t.Fatalf("foreign agent attached: %v", err)
	}
	// Cross-origin browser connection.
	if _, err := ws.Dial(ctx, wsURL(srv, "/relay/terminal/sess-1?token=good"), &ws.DialOptions{Header: http.Header{"Origin": {"https://evil.example"}}}); !errors.As(err, &he) || he.Status != 403 {
		t.Fatal(err)
	}
}

func TestRecorderKeepsRunesAcrossChunks(t *testing.T) {
	r := NewRecorder(80, 24, "t", time.Now(), 0)
	euro := []byte("€") // 3 bytes
	r.Output(euro[:1])
	r.Output(euro[1:])
	if !strings.Contains(string(r.Bytes()), `"o","€"`) {
		t.Fatal(string(r.Bytes()))
	}
	small := NewRecorder(80, 24, "t", time.Now(), 200)
	for i := 0; i < 50; i++ {
		small.Output([]byte("0123456789"))
	}
	if out := string(small.Bytes()); !strings.Contains(out, "truncated") || len(out) > 400 {
		t.Fatal(out)
	}
}
