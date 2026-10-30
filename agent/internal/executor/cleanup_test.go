package executor

import (
	"context"
	"fmt"
	"net"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/livezen-technologies/cloudpanel/agent/internal/docker"
	"github.com/livezen-technologies/cloudpanel/agent/internal/protocol"
)

func cleanupSetup(t *testing.T) (*Executor, *[]string) {
	t.Helper()
	old := time.Now().Add(-30 * 24 * time.Hour).Unix()
	recent := time.Now().Add(-2 * 24 * time.Hour).Unix()
	var mu sync.Mutex
	calls := []string{}
	h := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		calls = append(calls, r.Method+" "+r.URL.Path+" "+r.URL.RawQuery)
		mu.Unlock()
		switch {
		case r.URL.Path == "/containers/json":
			fmt.Fprint(w, `[{"Id":"c1","ImageID":"sha256:inuse","State":"running","Created":1}]`)
		case r.URL.Path == "/images/json":
			fmt.Fprintf(w, `[{"Id":"sha256:inuse","RepoTags":["web:1"],"Size":10,"Created":%d},
				{"Id":"sha256:last","RepoTags":["reg/app:v2"],"RepoDigests":["reg/app@sha256:good"],"Size":100,"Created":%d},
				{"Id":"sha256:old","RepoTags":["reg/app:v1"],"Size":200,"Created":%d},
				{"Id":"sha256:new","RepoTags":["reg/app:v3"],"Size":300,"Created":%d}]`, old, old, old, recent)
		case r.URL.Path == "/containers/prune":
			fmt.Fprint(w, `{"ContainersDeleted":["x"],"SpaceReclaimed":5}`)
		case r.URL.Path == "/images/prune":
			fmt.Fprint(w, `{"ImagesDeleted":[{"Deleted":"a"},{"Deleted":"b"}],"SpaceReclaimed":7}`)
		case r.URL.Path == "/build/prune":
			fmt.Fprint(w, `{"SpaceReclaimed":1000}`)
		case r.Method == "DELETE":
			fmt.Fprint(w, `[]`)
		default:
			w.WriteHeader(404)
		}
	})
	dir := t.TempDir()
	sock := filepath.Join(dir, "d.sock")
	l, err := net.Listen("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	srv := httptest.NewUnstartedServer(h)
	srv.Listener = l
	srv.Start()
	t.Cleanup(srv.Close)
	fp := &fakePanel{results: map[string][]protocol.Result{}}
	e := &Executor{Docker: docker.New(sock), HMACKey: key, Journal: &Journal{Path: filepath.Join(dir, "j.json")}, Report: fp.report, StateDir: dir, Now: func() time.Time { return now }}
	return e, &calls
}

func TestServerCleanupLevelsAndProtection(t *testing.T) {
	e, calls := cleanupSetup(t)
	r, err := e.Handle(context.Background(), cmd("s1", "server.cleanup", `{"level":"safe"}`, now))
	if err != nil {
		t.Fatal(err, r.Message)
	}
	out := r.Output.(map[string]any)
	if out["freed_bytes"] != int64(1012) || out["containers"] != 1 || out["dangling_images"] != 2 || len(out["images"].([]string)) != 0 {
		t.Fatalf("safe %+v", out)
	}
	joined := strings.Join(*calls, "\n")
	if !strings.Contains(joined, "label%21") || strings.Contains(joined, "DELETE") || strings.Contains(joined, "volumes") {
		t.Fatalf("safe calls %s", joined)
	}

	*calls = nil
	r, err = e.Handle(context.Background(), cmd("s2", "server.cleanup", `{"level":"standard","protect":["sha256:good"]}`, now))
	if err != nil {
		t.Fatal(err)
	}
	out = r.Output.(map[string]any)
	if fmt.Sprint(out["images"]) != "[reg/app:v1]" || fmt.Sprint(out["protected"]) != "[reg/app:v2]" {
		t.Fatalf("standard %+v", out)
	}

	r, _ = e.Handle(context.Background(), cmd("s3", "server.cleanup", `{"level":"aggressive","dry_run":true}`, now))
	out = r.Output.(map[string]any)
	imgs := out["images"].([]string)
	sort.Strings(imgs)
	if fmt.Sprint(imgs) != "[reg/app:v1 reg/app:v2 reg/app:v3]" || out["freed_bytes"] != int64(600) {
		t.Fatalf("aggressive dry run %+v", out)
	}
	if _, err := e.Handle(context.Background(), cmd("s4", "server.cleanup", `{"level":"nuke"}`, now)); err == nil {
		t.Fatal("bad level accepted")
	}
}
