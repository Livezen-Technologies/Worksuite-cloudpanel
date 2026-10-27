package stats

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"

	"github.com/livezen-technologies/cloudpanel/agent/internal/docker"
)

func TestSample(t *testing.T) {
	d := t.TempDir()
	os.MkdirAll(filepath.Join(d, "net"), 0o755)
	os.WriteFile(filepath.Join(d, "meminfo"), []byte("MemTotal: 1000 kB\nMemAvailable: 250 kB\n"), 0o644)
	os.WriteFile(filepath.Join(d, "net/dev"), []byte("Inter-|\n face |\n    lo: 99 0 0 0 0 0 0 0 99 0 0 0 0 0 0 0\n  eth0: 100 0 0 0 0 0 0 0 200 0 0 0 0 0 0 0\n"), 0o644)
	os.WriteFile(filepath.Join(d, "stat"), []byte("cpu  100 0 100 800 0 0 0 0 0 0\n"), 0o644)
	s := &Sampler{ProcRoot: d, DiskPath: d}
	st := s.Sample()
	if st.RAMPct != 75 || st.NetRx != 100 || st.NetTx != 200 || st.CPUPct != 20 {
		t.Fatalf("%+v", st)
	}
	os.WriteFile(filepath.Join(d, "stat"), []byte("cpu  150 0 150 900 0 0 0 0 0 0\n"), 0o644)
	if st := s.Sample(); st.CPUPct != 50 {
		t.Fatalf("delta cpu %v", st.CPUPct)
	}
	if st.DiskPct <= 0 {
		t.Fatalf("disk %v", st.DiskPct)
	}
}

func TestInventory(t *testing.T) {
	mux := http.NewServeMux()
	j := func(v any) http.HandlerFunc {
		return func(w http.ResponseWriter, r *http.Request) { json.NewEncoder(w).Encode(v) }
	}
	mux.Handle("GET /containers/json", j([]map[string]any{{"Id": "c1", "Names": []string{"/web"}, "Image": "nginx", "State": "running", "Created": 1700000000, "Ports": []map[string]any{{"IP": "0.0.0.0", "PrivatePort": 80, "PublicPort": 8080, "Type": "tcp"}}}}))
	mux.Handle("GET /containers/c1/json", j(map[string]any{"Id": "c1", "State": map[string]any{"Health": map[string]any{"Status": "healthy"}}}))
	mux.Handle("GET /containers/c1/stats", j(map[string]any{
		"cpu_stats":    map[string]any{"cpu_usage": map[string]any{"total_usage": 300}, "system_cpu_usage": 2000, "online_cpus": 1},
		"precpu_stats": map[string]any{"cpu_usage": map[string]any{"total_usage": 100}, "system_cpu_usage": 1000},
		"memory_stats": map[string]any{"usage": 3 << 20, "stats": map[string]any{"inactive_file": 1 << 20}},
	}))
	mux.Handle("GET /images/json", j([]map[string]any{{"Id": "sha256:1", "RepoTags": []string{"nginx:1"}, "Size": 1 << 20}}))
	mux.Handle("GET /networks", j([]map[string]any{{"Id": "n1", "Name": "bridge", "Driver": "bridge"}}))
	mux.Handle("GET /volumes", j(map[string]any{"Volumes": []map[string]any{{"Name": "v1", "Driver": "local"}}}))
	srv := httptest.NewServer(mux)
	defer srv.Close()
	dc := &docker.Client{HTTP: srv.Client(), Base: srv.URL}
	inv, err := CollectInventory(context.Background(), dc)
	if err != nil {
		t.Fatal(err)
	}
	c := inv.Containers[0]
	if c.Name != "web" || c.Health != "healthy" || c.CPUPct != 20 || c.MemMB != 2 || c.Ports[0] != "0.0.0.0:8080->80/tcp" {
		t.Fatalf("%+v", c)
	}
	if len(inv.Images) != 1 || len(inv.Networks) != 1 || len(inv.Volumes) != 1 {
		t.Fatalf("%+v", inv)
	}
}
