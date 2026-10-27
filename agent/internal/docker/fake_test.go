package docker

import (
	"context"
	"encoding/binary"
	"encoding/json"
	"net"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"testing"
)

// unixServer serves h on a unix socket and returns a Client pointing at it.
func unixServer(t *testing.T, h http.Handler) *Client {
	t.Helper()
	sock := filepath.Join(t.TempDir(), "d.sock")
	l, err := net.Listen("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	srv := httptest.NewUnstartedServer(h)
	srv.Listener = l
	srv.Start()
	t.Cleanup(srv.Close)
	return New(sock)
}

func TestClientOverUnixSocket(t *testing.T) {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /version", func(w http.ResponseWriter, r *http.Request) {
		json.NewEncoder(w).Encode(map[string]string{"Version": "27.1.0"})
	})
	mux.HandleFunc("POST /containers/{id}/start", func(w http.ResponseWriter, r *http.Request) {
		if r.PathValue("id") == "missing" {
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"No such container: missing"}`))
			return
		}
		w.WriteHeader(204)
	})
	mux.HandleFunc("POST /images/create", func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-Registry-Auth") == "" {
			t.Error("missing auth header")
		}
		w.Write([]byte(`{"status":"Pulling"}` + "\n" + `{"error":"denied"}`))
	})
	c := unixServer(t, mux)
	ctx := context.Background()
	v, err := c.Version(ctx)
	if err != nil || v.Version != "27.1.0" {
		t.Fatal(v, err)
	}
	if err := c.StartContainer(ctx, "abc"); err != nil {
		t.Fatal(err)
	}
	if err := c.StartContainer(ctx, "missing"); !IsNotFound(err) {
		t.Fatal(err)
	}
	if err := c.PullImage(ctx, "nginx", "1", &RegistryAuth{Username: "u"}, nil); err == nil {
		t.Fatal("expected pull error")
	}
}

func TestDemuxAndCPU(t *testing.T) {
	frame := func(s byte, p string) []byte {
		h := make([]byte, 8)
		h[0] = s
		binary.BigEndian.PutUint32(h[4:], uint32(len(p)))
		return append(h, p...)
	}
	raw := append(frame(1, "out\n"), frame(2, "err\n")...)
	if got := string(Demux(raw)); got != "out\nerr\n" {
		t.Fatalf("%q", got)
	}
	var s StatsSample
	s.CPUStats.CPUUsage.TotalUsage = 200
	s.PreCPUStats.CPUUsage.TotalUsage = 100
	s.CPUStats.SystemUsage = 2000
	s.PreCPUStats.SystemUsage = 1000
	s.CPUStats.OnlineCPUs = 2
	if p := s.CPUPercent(); p != 20 {
		t.Fatal(p)
	}
}
