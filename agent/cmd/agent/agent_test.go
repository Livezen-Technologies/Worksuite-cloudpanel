package main

import (
	"context"
	"encoding/json"
	"io"
	"log"
	"net"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/livezen-technologies/cloudpanel/agent/internal/config"
	"github.com/livezen-technologies/cloudpanel/agent/internal/protocol"
	"github.com/livezen-technologies/cloudpanel/agent/internal/signing"
)

func TestBackoff(t *testing.T) {
	for n := 1; n < 40; n++ {
		d := backoff(n)
		if d <= 0 || d > maxBackoff {
			t.Fatalf("backoff(%d)=%s", n, d)
		}
	}
	if backoff(1) > time.Second || backoff(30) < 30*time.Second {
		t.Fatal("bad growth")
	}
}

func fakeDockerSock(t *testing.T, calls *[]string, mu *sync.Mutex) string {
	sock := filepath.Join(t.TempDir(), "d.sock")
	l, err := net.Listen("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	srv := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		*calls = append(*calls, r.Method+" "+r.URL.Path)
		mu.Unlock()
		switch r.URL.Path {
		case "/containers/json", "/images/json", "/networks":
			w.Write([]byte(`[]`))
		case "/volumes":
			w.Write([]byte(`{"Volumes":[]}`))
		default:
			w.WriteHeader(204)
		}
	}))
	srv.Listener = l
	srv.Start()
	t.Cleanup(srv.Close)
	return sock
}

// End to end: fake panel (with outage) + fake Docker.
func TestRunLoop(t *testing.T) {
	var mu sync.Mutex
	var dcalls []string
	sock := fakeDockerSock(t, &dcalls, &mu)

	var beats int
	results := map[string][]string{}
	panel := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer sec" {
			w.WriteHeader(401)
			return
		}
		mu.Lock()
		defer mu.Unlock()
		if strings.HasSuffix(r.URL.Path, "/result") {
			var res protocol.Result
			json.NewDecoder(r.Body).Decode(&res)
			id := strings.Split(r.URL.Path, "/")[4]
			results[id] = append(results[id], res.Status)
			return
		}
		beats++
		var hb protocol.HeartbeatRequest
		b, _ := io.ReadAll(r.Body)
		json.Unmarshal(b, &hb)
		if hb.Containers == nil || hb.AgentVersion == "" {
			t.Errorf("bad heartbeat %s", b)
		}
		if beats == 1 {
			w.WriteHeader(503) // outage
			return
		}
		ts := time.Now().UTC().Format(time.RFC3339)
		sig, _ := signing.Sign([]byte("hk"), "op1", "container.restart", []byte(`{"container_id":"web"}`), ts)
		json.NewEncoder(w).Encode(protocol.HeartbeatResponse{Commands: []protocol.Command{
			{ID: "op1", Type: "container.restart", Version: 1, Payload: json.RawMessage(`{"container_id":"web"}`), IssuedAt: ts, Signature: sig},
		}})
	}))
	defer panel.Close()

	cfg := &config.Config{PanelURL: panel.URL, AgentSecret: "sec", HMACKey: "hk", DockerSocket: sock, StateDir: t.TempDir(), HeartbeatInterval: 15}
	cfg.ApplyDefaults()
	a, err := newAgent(cfg, filepath.Join(t.TempDir(), "agent"), log.New(io.Discard, "", 0))
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	a.sleep = func(c context.Context, d time.Duration) { sleepCtx(c, 10*time.Millisecond) }
	go func() {
		deadline := time.Now().Add(5 * time.Second)
		for time.Now().Before(deadline) {
			mu.Lock()
			n, b := len(results["op1"]), beats
			mu.Unlock()
			if n >= 2 && b >= 4 {
				break
			}
			time.Sleep(10 * time.Millisecond)
		}
		cancel()
	}()
	a.Run(ctx)
	mu.Lock()
	defer mu.Unlock()
	if strings.Join(results["op1"], ",") != "running,succeeded" {
		t.Fatalf("results %v (op1 must run exactly once despite redelivery)", results)
	}
	restarts := 0
	for _, c := range dcalls {
		if strings.HasPrefix(c, "POST") {
			if c != "POST /containers/web/restart" {
				t.Fatalf("unexpected docker mutation %s", c)
			}
			restarts++
		}
	}
	if restarts != 1 {
		t.Fatalf("restarts=%d calls=%v", restarts, dcalls)
	}
}

// Executor.Run is a func field, and the exported e.Run(...) call sites in
// crowdsec, firewall, egress, cron, antivirus, realtime, wordpress, mailimport
// and sysservices call it directly instead of going through the e.run fallback.
// Left nil it is a nil func value, so the first such command segfaults the agent.
func TestNewAgentSetsTheCommandRunner(t *testing.T) {
	a, err := newAgent(&config.Config{}, "/usr/local/bin/dockermgr-agent", log.New(io.Discard, "", 0))
	if err != nil {
		t.Fatalf("newAgent: %v", err)
	}
	if a.exec.Run == nil {
		t.Fatal("Executor.Run is nil; every direct e.Run(...) call site panics with a nil pointer dereference")
	}
}
