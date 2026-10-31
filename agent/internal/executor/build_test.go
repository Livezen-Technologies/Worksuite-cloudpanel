package executor

import (
	"archive/tar"
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/livezen-technologies/cloudpanel/agent/internal/docker"
	"github.com/livezen-technologies/cloudpanel/agent/internal/protocol"
)

// buildDocker fakes the Engine endpoints used by image.build/scan/healthcheck.
type buildDocker struct {
	mu        sync.Mutex
	buildQ    url.Values
	tarFiles  []string
	pushAuth  string
	buildFail bool
	inspect   string // JSON for GET /containers/{id}/json
	pulls     []string
	logs      string
}

func (b *buildDocker) handler(t *testing.T) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		b.mu.Lock()
		defer b.mu.Unlock()
		switch {
		case r.Method == "POST" && r.URL.Path == "/build":
			b.buildQ = r.URL.Query()
			tr := tar.NewReader(r.Body)
			for {
				h, err := tr.Next()
				if err != nil {
					break
				}
				b.tarFiles = append(b.tarFiles, h.Name)
			}
			if b.buildFail {
				w.Write([]byte(`{"stream":"Step 1/2 : FROM scratch\n"}` + "\n" + `{"errorDetail":{"message":"COPY failed: no such file"},"error":"COPY failed: no such file"}` + "\n"))
				return
			}
			w.Write([]byte(`{"stream":"Step 1/2 : FROM scratch"}` + "\n" + `{"stream":"\n"}` + "\n" + `{"stream":"Step 2/2 : COPY app /app\n ---> Using cache\n ---> abc\n"}` + "\n" + `{"aux":{"ID":"sha256:feed"}}` + "\n" + `{"stream":"Successfully built feed\n"}` + "\n"))
		case r.Method == "POST" && r.URL.Path == "/images/create":
			ref := r.URL.Query().Get("fromImage") + ":" + r.URL.Query().Get("tag")
			b.pulls = append(b.pulls, ref)
			if strings.Contains(ref, "gone") {
				w.WriteHeader(404)
				w.Write([]byte(`{"message":"manifest unknown"}`))
				return
			}
			w.Write([]byte(`{"status":"Pulled"}` + "\n"))
		case r.Method == "POST" && strings.HasSuffix(r.URL.Path, "/push"):
			b.pushAuth = r.Header.Get("X-Registry-Auth")
			if r.URL.Path != "/images/reg.example.com:5000/org/app/push" || r.URL.Query().Get("tag") != "v1" {
				t.Errorf("push path %s ?%s", r.URL.Path, r.URL.RawQuery)
			}
			w.Write([]byte(`{"status":"The push refers to repository [reg.example.com:5000/org/app]"}` + "\n" + `{"status":"v1: digest: sha256:d1 size: 528"}` + "\n" + `{"aux":{"Tag":"v1","Digest":"sha256:d1","Size":528}}` + "\n"))
		case strings.HasPrefix(r.URL.Path, "/containers/") && strings.HasSuffix(r.URL.Path, "/json"):
			w.Write([]byte(b.inspect))
		case strings.HasSuffix(r.URL.Path, "/logs"):
			w.Write([]byte(b.logs))
		default:
			w.WriteHeader(404)
			w.Write([]byte(`{"message":"not found"}`))
		}
	})
}

func buildSetup(t *testing.T) (*Executor, *buildDocker, *fakePanel) {
	t.Helper()
	dir := t.TempDir()
	sock := filepath.Join(dir, "docker.sock")
	l, err := net.Listen("unix", sock)
	if err != nil {
		t.Fatal(err)
	}
	bd := &buildDocker{}
	srv := httptest.NewUnstartedServer(bd.handler(t))
	srv.Listener = l
	srv.Start()
	t.Cleanup(srv.Close)
	fp := &fakePanel{results: map[string][]protocol.Result{}}
	e := &Executor{
		Docker:   docker.New(sock),
		HMACKey:  key,
		Journal:  &Journal{Path: filepath.Join(dir, "state", "journal.json")},
		Report:   fp.report,
		StateDir: filepath.Join(dir, "state"),
		Now:      func() time.Time { return now },
	}
	return e, bd, fp
}

// fakeGit is a Runner that "clones" a fixed tree and records invocations.
type fakeGit struct {
	calls [][]string
	envs  [][]string
	fail  bool
	node  bool // clone a Node.js app without a Dockerfile
}

func (g *fakeGit) run(_ context.Context, dir string, env []string, name string, args ...string) ([]byte, error) {
	g.calls = append(g.calls, append([]string{name}, args...))
	g.envs = append(g.envs, env)
	if name != "git" {
		return nil, fmt.Errorf("unexpected program %s", name)
	}
	for _, a := range args {
		if a == "clone" {
			if g.fail {
				return []byte("fatal: repository not found\n"), errors.New("exit status 128")
			}
			dst := args[len(args)-1]
			os.MkdirAll(filepath.Join(dst, ".git"), 0o755)
			os.WriteFile(filepath.Join(dst, ".git", "HEAD"), []byte("ref"), 0o644)
			os.MkdirAll(filepath.Join(dst, "svc"), 0o755)
			if g.node {
				os.WriteFile(filepath.Join(dst, "package.json"), []byte(`{"scripts":{"build":"vite build","start":"node server.js"}}`), 0o644)
				os.WriteFile(filepath.Join(dst, "package-lock.json"), []byte(`{}`), 0o644)
				return []byte("Cloning into 'src'...\n"), nil
			}
			os.WriteFile(filepath.Join(dst, "svc", "Dockerfile"), []byte("FROM scratch\nCOPY app /app\n"), 0o644)
			os.WriteFile(filepath.Join(dst, "svc", "app"), []byte("bin"), 0o755)
			os.Symlink("/etc/passwd", filepath.Join(dst, "svc", "link"))
			return []byte("Cloning into 'src'...\n"), nil
		}
		if a == "rev-parse" {
			return []byte("0123456789abcdef0123456789abcdef01234567\n"), nil
		}
	}
	return nil, nil
}

func TestImageBuildAndPush(t *testing.T) {
	e, bd, fp := buildSetup(t)
	g := &fakeGit{}
	e.Run = g.run
	payload := `{"repo":"https://git.example.com/org/app.git","ref":"main","dockerfile_path":"svc/Dockerfile","context":"svc","build_args":{"VERSION":"1"},"git_auth":{"token":"s3cret"},"image_tag":"reg.example.com:5000/org/app:v1","push":{"registry":"reg.example.com:5000","username":"u","password":"p"}}`
	r, err := e.Handle(context.Background(), cmd("b1", "image.build", payload, now))
	if err != nil {
		t.Fatal(err, r.Message)
	}
	out := r.Output.(map[string]any)
	if out["digest"] != "sha256:d1" || out["repo_digest"] != "reg.example.com:5000/org/app@sha256:d1" || out["image_id"] != "sha256:feed" || out["commit"] != "0123456789abcdef0123456789abcdef01234567" {
		t.Fatalf("output %+v", out)
	}
	// git: fixed args, `--` before the URL, token only in env (never argv).
	clone := g.calls[0]
	joined := strings.Join(clone, " ")
	if !strings.Contains(joined, "clone --depth 1 --single-branch --no-tags --branch main -- https://git.example.com/org/app.git") || strings.Contains(joined, "s3cret") {
		t.Fatalf("clone args %v", clone)
	}
	envs := strings.Join(g.envs[0], "\n")
	want := "GIT_CONFIG_VALUE_0=Authorization: Basic " + base64.StdEncoding.EncodeToString([]byte("x-access-token:s3cret"))
	if !strings.Contains(envs, want) || !strings.Contains(envs, "GIT_TERMINAL_PROMPT=0") {
		t.Fatalf("env %v", g.envs[0])
	}
	// build: context tar without .git, dockerfile relative to the context, no host network.
	if bd.buildQ.Get("dockerfile") != "Dockerfile" || bd.buildQ.Get("networkmode") != "default" || bd.buildQ.Get("t") != "reg.example.com:5000/org/app:v1" || bd.buildQ.Get("buildargs") != `{"VERSION":"1"}` {
		t.Fatalf("build query %v", bd.buildQ)
	}
	files := strings.Join(bd.tarFiles, ",")
	if !strings.Contains(files, "Dockerfile") || !strings.Contains(files, "app") || strings.Contains(files, ".git") {
		t.Fatalf("tar files %v", bd.tarFiles)
	}
	var auth map[string]string
	raw, _ := base64.URLEncoding.DecodeString(bd.pushAuth)
	json.Unmarshal(raw, &auth)
	if auth["username"] != "u" || auth["serveraddress"] != "reg.example.com:5000" {
		t.Fatalf("push auth %v", auth)
	}
	// Log lines streamed as running progress.
	var logs []string
	for _, res := range fp.results["b1"] {
		if res.Status == "running" && res.Message != "" {
			logs = append(logs, res.Message)
		}
	}
	all := strings.Join(logs, "\n")
	if !strings.Contains(all, "Step 1/2 : FROM scratch") || !strings.Contains(all, "pushed reg.example.com:5000/org/app@sha256:d1") {
		t.Fatalf("progress logs %q", all)
	}
	// temp checkout cleaned up
	if ents, _ := os.ReadDir(filepath.Join(e.StateDir, "builds")); len(ents) != 0 {
		t.Fatalf("build dir not cleaned: %v", ents)
	}
}

func TestImageBuildLocalAndFailures(t *testing.T) {
	e, bd, _ := buildSetup(t)
	g := &fakeGit{}
	e.Run = g.run
	// local repos refused unless enabled
	if _, err := e.Handle(context.Background(), cmd("l1", "image.build", `{"repo":"/srv/repo.git","context":"svc","dockerfile_path":"svc/Dockerfile","image_tag":"dmp/app:1"}`, now)); err == nil || !strings.Contains(err.Error(), "local repositories are disabled") {
		t.Fatal(err)
	}
	e.AllowLocalGit = true
	r, err := e.Handle(context.Background(), cmd("l2", "image.build", `{"repo":"/srv/repo.git","context":"svc","dockerfile_path":"svc/Dockerfile","image_tag":"dmp/app:1"}`, now))
	if err != nil {
		t.Fatal(err)
	}
	if out := r.Output.(map[string]any); out["digest"] != "sha256:feed" || out["repo_digest"] != nil {
		t.Fatalf("%+v", out)
	}
	if !strings.Contains(strings.Join(g.calls[len(g.calls)-2], " "), "-- file:///srv/repo.git") {
		t.Fatalf("local clone uses file://: %v", g.calls)
	}
	bad := map[string]string{
		"traversal":   `{"repo":"https://x/y","context":"../..","image_tag":"a:1"}`,
		"option":      `{"repo":"--upload-pack=sh","image_tag":"a:1"}`,
		"ref":         `{"repo":"https://x/y","ref":"-x","image_tag":"a:1"}`,
		"scheme":      `{"repo":"ext::sh -c id","image_tag":"a:1"}`,
		"push prefix": `{"repo":"https://x/y","image_tag":"a:1","push":{"registry":"reg.io"}}`,
		"outside ctx": `{"repo":"https://x/y","context":"svc/x","dockerfile_path":"svc/Dockerfile","image_tag":"a:1"}`,
	}
	for name, p := range bad {
		if _, err := e.Handle(context.Background(), cmd("bad-"+name, "image.build", p, now)); err == nil {
			t.Errorf("%s: expected error", name)
		}
	}
	// build error returns the log tail
	bd.buildFail = true
	r, err = e.Handle(context.Background(), cmd("f1", "image.build", `{"repo":"https://x/y","context":"svc","dockerfile_path":"svc/Dockerfile","image_tag":"dmp/app:2"}`, now))
	if err == nil || !strings.Contains(r.Message, "COPY failed") {
		t.Fatal(err, r.Message)
	}
	if lines := r.Output.(map[string]any)["log"].([]string); !strings.Contains(strings.Join(lines, "\n"), "ERROR: COPY failed") {
		t.Fatalf("%v", lines)
	}
	// clone failure
	bd.buildFail = false
	g.fail = true
	if r, err = e.Handle(context.Background(), cmd("f2", "image.build", `{"repo":"https://x/y","image_tag":"dmp/app:3"}`, now)); err == nil || !strings.Contains(r.Message, "git clone failed") {
		t.Fatal(err, r.Message)
	}
}

func TestImageScanTrivy(t *testing.T) {
	e, _, _ := buildSetup(t)
	e.LookPath = func(string) (string, error) { return "", errors.New("not found") }
	if r, _ := e.Handle(context.Background(), cmd("s0", "image.scan", `{"image":"nginx:1"}`, now)); r.Message != "trivy not installed" {
		t.Fatalf("%q", r.Message)
	}
	e.LookPath = func(string) (string, error) { return "/usr/bin/trivy", nil }
	var args []string
	e.Run = func(_ context.Context, _ string, _ []string, name string, a ...string) ([]byte, error) {
		args = append([]string{name}, a...)
		out := a[len(a)-2]
		os.WriteFile(out, []byte(`{"Results":[{"Target":"alpine","Vulnerabilities":[{"VulnerabilityID":"CVE-1","PkgName":"zlib","InstalledVersion":"1","Severity":"LOW"},{"VulnerabilityID":"CVE-2","PkgName":"openssl","InstalledVersion":"3","FixedVersion":"3.1","Severity":"CRITICAL"}]},{"Target":"app"}]}`), 0o600)
		return nil, nil
	}
	r, err := e.Handle(context.Background(), cmd("s1", "image.scan", `{"image":"nginx:1"}`, now))
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(args[:5], " ") != "/usr/bin/trivy image --format json --quiet" || args[len(args)-1] != "nginx:1" {
		t.Fatalf("args %v", args)
	}
	b, _ := json.Marshal(r.Output)
	if !strings.Contains(string(b), `"counts":{"critical":1,"low":1}`) || !strings.Contains(string(b), `"vulnerabilities":[{"id":"CVE-2"`) {
		t.Fatalf("%s", b)
	}
	if _, err := e.Handle(context.Background(), cmd("s2", "image.scan", `{"image":"--help"}`, now)); err == nil {
		t.Fatal("option injection accepted")
	}
}

func TestHealthCheck(t *testing.T) {
	e, bd, _ := buildSetup(t)
	e.HealthInterval = 10 * time.Millisecond
	var status atomic.Int32
	status.Store(503)
	app := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/healthz" {
			w.WriteHeader(404)
			return
		}
		w.WriteHeader(int(status.Load()))
	}))
	defer app.Close()
	_, port, _ := net.SplitHostPort(strings.TrimPrefix(app.URL, "http://"))
	bd.inspect = `{"Id":"c1","State":{"Status":"running","Running":true},"Config":{"Tty":true},"NetworkSettings":{"Networks":{"proxy":{"IPAddress":"127.0.0.1"}}}}`
	bd.logs = "boot\nlistening\n"
	go func() { time.Sleep(50 * time.Millisecond); status.Store(200) }()
	r, err := e.Handle(context.Background(), cmd("h1", "container.healthcheck", `{"container_id":"app-x","port":`+port+`,"path":"/healthz","timeout_s":5}`, now))
	if err != nil {
		t.Fatal(err, r.Message)
	}
	if out := r.Output.(map[string]any); out["method"] != "http" || out["status"] != 200 {
		t.Fatalf("%+v", out)
	}
	// wrong path never passes: times out with logs
	r, err = e.Handle(context.Background(), cmd("h2", "container.healthcheck", `{"container_id":"app-x","port":`+port+`,"path":"/nope","timeout_s":1}`, now))
	if err == nil || !strings.Contains(r.Message, "returned 404") || r.Output.(map[string]any)["logs"] != "boot\nlistening\n" {
		t.Fatal(err, r.Message, r.Output)
	}
	// exited container fails immediately
	bd.mu.Lock()
	bd.inspect = `{"Id":"c1","State":{"Status":"exited","Running":false,"ExitCode":2},"Config":{"Tty":true}}`
	bd.mu.Unlock()
	if r, _ = e.Handle(context.Background(), cmd("h3", "container.healthcheck", `{"container_id":"app-x","port":80}`, now)); !strings.Contains(r.Message, "exit code 2") {
		t.Fatal(r.Message)
	}
	// Docker health status wins over HTTP
	bd.mu.Lock()
	bd.inspect = `{"Id":"c1","State":{"Status":"running","Running":true,"Health":{"Status":"unhealthy"}},"Config":{"Tty":true}}`
	bd.mu.Unlock()
	if r, _ = e.Handle(context.Background(), cmd("h4", "container.healthcheck", `{"container_id":"app-x","port":80}`, now)); !strings.Contains(r.Message, "unhealthy") {
		t.Fatal(r.Message)
	}
	if _, err := e.Handle(context.Background(), cmd("h5", "container.healthcheck", `{"container_id":"app-x","path":"no-slash"}`, now)); err == nil {
		t.Fatal("bad path accepted")
	}
}

func TestImageBuildAutoDetectCacheAndTimeout(t *testing.T) {
	e, bd, _ := buildSetup(t)
	g := &fakeGit{node: true}
	e.Run = g.run
	r, err := e.Handle(context.Background(), cmd("a1", "image.build", `{"repo":"https://x/y","builder":"auto","timeout_seconds":600,"no_cache":true,"image_tag":"dmp/app:1"}`, now))
	if err != nil {
		t.Fatal(err, r.Message)
	}
	out := r.Output.(map[string]any)
	if out["stack"] != "node" || out["cache_steps"] != 2 || out["cache_hits"] != 1 {
		t.Fatalf("%+v", out)
	}
	if _, ok := out["duration_seconds"].(int); !ok {
		t.Fatalf("duration %+v", out)
	}
	if bd.buildQ.Get("dockerfile") != ".dockermgr.Dockerfile" || bd.buildQ.Get("nocache") != "1" {
		t.Fatalf("query %v", bd.buildQ)
	}
	if !strings.Contains(strings.Join(bd.tarFiles, ","), ".dockermgr.Dockerfile") {
		t.Fatalf("tar %v", bd.tarFiles)
	}
	// The default builder needs a real Dockerfile.
	if _, err := e.Handle(context.Background(), cmd("a2", "image.build", `{"repo":"https://x/y","image_tag":"dmp/app:2"}`, now)); err == nil || !strings.Contains(err.Error(), "not found") {
		t.Fatal(err)
	}
	if _, err := e.Handle(context.Background(), cmd("a3", "image.build", `{"repo":"https://x/y","builder":"buildpacks","image_tag":"dmp/app:3"}`, now)); err == nil {
		t.Fatal("unknown builder accepted")
	}
}

func TestDetectStack(t *testing.T) {
	for marker, want := range map[string]string{"requirements.txt": "python", "go.mod": "go", "composer.json": "php", "index.html": "static", "README.md": ""} {
		dir := t.TempDir()
		os.WriteFile(filepath.Join(dir, marker), []byte("x"), 0o644)
		if got, df := detectStack(dir); got != want || (want != "" && !strings.Contains(df, "EXPOSE 8080")) {
			t.Errorf("%s: got %q", marker, got)
		}
	}
}

func TestImageBuildUsesRegistryImagesAsCache(t *testing.T) {
	e, bd, _ := buildSetup(t)
	e.Run = (&fakeGit{}).run
	payload := `{"repo":"https://x/y","dockerfile_path":"svc/Dockerfile","context":"svc","image_tag":"reg.example.com:5000/org/app:v1",
		"push":{"registry":"reg.example.com:5000","username":"u","password":"p"},
		"cache_from":["reg.example.com:5000/org/app:v0","reg.example.com:5000/org/app:gone"]}`
	r, err := e.Handle(context.Background(), cmd("cf1", "image.build", payload, now))
	if err != nil {
		t.Fatal(err, r.Message)
	}
	if got := bd.buildQ.Get("cachefrom"); got != `["reg.example.com:5000/org/app:v0"]` {
		t.Fatalf("cachefrom %q (pulls %v)", got, bd.pulls)
	}
	if fmt.Sprint(r.Output.(map[string]any)["cache_from"]) != "[reg.example.com:5000/org/app:v0]" {
		t.Fatalf("%+v", r.Output)
	}
	bad := `{"repo":"https://x/y","image_tag":"reg.example.com:5000/org/app:v3","push":{"registry":"reg.example.com:5000"},"cache_from":["docker.io/evil/img:1"]}`
	if _, err := e.Handle(context.Background(), cmd("cf2", "image.build", bad, now)); err == nil {
		t.Fatal("cache image outside the push registry accepted")
	}
}
