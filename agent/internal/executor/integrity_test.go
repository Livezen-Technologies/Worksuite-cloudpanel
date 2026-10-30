package executor

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

func runFIM(t *testing.T, e *Executor, typ string) (map[string]any, error) {
	t.Helper()
	out, err := e.integrity(context.Background(), typ, json.RawMessage(`{"volume":"site"}`))
	b, _ := json.Marshal(out)
	var m map[string]any
	_ = json.Unmarshal(b, &m)
	return m, err
}

func TestIntegrityReportsChangesSinceTheBaseline(t *testing.T) {
	e, dir := fsExec(t)
	e.StateDir = t.TempDir()
	write(t, dir, "index.php", "<?php echo 1;")
	write(t, dir, "wp-includes/load.php", "<?php // core")
	write(t, dir, "old.js", "x()")
	write(t, dir, "wp-content/uploads/photo.jpg", "jpg")

	if _, err := runFIM(t, e, "fim.check"); err == nil {
		t.Fatal("a check without a baseline must fail")
	}
	base, err := runFIM(t, e, "fim.baseline")
	if err != nil || base["files"].(float64) != 3 {
		t.Fatalf("baseline: %v %v", base, err)
	}
	if fi, _ := os.Stat(filepath.Join(e.StateDir, "integrity", "site.json")); fi == nil || fi.Mode().Perm() != 0o600 {
		t.Fatal("the baseline must be private to the agent")
	}

	write(t, dir, "wp-includes/load.php", "<?php eval($_POST['x']);")
	write(t, dir, "wp-content/uploads/shell.php", "<?php system($_GET['c']);")
	write(t, dir, "wp-content/uploads/photo2.jpg", "not code")
	os.Remove(filepath.Join(dir, "old.js"))
	os.Symlink("/etc/passwd", filepath.Join(dir, "link.php"))

	out, err := runFIM(t, e, "fim.check")
	if err != nil {
		t.Fatal(err)
	}
	want := map[string][]any{"added": {"wp-content/uploads/shell.php"}, "changed": {"wp-includes/load.php"}, "removed": {"old.js"}}
	for k, v := range want {
		got, _ := json.Marshal(out[k])
		exp, _ := json.Marshal(v)
		if string(got) != string(exp) {
			t.Fatalf("%s: got %s, want %s", k, got, exp)
		}
	}
}
