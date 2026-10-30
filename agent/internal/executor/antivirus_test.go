package executor

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func avExec(t *testing.T, clamOut string, clamErr error) (*Executor, string, *[]string) {
	t.Helper()
	e, dir := fsExec(t)
	e.StateDir = t.TempDir()
	calls := &[]string{}
	e.Run = func(_ context.Context, _ string, _ []string, name string, args ...string) ([]byte, error) {
		*calls = append(*calls, name+" "+strings.Join(args, " "))
		return []byte(strings.ReplaceAll(clamOut, "{dir}", dir)), clamErr
	}
	return e, dir, calls
}

func runAV(t *testing.T, e *Executor, typ string, p map[string]any) (map[string]any, error) {
	t.Helper()
	p["volume"] = "site"
	raw, _ := json.Marshal(p)
	out, err := e.antivirus(context.Background(), typ, raw)
	b, _ := json.Marshal(out)
	var m map[string]any
	json.Unmarshal(b, &m)
	return m, err
}

func TestScanReportsThreatsWithHashesAndSkipsAllowlisted(t *testing.T) {
	e, dir, calls := avExec(t, "{dir}/wp-content/uploads/shell.php: YARA.dmp_php_webshell.UNOFFICIAL FOUND\n{dir}/ok.php: Php.Trojan.Agent FOUND\n/etc/passwd: Fake FOUND\n", errors.New("exit status 1"))
	write(t, dir, "wp-content/uploads/shell.php", "<?php system($_GET['c']);")
	write(t, dir, "ok.php", "<?php echo 1;")
	allow, _ := hashIn(mustRoot(t, dir), "ok.php")

	out, err := runAV(t, e, "av.scan", map[string]any{"allowlist": []string{allow}})
	if err != nil {
		t.Fatal(err)
	}
	threats := out["threats"].([]any)
	if len(threats) != 1 {
		t.Fatalf("want one threat (allow-listed and outside-volume lines dropped), got %v", threats)
	}
	th := threats[0].(map[string]any)
	if th["path"] != "wp-content/uploads/shell.php" || th["signature"] != "YARA.dmp_php_webshell.UNOFFICIAL" || len(th["sha256"].(string)) != 64 {
		t.Fatalf("unexpected threat %v", th)
	}
	if !strings.Contains((*calls)[0], "docker exec clamav clamdscan --multiscan --infected --no-summary --stdout "+dir) {
		t.Fatalf("unexpected clamdscan call %q", (*calls)[0])
	}
}

func TestScanErrorsAreReported(t *testing.T) {
	e, _, _ := avExec(t, "ERROR: Could not connect to clamd on LocalSocket /run/clamav/clamd.sock", errors.New("exit status 2"))
	if _, err := runAV(t, e, "av.scan", map[string]any{}); err == nil || !strings.Contains(err.Error(), "clamd") {
		t.Fatalf("a scanner error must not look like a clean scan: %v", err)
	}
}

func TestQuarantineRestoreAndDelete(t *testing.T) {
	e, dir, _ := avExec(t, "", nil)
	write(t, dir, "wp-content/uploads/shell.php", "evil")
	os.Chmod(filepath.Join(dir, "wp-content/uploads/shell.php"), 0o755)

	if _, err := runAV(t, e, "av.quarantine", map[string]any{"path": "wp-content/uploads/shell.php", "id": "t-1"}); err != nil {
		t.Fatal(err)
	}
	if read(dir, "wp-content/uploads/shell.php") != "<missing>" {
		t.Fatal("a quarantined file must leave the volume")
	}
	fi, _ := os.Stat(filepath.Join(e.StateDir, "quarantine", "t-1", "file.enc"))
	if fi == nil || fi.Mode().Perm() != 0 {
		t.Fatal("a quarantined file must have no permissions")
	}
	os.Chmod(filepath.Join(e.StateDir, "quarantine", "t-1", "file.enc"), 0o400)
	stored, _ := os.ReadFile(filepath.Join(e.StateDir, "quarantine", "t-1", "file.enc"))
	os.Chmod(filepath.Join(e.StateDir, "quarantine", "t-1", "file.enc"), 0)
	if bytes.Contains(stored, []byte("evil")) || !bytes.HasPrefix(stored, []byte("WSQ1")) {
		t.Fatal("a quarantined file must be stored encrypted (AV-5)")
	}
	if _, err := runAV(t, e, "av.quarantine", map[string]any{"path": "x", "id": "../../etc"}); err == nil {
		t.Fatal("quarantine ids must not be paths")
	}

	if _, err := runAV(t, e, "av.restore", map[string]any{"id": "t-1"}); err != nil {
		t.Fatal(err)
	}
	if read(dir, "wp-content/uploads/shell.php") != "evil" {
		t.Fatal("restore must put the file back")
	}
	if fi, _ := os.Stat(filepath.Join(dir, "wp-content/uploads/shell.php")); fi.Mode().Perm() != 0o755 {
		t.Fatal("restore must bring back the original permissions")
	}

	runAV(t, e, "av.quarantine", map[string]any{"path": "wp-content/uploads/shell.php", "id": "t-2"})
	if _, err := runAV(t, e, "av.delete", map[string]any{"id": "t-2"}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(e.StateDir, "quarantine", "t-2")); err == nil {
		t.Fatal("delete must remove the quarantined file")
	}
}

func TestRestoreRefusesAnotherVolumesItem(t *testing.T) {
	e, dir, _ := avExec(t, "", nil)
	write(t, dir, "a.php", "x")
	runAV(t, e, "av.quarantine", map[string]any{"path": "a.php", "id": "t-9"})
	raw, _ := json.Marshal(map[string]any{"volume": "other", "id": "t-9"})
	e.VolumeRoot = func(context.Context, string) (string, error) { return t.TempDir(), nil }
	if _, err := e.antivirus(context.Background(), "av.restore", raw); err == nil {
		t.Fatal("an item may only be restored into the volume it came from")
	}
}

func mustRoot(t *testing.T, dir string) *os.Root {
	r, err := os.OpenRoot(dir)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { r.Close() })
	return r
}

func TestQuarantineRefusesATamperedFile(t *testing.T) {
	e, dir, _ := avExec(t, "", nil)
	write(t, dir, "shell.php", "evil payload")
	if _, err := runAV(t, e, "av.quarantine", map[string]any{"path": "shell.php", "id": "t-9"}); err != nil {
		t.Fatal(err)
	}
	enc := filepath.Join(e.StateDir, "quarantine", "t-9", "file.enc")
	os.Chmod(enc, 0o600)
	b, _ := os.ReadFile(enc)
	b[len(b)/2] ^= 0xff
	os.WriteFile(enc, b, 0o600)
	if _, err := runAV(t, e, "av.restore", map[string]any{"id": "t-9"}); err == nil {
		t.Fatal("a tampered quarantine file must not be restored")
	}
	if read(dir, "shell.php") != "<missing>" {
		t.Fatal("a failed restore must not leave a partial file")
	}
}

func TestRestoresItemsQuarantinedBeforeEncryption(t *testing.T) {
	e, dir, _ := avExec(t, "", nil)
	q := filepath.Join(e.StateDir, "quarantine", "old-1")
	os.MkdirAll(q, 0o700)
	os.WriteFile(filepath.Join(q, "file"), []byte("legacy"), 0)
	os.WriteFile(filepath.Join(q, "meta.json"), []byte(`{"volume":"site","path":"a.php","mode":420}`), 0o600)
	if _, err := runAV(t, e, "av.restore", map[string]any{"id": "old-1"}); err != nil {
		t.Fatal(err)
	}
	if read(dir, "a.php") != "legacy" {
		t.Fatal("a plain quarantine item must still restore")
	}
}
