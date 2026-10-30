package executor

import (
	"context"
	"encoding/json"
	"os/exec"
	"strings"
	"testing"
)

func TestCronExecRunsAsAUserAndRecordsExitCodes(t *testing.T) {
	var got []string
	code := 0
	e := &Executor{Run: func(_ context.Context, _ string, _ []string, name string, args ...string) ([]byte, error) {
		got = append([]string{name}, args...)
		if code != 0 {
			return []byte("boom"), exec.Command("sh", "-c", "exit 3").Run()
		}
		return []byte("ok"), nil
	}}
	out, err := e.cronExec(context.Background(), json.RawMessage(`{"container":"app-shop","command":"php artisan schedule:run","user":"33:33"}`))
	if err != nil || out.(map[string]any)["exit_code"] != 0 {
		t.Fatalf("%v %v", out, err)
	}
	if strings.Join(got, " ") != `docker exec -u 33:33 app-shop sh -c exec sh -c "$1" sh php artisan schedule:run` {
		t.Fatalf("got %v", got)
	}
	code = 3
	out, err = e.cronExec(context.Background(), json.RawMessage(`{"container":"app-shop","command":"false","user":"1000"}`))
	if err != nil || out.(map[string]any)["exit_code"] != 3 || out.(map[string]any)["output"] != "boom" {
		t.Fatalf("a failing command is a result: %v %v", out, err)
	}
	for _, bad := range []string{`{"container":"app","command":"x","user":"0"}`, `{"container":"app","command":"x","user":"root"}`, `{"container":"app","command":"x"}`, `{"container":"a;b","command":"x","user":"33"}`, `{"container":"app","command":"","user":"33"}`} {
		if _, err := e.cronExec(context.Background(), json.RawMessage(bad)); err == nil {
			t.Errorf("%s must be refused", bad)
		}
	}
}

func TestCronOneOffRunsAFreshContainerWithEnvAndVolumes(t *testing.T) {
	var got string
	e := &Executor{Run: func(_ context.Context, _ string, _ []string, name string, args ...string) ([]byte, error) {
		got = name + " " + strings.Join(args, " ")
		return nil, nil
	}}
	_, err := e.cronExec(context.Background(), json.RawMessage(`{"run_id":"r1","mode":"oneoff","image":"ghcr.io/acme/shop:1","env":{"B":"2","A":"1"},"mounts":[{"source":"app-shop-data","target":"/data"}],"network":"proxy","command":"php artisan queue:prune","user":"1000:1000"}`))
	if err != nil {
		t.Fatal(err)
	}
	want := "docker run --rm --name ws-cron-r1 --user 1000:1000 --security-opt no-new-privileges --network proxy -e A=1 -e B=2 -v app-shop-data:/data --entrypoint sh ghcr.io/acme/shop:1 -c php artisan queue:prune"
	if got != want {
		t.Fatalf("got  %s\nwant %s", got, want)
	}
	for _, bad := range []string{
		`{"run_id":"r1","mode":"oneoff","image":"x","mounts":[{"source":"/etc","target":"/e"}],"command":"x","user":"1000"}`,
		`{"mode":"oneoff","image":"x","command":"x","user":"1000"}`,
		`{"run_id":"r1","mode":"oneoff","image":"x","env":{"A B":"1"},"command":"x","user":"1000"}`,
		`{"run_id":"r1","mode":"other","command":"x","user":"1000"}`,
	} {
		if _, err := e.cronExec(context.Background(), json.RawMessage(bad)); err == nil {
			t.Errorf("%s must be refused", bad)
		}
	}
}

func TestCronHostRunsAShellOnTheServer(t *testing.T) {
	var got string
	e := &Executor{Run: func(_ context.Context, _ string, _ []string, name string, args ...string) ([]byte, error) {
		got = name + " " + strings.Join(args, " ")
		return []byte("done"), nil
	}}
	if _, err := e.cronExec(context.Background(), json.RawMessage(`{"mode":"host","command":"df -h"}`)); err != nil {
		t.Fatal(err)
	}
	if got != "sh -c df -h" {
		t.Fatalf("got %s", got)
	}
}

func TestCronStopEndsARunInProgress(t *testing.T) {
	started := make(chan struct{})
	var calls []string
	e := &Executor{Run: func(ctx context.Context, _ string, _ []string, name string, args ...string) ([]byte, error) {
		calls = append(calls, name+" "+strings.Join(args, " "))
		if len(calls) == 1 {
			close(started)
			<-ctx.Done()
			return nil, ctx.Err()
		}
		return nil, nil
	}}
	done := make(chan map[string]any)
	go func() {
		out, _ := e.cronExec(context.Background(), json.RawMessage(`{"run_id":"r9","mode":"oneoff","image":"img","command":"sleep 999","user":"1000"}`))
		done <- out.(map[string]any)
	}()
	<-started
	out, err := e.cronStop(context.Background(), json.RawMessage(`{"run_id":"r9","mode":"oneoff"}`))
	if err != nil || out.(map[string]any)["stopped"] != true {
		t.Fatalf("%v %v", out, err)
	}
	res := <-done
	if res["stopped"] != true || res["exit_code"] != 143 {
		t.Fatalf("got %v", res)
	}
	if calls[len(calls)-1] != "docker rm -f ws-cron-r9" {
		t.Fatalf("the one-off container must be removed: %v", calls)
	}
}
