package executor

import (
	"context"
	"strings"
	"testing"
	"time"
)

func TestRestoreTestSandboxCountsRowsInAFreshDatabase(t *testing.T) {
	sandboxPoll = time.Millisecond
	defer func() { sandboxPoll = 2 * time.Second }()
	e, _, _, _ := setup(t)
	f := &fakeTools{restoreFiles: map[string]string{"var/lib/dockermgr/dumps/db/postgres.dump": "PGDMP-ok"}}
	f.install(e)
	base := e.Run
	var docker [][]string
	readyTries := 0
	e.Run = func(ctx context.Context, dir string, env []string, name string, args ...string) ([]byte, error) {
		if !strings.HasSuffix(name, "docker") {
			return base(ctx, dir, env, name, args...)
		}
		docker = append(docker, args)
		switch {
		case len(args) > 2 && args[2] == "pg_isready":
			if readyTries++; readyTries < 3 {
				return []byte("no response"), context.DeadlineExceeded
			}
		case len(args) > 2 && args[2] == "psql":
			return []byte("public.orders=120\npublic.users=7\n"), nil
		}
		return []byte(""), nil
	}
	r, err := e.Handle(context.Background(), cmd("sb1", "backup.test", `{"repository":"/repo","engine":"postgres","sandbox":{"kind":"db","image":"postgres:16-alpine"}}`, now))
	if err != nil {
		t.Fatal(err, r.Message)
	}
	sb := r.Output.(map[string]any)["sandbox"].(map[string]any)
	if sb["tables"] != 2 || sb["rows"] != int64(127) {
		t.Fatalf("%+v", sb)
	}
	run := strings.Join(docker[1], " ")
	for _, want := range []string{"run -d --name ws-sandbox-sb1 --network none", "POSTGRES_PASSWORD=sandbox", "postgres:16-alpine"} {
		if !strings.Contains(run, want) {
			t.Fatalf("missing %q in %s", want, run)
		}
	}
	if load := strings.Join(f.streams[len(f.streams)-1], " "); !strings.HasSuffix(load, "exec -i ws-sandbox-sb1 pg_restore -U postgres --no-owner -d postgres") {
		t.Fatal(load)
	}
	if last := strings.Join(docker[len(docker)-1], " "); last != "rm -f -v ws-sandbox-sb1" {
		t.Fatalf("sandbox not removed: %s", last)
	}

	// An empty database fails the test.
	e.Run = func(ctx context.Context, dir string, env []string, name string, args ...string) ([]byte, error) {
		if !strings.HasSuffix(name, "docker") {
			return base(ctx, dir, env, name, args...)
		}
		return nil, nil
	}
	if _, err := e.Handle(context.Background(), cmd("sb2", "backup.test", `{"repository":"/repo","engine":"postgres","sandbox":{"kind":"db","image":"postgres:16-alpine"}}`, now)); err == nil || !strings.Contains(err.Error(), "no tables") {
		t.Fatalf("empty database accepted: %v", err)
	}
	for _, bad := range []string{`{"kind":"db","image":"postgres:16"}`, `{"kind":"app","image":"x:1","source":"rel","mount":"/data"}`, `{"kind":"vm","image":"x:1"}`} {
		if _, err := e.Handle(context.Background(), cmd("sb3", "backup.test", `{"repository":"/repo","engine":"redis","sandbox":`+bad+`}`, now)); err == nil {
			t.Errorf("sandbox %s accepted", bad)
		}
	}
}

func TestRestoreTestSandboxStartsTheAppOnRestoredFiles(t *testing.T) {
	e, _, _, _ := setup(t)
	f := &fakeTools{restoreFiles: map[string]string{"var/lib/docker/volumes/app-shop-data/_data/index.html": "hi"}}
	f.install(e)
	base := e.Run
	running := "true 0 0"
	var runArgs string
	e.Run = func(ctx context.Context, dir string, env []string, name string, args ...string) ([]byte, error) {
		if !strings.HasSuffix(name, "docker") {
			return base(ctx, dir, env, name, args...)
		}
		switch args[0] {
		case "run":
			runArgs = strings.Join(args, " ")
		case "inspect":
			return []byte(running), nil
		case "logs":
			return []byte("panic: missing config"), nil
		}
		return nil, nil
	}
	spec := `{"repository":"/repo","sandbox":{"kind":"app","image":"acme/shop:1","source":"/var/lib/docker/volumes/app-shop-data/_data","mount":"/data","seconds":1}}`
	r, err := e.Handle(context.Background(), cmd("sa1", "backup.test", spec, now))
	if err != nil {
		t.Fatal(err, r.Message)
	}
	if !strings.Contains(runArgs, "--network none") || !strings.Contains(runArgs, "/var/lib/docker/volumes/app-shop-data/_data:/data acme/shop:1") {
		t.Fatal(runArgs)
	}
	if r.Output.(map[string]any)["sandbox"].(map[string]any)["running"] != true {
		t.Fatalf("%+v", r.Output)
	}
	running = "false 1 0"
	if _, err := e.Handle(context.Background(), cmd("sa2", "backup.test", spec, now)); err == nil || !strings.Contains(err.Error(), "did not stay up") {
		t.Fatalf("crashed app accepted: %v", err)
	}
}
