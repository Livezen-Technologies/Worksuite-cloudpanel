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

// A host whose data lives on a second array must report both filesystems, and
// report each array once: before per-mount reporting the panel showed only the
// root figure, so a node with 2.2 TB looked like it had 498 GB.
func TestDisksReportsEveryArrayOnce(t *testing.T) {
	d := t.TempDir()
	root := filepath.Join(d, "root")
	data := filepath.Join(d, "data")
	bind := filepath.Join(d, "bind")
	dkr := filepath.Join(d, "dockervol")
	for _, p := range []string{root, data, bind, dkr} {
		if err := os.MkdirAll(p, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	mounts := "proc /proc proc rw 0 0\n" +
		"tmpfs /run tmpfs rw 0 0\n" +
		"/dev/loop0 /snap/core squashfs ro 0 0\n" +
		"overlay /var/lib/docker/overlay2/abc/merged overlay rw 0 0\n" +
		"/dev/md3 " + root + " ext4 rw 0 0\n" +
		"/dev/md10 " + data + " ext4 rw 0 0\n" +
		"/dev/md3 " + bind + " ext4 rw 0 0\n" +
		"/dev/sdz /var/lib/docker/volumes ext4 rw 0 0\n"
	os.WriteFile(filepath.Join(d, "mounts"), []byte(mounts), 0o644)

	got := (&Sampler{ProcRoot: d}).Disks()
	if len(got) != 2 {
		t.Fatalf("want 2 filesystems, got %d: %+v", len(got), got)
	}
	devs := map[string]string{}
	for _, x := range got {
		if devs[x.Device] != "" {
			t.Fatalf("device %s reported twice", x.Device)
		}
		devs[x.Device] = x.Mount
		if x.SizeMB <= 0 || x.UsedPct < 0 || x.UsedPct > 100 {
			t.Fatalf("implausible %+v", x)
		}
		if x.FSType != "ext4" {
			t.Fatalf("fstype %q", x.FSType)
		}
	}
	if devs["/dev/md3"] == "" || devs["/dev/md10"] == "" {
		t.Fatalf("both arrays expected, got %v", devs)
	}
	if devs["/dev/md3"] == bind {
		t.Fatalf("the bind mount won over the real mount point")
	}
	// Largest first, so the panel can show the biggest array at a glance.
	if got[0].SizeMB < got[1].SizeMB {
		t.Fatalf("not sorted by size: %+v", got)
	}
}

func TestSampleCarriesDisks(t *testing.T) {
	d := t.TempDir()
	os.MkdirAll(filepath.Join(d, "net"), 0o755)
	os.WriteFile(filepath.Join(d, "meminfo"), []byte("MemTotal: 1000 kB\nMemAvailable: 250 kB\n"), 0o644)
	os.WriteFile(filepath.Join(d, "net/dev"), []byte(""), 0o644)
	os.WriteFile(filepath.Join(d, "stat"), []byte("cpu  100 0 100 800 0 0 0 0 0 0\n"), 0o644)
	os.WriteFile(filepath.Join(d, "mounts"), []byte("/dev/md3 "+d+" ext4 rw 0 0\n"), 0o644)
	if st := (&Sampler{ProcRoot: d, DiskPath: d}).Sample(); len(st.Disks) != 1 {
		t.Fatalf("disks not sampled: %+v", st.Disks)
	}
}

func TestUnescapeMount(t *testing.T) {
	if got := unescapeMount(`/mnt/my\040disk`); got != "/mnt/my disk" {
		t.Fatalf("got %q", got)
	}
	if got := unescapeMount("/mnt/plain"); got != "/mnt/plain" {
		t.Fatalf("got %q", got)
	}
}
