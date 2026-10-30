package executor

import (
	"context"
	"encoding/json"
	"errors"
	"io"
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
	"github.com/livezen-technologies/cloudpanel/agent/internal/policy"
	"github.com/livezen-technologies/cloudpanel/agent/internal/protocol"
	"github.com/livezen-technologies/cloudpanel/agent/internal/signing"
)

var key = []byte("test-hmac-key")
var now = time.Date(2026, 10, 27, 12, 0, 0, 0, time.UTC)

type fakeDocker struct {
	mu    sync.Mutex
	calls []string
	body  map[string]any
	// pullStatus, when set, is returned for POST /images/create (e.g. 429 rate limit).
	pullStatus int
	// localImages are answered by GET /images/{ref}/json; others are 404.
	localImages map[string]bool
}

func (f *fakeDocker) handler(t *testing.T) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		f.mu.Lock()
		f.calls = append(f.calls, r.Method+" "+r.URL.Path+"?"+r.URL.RawQuery)
		f.mu.Unlock()
		switch {
		case r.URL.Path == "/images/create" && f.pullStatus != 0:
			w.WriteHeader(f.pullStatus)
			w.Write([]byte(`{"message":"toomanyrequests: rate limit"}`))
		case strings.HasPrefix(r.URL.Path, "/images/") && strings.HasSuffix(r.URL.Path, "/json"):
			ref := strings.TrimSuffix(strings.TrimPrefix(r.URL.Path, "/images/"), "/json")
			if !f.localImages[ref] {
				w.WriteHeader(404)
				w.Write([]byte(`{"message":"No such image"}`))
				return
			}
			w.Write([]byte(`{"Id":"sha256:abc"}`))
		case r.URL.Path == "/containers/create":
			b, _ := io.ReadAll(r.Body)
			f.mu.Lock()
			json.Unmarshal(b, &f.body)
			f.mu.Unlock()
			w.WriteHeader(201)
			w.Write([]byte(`{"Id":"newid"}`))
		case strings.HasSuffix(r.URL.Path, "/json") && strings.HasPrefix(r.URL.Path, "/containers/"):
			w.Write([]byte(`{"Id":"c1","Config":{"Tty":true}}`))
		case strings.HasSuffix(r.URL.Path, "/logs"):
			w.Write([]byte("hello\n"))
		case r.URL.Path == "/networks/create":
			w.WriteHeader(201)
			w.Write([]byte(`{"Id":"net1"}`))
		default:
			if r.Method == "POST" && strings.HasSuffix(r.URL.Path, "/prune") {
				w.Write([]byte(`{"SpaceReclaimed":0}`))
				return
			}
			w.WriteHeader(204)
		}
	})
}

func (f *fakeDocker) has(prefix string) bool {
	f.mu.Lock()
	defer f.mu.Unlock()
	for _, c := range f.calls {
		if strings.HasPrefix(c, prefix) {
			return true
		}
	}
	return false
}

type fakePanel struct {
	mu      sync.Mutex
	results map[string][]protocol.Result
}

func (p *fakePanel) report(_ context.Context, id string, r protocol.Result) error {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.results[id] = append(p.results[id], r)
	return nil
}

func setup(t *testing.T) (*Executor, *fakeDocker, *fakePanel, string) {
	t.Helper()
	dir := t.TempDir()
	sock := filepath.Join(dir, "docker.sock")
	l, err := net.Listen("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	fd := &fakeDocker{}
	srv := httptest.NewUnstartedServer(fd.handler(t))
	srv.Listener = l
	srv.Start()
	t.Cleanup(srv.Close)
	fp := &fakePanel{results: map[string][]protocol.Result{}}
	e := &Executor{
		Docker:           docker.New(sock),
		Policy:           &policy.Store{Path: filepath.Join(dir, "policy.json")},
		HMACKey:          key,
		Journal:          &Journal{Path: filepath.Join(dir, "state", "journal.json")},
		Report:           fp.report,
		StateDir:         filepath.Join(dir, "state"),
		AllowedBindRoots: []string{"/srv/data"},
		Now:              func() time.Time { return now },
	}
	return e, fd, fp, dir
}

func cmd(id, typ, payload string, issued time.Time) protocol.Command {
	ts := issued.UTC().Format(time.RFC3339)
	sig, _ := signing.Sign(key, id, typ, []byte(payload), ts)
	return protocol.Command{ID: id, Type: typ, Version: 1, Payload: json.RawMessage(payload), IssuedAt: ts, Signature: sig}
}

func TestStartAndDuplicate(t *testing.T) {
	e, fd, fp, _ := setup(t)
	c := cmd("op-1", "container.start", `{"container_id":"web"}`, now.Add(-time.Minute))
	r, err := e.Handle(context.Background(), c)
	if err != nil || r.Status != "succeeded" {
		t.Fatal(r, err)
	}
	if !fd.has("POST /containers/web/start") {
		t.Fatal(fd.calls)
	}
	if got := fp.results["op-1"]; len(got) != 2 || got[0].Status != "running" || got[1].Status != "succeeded" {
		t.Fatalf("%+v", got)
	}
	// Duplicate, also after a "restart" (fresh journal object, same file).
	e.Journal = &Journal{Path: e.Journal.Path}
	if _, err := e.Handle(context.Background(), c); !errors.Is(err, ErrDuplicate) {
		t.Fatal("duplicate not rejected", err)
	}
	if len(fp.results["op-1"]) != 2 {
		t.Fatal("duplicate must not report")
	}
}

func TestRejections(t *testing.T) {
	e, fd, _, dir := setup(t)
	ctx := context.Background()
	bad := cmd("a", "container.start", `{"container_id":"web"}`, now)
	bad.Signature = strings.Repeat("0", 64)
	old := cmd("b", "container.start", `{"container_id":"web"}`, now.Add(-11*time.Minute))
	unknown := cmd("c", "host.shell", `{"cmd":"rm -rf /"}`, now)
	unsigned := cmd("d", "container.start", `{}`, now)
	unsigned.Signature = ""
	tampered := cmd("e", "container.start", `{"container_id":"web"}`, now)
	tampered.Payload = json.RawMessage(`{"container_id":"db"}`)
	future := cmd("f", "container.start", `{"container_id":"web"}`, now.Add(10*time.Minute))
	for _, c := range []protocol.Command{bad, old, unknown, unsigned, tampered, future} {
		if _, err := e.Handle(ctx, c); err == nil {
			t.Errorf("command %s accepted", c.ID)
		}
	}
	os.WriteFile(filepath.Join(dir, "policy.json"), []byte(`{"disabled_types":["container.stop"]}`), 0o600)
	if _, err := e.Handle(ctx, cmd("g", "container.stop", `{"container_id":"web"}`, now)); err == nil || !strings.Contains(err.Error(), "policy") {
		t.Fatal("policy not enforced", err)
	}
	if fd.has("POST") {
		t.Fatal("docker touched by rejected command", fd.calls)
	}
	// Invalid argument injection.
	if _, err := e.Handle(ctx, cmd("h", "container.start", `{"container_id":"../../x"}`, now)); err == nil {
		t.Fatal("bad id accepted")
	}
}

func TestCreateSecureDefaults(t *testing.T) {
	e, fd, _, _ := setup(t)
	p := `{"name":"app1","image":"nginx:1.27","user":"1000:1000","read_only_rootfs":true,"memory_mb":256,"cpus":0.5,
	"ports":[{"container_port":80,"host_port":8080}],"networks":[{"name":"proxy"},{"name":"backend","aliases":["app"]}],
	"mounts":[{"type":"volume","source":"data","target":"/data"}],
	"healthcheck":{"test":"curl -f http://localhost/","interval_s":10},
	"labels":{"traefik.enable":"true","traefik.http.routers.app1.rule":"Host(` + "`a.example.com`" + `)"},
	"env":{"B":"2","A":"1"}}`
	r, err := e.Handle(context.Background(), cmd("cr", "container.create", p, now))
	if err != nil {
		t.Fatal(r, err)
	}
	b := fd.body
	hc := b["HostConfig"].(map[string]any)
	if hc["CapDrop"].([]any)[0] != "ALL" || hc["SecurityOpt"].([]any)[0] != "no-new-privileges:true" || hc["ReadonlyRootfs"] != true || hc["Privileged"] != false {
		t.Fatalf("%v", hc)
	}
	if hc["Memory"].(float64) != 256<<20 || hc["NanoCpus"].(float64) != 5e8 || hc["RestartPolicy"].(map[string]any)["Name"] != "unless-stopped" {
		t.Fatalf("%v", hc)
	}
	if b["User"] != "1000:1000" || b["Labels"].(map[string]any)["traefik.enable"] != "true" || b["Env"].([]any)[0] != "A=1" {
		t.Fatalf("%v", b)
	}
	if b["Healthcheck"].(map[string]any)["Test"].([]any)[0] != "CMD-SHELL" {
		t.Fatal(b["Healthcheck"])
	}
	if !fd.has("POST /networks/backend/connect") || !fd.has("POST /containers/newid/start") {
		t.Fatal(fd.calls)
	}
	for i, bad := range []string{
		`{"image":"nginx","user":"root"}`,
		`{"image":"nginx","mounts":[{"type":"bind","source":"/var/run/docker.sock","target":"/s"}]}`,
		`{"image":"nginx","cap_add":["SYS_ADMIN"]}`,
		`{"image":"nginx","networks":[{"name":"host"}]}`,
	} {
		if _, err := e.Handle(context.Background(), cmd("bad"+string(rune('a'+i)), "container.create", bad, now)); err == nil {
			t.Errorf("unsafe create accepted: %s", bad)
		}
	}
}

func TestRemoveKeepsVolumes(t *testing.T) {
	e, fd, _, _ := setup(t)
	e.Handle(context.Background(), cmd("rm1", "container.remove", `{"container_id":"web"}`, now))
	if !fd.has("DELETE /containers/web?force=false&v=false") {
		t.Fatal(fd.calls)
	}
	e.Handle(context.Background(), cmd("rm2", "container.remove", `{"container_id":"web","keep_volumes":false,"force":true}`, now))
	if !fd.has("DELETE /containers/web?force=true&v=true") {
		t.Fatal(fd.calls)
	}
}

func TestOtherDockerCommands(t *testing.T) {
	e, fd, fp, _ := setup(t)
	ctx := context.Background()
	cases := []struct{ typ, payload, want string }{
		{"container.stop", `{"container_id":"web","timeout":5}`, "POST /containers/web/stop?t=5"},
		{"container.restart", `{"container_id":"web"}`, "POST /containers/web/restart"},
		{"container.pause", `{"container_id":"web"}`, "POST /containers/web/pause"},
		{"container.unpause", `{"container_id":"web"}`, "POST /containers/web/unpause"},
		{"container.logs", `{"container_id":"web","tail":50}`, "GET /containers/web/logs"},
		{"container.prune", `{}`, "POST /containers/prune"},
		{"image.pull", `{"image":"nginx:1.27"}`, "POST /images/create?fromImage=nginx&tag=1.27"},
		{"image.remove", `{"image":"nginx:1.27"}`, "DELETE /images/nginx:1.27"},
		{"image.prune", `{}`, "POST /images/prune"},
		{"network.create", `{"name":"proxy"}`, "POST /networks/create"},
		{"network.remove", `{"network_id":"proxy"}`, "DELETE /networks/proxy"},
		{"volume.create", `{"name":"data"}`, "POST /volumes/create"},
		{"volume.remove", `{"name":"data"}`, "DELETE /volumes/data"},
	}
	for i, c := range cases {
		id := "o" + string(rune('a'+i))
		if r, err := e.Handle(ctx, cmd(id, c.typ, c.payload, now)); err != nil {
			t.Errorf("%s: %v %+v", c.typ, err, r)
		}
		if !fd.has(c.want) {
			t.Errorf("%s: expected %s in %v", c.typ, c.want, fd.calls)
		}
	}
	logs := fp.results["oe"][1].Output.(map[string]any)["logs"]
	if logs != "hello\n" {
		t.Fatal(logs)
	}
}

func TestComposeAndRestic(t *testing.T) {
	e, _, _, _ := setup(t)
	var got [][]string
	var gotEnv []string
	e.Run = func(_ context.Context, dir string, env []string, name string, args ...string) ([]byte, error) {
		got = append(got, append([]string{name}, args...))
		gotEnv = env
		return []byte(`{"message_type":"summary","snapshot_id":"abc123","total_bytes_processed":10}`), nil
	}
	ctx := context.Background()
	if _, err := e.Handle(ctx, cmd("cu", "compose.up", `{"project":"shop","compose_yaml":"services:\n  web:\n    image: nginx\n","env":{"TAG":"1"}}`, now)); err != nil {
		t.Fatal(err)
	}
	y, _ := os.ReadFile(filepath.Join(e.StateDir, "compose/shop/compose.yaml"))
	if !strings.Contains(string(y), "nginx") || !strings.Contains(strings.Join(got[0], " "), "--project-name shop") || !strings.Contains(strings.Join(got[0], " "), "up --detach") {
		t.Fatal(got)
	}
	if _, err := e.Handle(ctx, cmd("cd", "compose.down", `{"project":"shop"}`, now)); err != nil {
		t.Fatal(err)
	}
	if strings.Contains(strings.Join(got[1], " "), "--volumes") {
		t.Fatal("down must keep volumes by default")
	}
	if _, err := e.Handle(ctx, cmd("cx", "compose.up", `{"project":"../etc","compose_yaml":"x"}`, now)); err == nil {
		t.Fatal("bad project accepted")
	}

	e.LookPath = func(string) (string, error) { return "", errors.New("nope") }
	r, err := e.Handle(ctx, cmd("b1", "backup.run", `{"repository":"s3:x/y","paths":["/data"]}`, now))
	if err == nil || !strings.Contains(r.Message, "restic is not installed") {
		t.Fatal(r, err)
	}
	e.LookPath = func(string) (string, error) { return "/usr/bin/restic", nil }
	if _, err := e.Handle(ctx, cmd("b2", "backup.run", `{"repository":"s3:x/y","paths":["/data"],"tags":["daily"],"env":{"RESTIC_PASSWORD":"p","AWS_ACCESS_KEY_ID":"a"}}`, now)); err != nil {
		t.Fatal(err)
	}
	last := strings.Join(got[len(got)-1], " ")
	if last != "/usr/bin/restic --repo=s3:x/y backup --json --tag=daily -- /data" || !strings.Contains(strings.Join(gotEnv, " "), "RESTIC_PASSWORD=p") {
		t.Fatal(last, gotEnv)
	}
	if _, err := e.Handle(ctx, cmd("b3", "backup.run", `{"repository":"r","paths":["/d"],"env":{"LD_PRELOAD":"x"}}`, now)); err == nil {
		t.Fatal("bad env accepted")
	}
	if _, err := e.Handle(ctx, cmd("r1", "restore.run", `{"repository":"r","snapshot":"abc123","target":"/restore"}`, now)); err != nil {
		t.Fatal(err)
	}
	if last := strings.Join(got[len(got)-1], " "); last != "/usr/bin/restic --repo=r restore --target=/restore abc123" {
		t.Fatal(last)
	}
}

func TestAgentRestart(t *testing.T) {
	e, _, fp, _ := setup(t)
	restarted := false
	e.Restart = func() { restarted = true }
	if _, err := e.Handle(context.Background(), cmd("ar", "agent.restart", `{}`, now)); err != nil {
		t.Fatal(err)
	}
	if !restarted || fp.results["ar"][1].Status != "succeeded" {
		t.Fatal("restart not performed after reporting")
	}
	if _, err := e.Handle(context.Background(), cmd("au", "agent.update", `{"url":"https://x"}`, now)); err == nil {
		t.Fatal("update without updater accepted")
	}
}

func TestCreatePullAlwaysFallsBackToLocalImage(t *testing.T) {
	e, fd, _, _ := setup(t)
	fd.pullStatus = 429
	fd.localImages = map[string]bool{"e2e/hello:1": true}
	r, err := e.Handle(context.Background(), cmd("pa1", "container.create", `{"name":"a","image":"e2e/hello:1","pull":"always"}`, now))
	if err != nil || r.Status != "succeeded" {
		t.Fatalf("expected fallback to local image: %+v %v", r, err)
	}
	if !fd.has("POST /containers/create") {
		t.Fatal(fd.calls)
	}
}

func TestCreatePullAlwaysFailsWithoutLocalImage(t *testing.T) {
	e, fd, _, _ := setup(t)
	fd.pullStatus = 429
	r, err := e.Handle(context.Background(), cmd("pa2", "container.create", `{"name":"a","image":"nginx:1.27","pull":"always"}`, now))
	if err == nil && r.Status == "succeeded" {
		t.Fatalf("expected failure: %+v", r)
	}
	if fd.has("POST /containers/create") {
		t.Fatal("must not create without an image", fd.calls)
	}
}

func TestCronExecRunsBesideTheQueueAndReportsWhenDone(t *testing.T) {
	e, _, fp, _ := setup(t)
	release := make(chan struct{})
	e.Run = func(ctx context.Context, _ string, _ []string, name string, args ...string) ([]byte, error) {
		<-release
		return []byte("ok"), nil
	}
	r, err := e.Handle(context.Background(), cmd("cr-1", "cron.exec", `{"container":"app-shop","command":"sleep 60","user":"1000"}`, now.Add(-time.Minute)))
	if err != nil || r.Status != "running" {
		t.Fatalf("a scheduled job must not block the queue: %+v %v", r, err)
	}
	close(release)
	deadline := time.Now().Add(2 * time.Second)
	for {
		fp.mu.Lock()
		got := fp.results["cr-1"]
		fp.mu.Unlock()
		if len(got) == 2 && got[1].Status == "succeeded" {
			break
		}
		if time.Now().After(deadline) {
			t.Fatalf("no final result: %+v", got)
		}
		time.Sleep(10 * time.Millisecond)
	}
}
