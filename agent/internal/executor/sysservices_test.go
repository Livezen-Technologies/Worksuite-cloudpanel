package executor

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestParseSystemctlShow(t *testing.T) {
	rows := ParseSystemctlShow("Id=nginx.service\nDescription=nginx\nActiveState=active\nSubState=running\nUnitFileState=enabled\nMainPID=12\nMemoryCurrent=1048576\nNRestarts=2\n\nId=ws-api.service\nActiveState=failed\nMemoryCurrent=[not set]\n")
	if len(rows) != 2 || rows[0]["memory_bytes"] != int64(1048576) || rows[0]["restarts"] != 2 || rows[0]["pid"] != 12 {
		t.Fatalf("%v", rows)
	}
	if _, ok := rows[1]["memory_bytes"]; ok || rows[1]["custom"] != true || rows[1]["active"] != "failed" {
		t.Fatalf("%v", rows[1])
	}
}

func TestRenderUnitRefusesUnsafeInput(t *testing.T) {
	ok, err := RenderUnit(svcCreate{Name: "api", ExecStart: "/usr/bin/node server.js", WorkingDir: "/srv/api", User: "app", Env: map[string]string{"PORT": "3000"}})
	if err != nil || !strings.Contains(ok, "ExecStart=/usr/bin/node server.js") || !strings.Contains(ok, `Environment="PORT=3000"`) || !strings.Contains(ok, "User=app") {
		t.Fatalf("%v\n%s", err, ok)
	}
	for _, bad := range []svcCreate{
		{Name: "API", ExecStart: "/bin/x"}, {Name: "a", ExecStart: "node x"}, {Name: "a", ExecStart: "/bin/x\nExecStartPre=/bin/rm"},
		{Name: "a", ExecStart: "/bin/x", User: "root"}, {Name: "a", ExecStart: "/bin/x", Restart: "maybe"},
		{Name: "a", ExecStart: "/bin/x", Env: map[string]string{"A": "1\nUser=root"}},
	} {
		if _, err := RenderUnit(bad); err == nil {
			t.Errorf("%+v must be refused", bad)
		}
	}
}

func TestSvcActionAndCreate(t *testing.T) {
	var calls []string
	e := &Executor{UnitDir: t.TempDir(), Run: func(_ context.Context, _ string, _ []string, name string, args ...string) ([]byte, error) {
		calls = append(calls, name+" "+strings.Join(args, " "))
		if len(args) > 0 && args[0] == "show" {
			return []byte("Id=" + args[len(args)-1] + "\nActiveState=active\n"), nil
		}
		return nil, nil
	}}
	nop := func(int, string) {}
	if _, err := e.systemServices(context.Background(), "svc.action", json.RawMessage(`{"unit":"ssh.service","action":"stop"}`), nop); err == nil {
		t.Fatal("stopping ssh must be refused")
	}
	if _, err := e.systemServices(context.Background(), "svc.action", json.RawMessage(`{"unit":"x;rm.service","action":"start"}`), nop); err == nil {
		t.Fatal("bad unit must be refused")
	}
	if _, err := e.systemServices(context.Background(), "svc.action", json.RawMessage(`{"unit":"nginx.service","action":"restart"}`), nop); err != nil || calls[0] != "systemctl restart nginx.service" {
		t.Fatalf("%v %v", err, calls)
	}
	out, err := e.systemServices(context.Background(), "svc.create", json.RawMessage(`{"name":"worker","exec_start":"/usr/bin/php artisan queue:work","start":true}`), nop)
	if err != nil || out.(map[string]any)["unit"] != "ws-worker.service" {
		t.Fatalf("%v %v", out, err)
	}
	if _, err := os.Stat(filepath.Join(e.UnitDir, "ws-worker.service")); err != nil {
		t.Fatal(err)
	}
	if _, err := e.systemServices(context.Background(), "svc.delete", json.RawMessage(`{"unit":"nginx.service"}`), nop); err == nil {
		t.Fatal("only ws-* units can be deleted")
	}
}
