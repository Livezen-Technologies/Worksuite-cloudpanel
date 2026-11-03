package executor

import (
	"context"
	"encoding/json"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/livezen-technologies/cloudpanel/agent/internal/docker"
)

const testSwarmToken = "SWMTKN-1-" + "abcdefghijklmnopqrstuvwxyz0123456789abcdefghijklmn" + "-" + "abcdefghijklmnopqrstuvwxy"

type fakeSwarm struct {
	mu    sync.Mutex
	calls []string
	last  map[string]any
}

func swarmSetup(t *testing.T) (*Executor, *fakeSwarm) {
	e, _, _, dir := setup(t)
	fs := &fakeSwarm{}
	sock := filepath.Join(dir, "swarm.sock")
	l, err := net.Listen("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	srv := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		b, _ := io.ReadAll(r.Body)
		fs.mu.Lock()
		fs.calls = append(fs.calls, r.Method+" "+r.URL.Path+"?"+r.URL.RawQuery)
		fs.last = nil
		json.Unmarshal(b, &fs.last)
		fs.mu.Unlock()
		switch {
		case r.URL.Path == "/swarm/init":
			w.Write([]byte(`"node0abcdef1234"`))
		case r.URL.Path == "/swarm":
			w.Write([]byte(`{"ID":"swarm1","JoinTokens":{"Worker":"` + testSwarmToken + `","Manager":"SWMTKN-1-mgr"}}`))
		case r.URL.Path == "/nodes":
			w.Write([]byte(`[{"ID":"node0abcdef1234","Description":{"Hostname":"m1"},"Spec":{"Role":"manager","Availability":"active"},"Status":{"State":"ready","Addr":"10.0.0.1"},"ManagerStatus":{"Leader":true}},{"ID":"node1abcdef1234","Description":{"Hostname":"w1"},"Spec":{"Role":"worker","Availability":"active","Labels":{"zone":"a"}},"Status":{"State":"ready","Addr":"10.0.0.2"}}]`))
		case r.URL.Path == "/services":
			w.Write([]byte(`[{"ID":"svc1abcdef1234","Spec":{"Name":"web","TaskTemplate":{"ContainerSpec":{"Image":"nginx:1"}},"Mode":{"Replicated":{"Replicas":3}}}}]`))
		case r.URL.Path == "/tasks":
			w.Write([]byte(`[{"NodeID":"node1abcdef1234","ServiceID":"svc1abcdef1234","Status":{"State":"running"}},{"NodeID":"node1abcdef1234","ServiceID":"svc1abcdef1234","Status":{"State":"running"}},{"NodeID":"node0abcdef1234","ServiceID":"svc1abcdef1234","Status":{"State":"pending"}}]`))
		case r.URL.Path == "/nodes/node1abcdef1234" && r.Method == "GET":
			w.Write([]byte(`{"ID":"node1abcdef1234","Version":{"Index":42},"Spec":{"Role":"worker","Availability":"active"}}`))
		case r.URL.Path == "/services/svc1abcdef1234" && r.Method == "GET":
			w.Write([]byte(`{"ID":"svc1abcdef1234","Version":{"Index":7},"Spec":{"Name":"web","Mode":{"Replicated":{"Replicas":3}}}}`))
		default:
			w.WriteHeader(200)
		}
	}))
	srv.Listener = l
	srv.Start()
	t.Cleanup(srv.Close)
	e.Docker = docker.New(sock)
	return e, fs
}

func (f *fakeSwarm) called(prefix string) bool {
	f.mu.Lock()
	defer f.mu.Unlock()
	for _, c := range f.calls {
		if strings.HasPrefix(c, prefix) {
			return true
		}
	}
	return false
}

func TestSwarmInitJoinInspect(t *testing.T) {
	e, fs := swarmSetup(t)
	ts := now.Add(-time.Minute)
	r, err := e.Handle(context.Background(), cmd("s1", "swarm.init", `{"advertise_addr":"10.0.0.1"}`, ts))
	if err != nil || r.Status != "succeeded" {
		t.Fatalf("init: %v %+v", err, r)
	}
	out := r.Output.(map[string]any)
	if out["worker_token"] != testSwarmToken || out["node_id"] != "node0abcdef1234" {
		t.Fatalf("init output %v", out)
	}

	r, _ = e.Handle(context.Background(), cmd("s2", "swarm.join", `{"advertise_addr":"10.0.0.2","manager":"10.0.0.1:2377","token":"`+testSwarmToken+`"}`, ts))
	if r.Status != "succeeded" || fs.last["RemoteAddrs"].([]any)[0] != "10.0.0.1:2377" {
		t.Fatalf("join: %+v %v", r, fs.last)
	}

	r, _ = e.Handle(context.Background(), cmd("s3", "swarm.inspect", `{}`, ts))
	got, _ := json.Marshal(r.Output)
	if !strings.Contains(string(got), `"hostname":"w1"`) || !strings.Contains(string(got), `"tasks":2`) || !strings.Contains(string(got), `"running":2`) || !strings.Contains(string(got), `"replicas":3`) {
		t.Fatalf("inspect: %s", got)
	}
}

func TestSwarmDrainAndScaleSendTheCurrentVersion(t *testing.T) {
	e, fs := swarmSetup(t)
	ts := now.Add(-time.Minute)
	r, _ := e.Handle(context.Background(), cmd("d1", "swarm.node_update", `{"node_id":"node1abcdef1234","availability":"drain"}`, ts))
	if r.Status != "succeeded" || !fs.called("POST /nodes/node1abcdef1234/update?version=42") || fs.last["Availability"] != "drain" {
		t.Fatalf("drain: %+v %v %v", r, fs.calls, fs.last)
	}
	r, _ = e.Handle(context.Background(), cmd("d2", "swarm.service_scale", `{"service_id":"svc1abcdef1234","replicas":5}`, ts))
	if r.Status != "succeeded" || !fs.called("POST /services/svc1abcdef1234/update?version=7") {
		t.Fatalf("scale: %+v %v", r, fs.calls)
	}
	if rep := fs.last["Mode"].(map[string]any)["Replicated"].(map[string]any)["Replicas"]; rep != float64(5) {
		t.Fatalf("replicas sent %v", rep)
	}
}

func TestSwarmRejectsBadInput(t *testing.T) {
	e, _ := swarmSetup(t)
	ts := now.Add(-time.Minute)
	for i, p := range []struct{ typ, body string }{
		{"swarm.init", `{"advertise_addr":"not-an-ip"}`},
		{"swarm.join", `{"advertise_addr":"10.0.0.2","manager":"10.0.0.1","token":"` + testSwarmToken + `"}`},
		{"swarm.join", `{"advertise_addr":"10.0.0.2","manager":"10.0.0.1:2377","token":"SWMTKN-1-bad;rm"}`},
		{"swarm.node_update", `{"node_id":"../etc","availability":"drain"}`},
		{"swarm.node_update", `{"node_id":"node1abcdef1234","availability":"gone"}`},
		{"swarm.service_scale", `{"service_id":"svc1abcdef1234","replicas":1000}`},
	} {
		r, _ := e.Handle(context.Background(), cmd("bad"+string(rune('a'+i)), p.typ, p.body, ts))
		if r.Status == "succeeded" {
			t.Errorf("case %d (%s) accepted", i, p.typ)
		}
	}
}
