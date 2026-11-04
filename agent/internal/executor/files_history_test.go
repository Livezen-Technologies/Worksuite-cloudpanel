package executor

import (
	"context"
	"encoding/json"
	"testing"
	"time"
)

func runHistory(t *testing.T, e *Executor, typ string, p map[string]any) (map[string]any, error) {
	t.Helper()
	p["volume"] = "site"
	raw, _ := json.Marshal(p)
	out, err := e.history(context.Background(), typ, raw)
	b, _ := json.Marshal(out)
	var m map[string]any
	json.Unmarshal(b, &m)
	return m, err
}

func TestEditsKeepVersionsThatCanBeRestored(t *testing.T) {
	e, dir := fsExec(t)
	e.StateDir = t.TempDir()
	clock := time.Date(2026, 10, 30, 1, 0, 0, 0, time.UTC)
	e.Now = func() time.Time { clock = clock.Add(time.Second); return clock }
	write(t, dir, "wp-config.php", "v1")
	for _, c := range []string{"v2", "v3"} {
		if _, err := runFS(t, e, "fs.write", map[string]any{"path": "wp-config.php", "content": c}); err != nil {
			t.Fatal(err)
		}
	}
	list, err := runHistory(t, e, "fs.history_list", map[string]any{"path": "wp-config.php"})
	if err != nil {
		t.Fatal(err)
	}
	vs := list["versions"].([]any)
	if len(vs) != 2 || vs[0].(map[string]any)["reason"] != "edit" {
		t.Fatalf("expected two edit versions, newest first: %v", vs)
	}
	oldest := vs[1].(map[string]any)["id"].(string)
	got, _ := runHistory(t, e, "fs.history_read", map[string]any{"path": "wp-config.php", "id": oldest})
	if got["content"] != "v1" {
		t.Fatalf("oldest version should be v1, got %v", got)
	}
	if _, err := runHistory(t, e, "fs.history_restore", map[string]any{"path": "wp-config.php", "id": oldest}); err != nil {
		t.Fatal(err)
	}
	if read(dir, "wp-config.php") != "v1" {
		t.Fatal("restore did not bring v1 back")
	}
	list, _ = runHistory(t, e, "fs.history_list", map[string]any{"path": "wp-config.php"})
	if vs := list["versions"].([]any); vs[0].(map[string]any)["reason"] != "restore" {
		t.Fatalf("a restore must keep what it replaced so it can be undone: %v", vs)
	}
	if _, err := runHistory(t, e, "fs.history_read", map[string]any{"path": "wp-config.php", "id": "../../etc/passwd"}); err == nil {
		t.Fatal("version ids must not be paths")
	}
}

func TestDeletedFileCanBeRestoredFromHistoryAndHistoryIsCapped(t *testing.T) {
	e, dir := fsExec(t)
	e.StateDir = t.TempDir()
	clock := time.Date(2026, 10, 30, 1, 0, 0, 0, time.UTC)
	e.Now = func() time.Time { clock = clock.Add(time.Second); return clock }
	write(t, dir, "index.php", "0")
	for i := 0; i < HistoryKeep+5; i++ {
		runFS(t, e, "fs.write", map[string]any{"path": "index.php", "content": "x"})
	}
	if list, _ := runHistory(t, e, "fs.history_list", map[string]any{"path": "index.php"}); len(list["versions"].([]any)) != HistoryKeep {
		t.Fatalf("history must keep at most %d versions", HistoryKeep)
	}
	if _, err := runFS(t, e, "fs.delete", map[string]any{"path": "index.php"}); err != nil {
		t.Fatal(err)
	}
	list, _ := runHistory(t, e, "fs.history_list", map[string]any{"path": "index.php"})
	newest := list["versions"].([]any)[0].(map[string]any)
	if newest["reason"] != "delete" {
		t.Fatalf("a permanent delete must keep a version: %v", newest)
	}
	runHistory(t, e, "fs.history_restore", map[string]any{"path": "index.php", "id": newest["id"]})
	if read(dir, "index.php") != "x" {
		t.Fatal("a deleted file must be restorable from history")
	}
}
