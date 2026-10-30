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
	if strings.Join(got, " ") != "docker exec -u 33:33 app-shop sh -c php artisan schedule:run" {
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
