package executor

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func scanVolume(t *testing.T, e *Executor, p map[string]any) map[string]any {
	t.Helper()
	p["volume"] = "site"
	raw, _ := json.Marshal(p)
	out, err := e.scanFiles(context.Background(), raw)
	if err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(out)
	var m map[string]any
	json.Unmarshal(b, &m)
	return m
}

// rules returns path -> rules flagged for it.
func rules(m map[string]any) map[string][]string {
	out := map[string][]string{}
	for _, f := range m["findings"].([]any) {
		f := f.(map[string]any)
		out[f["path"].(string)] = append(out[f["path"].(string)], f["rule"].(string))
	}
	return out
}

func has(list []string, s string) bool {
	for _, x := range list {
		if x == s {
			return true
		}
	}
	return false
}

func TestScanFlagsTheUsualCompromiseSigns(t *testing.T) {
	e, dir := fsExec(t)
	now := time.Date(2026, 10, 30, 0, 0, 0, 0, time.UTC)
	e.Now = func() time.Time { return now }
	old := now.Add(-60 * 24 * time.Hour)

	write(t, dir, "wp-content/uploads/2026/10/thumb.php", `<?php eval(base64_decode("ZWNobyAxOw=="));`)
	write(t, dir, "wp-content/uploads/2026/10/photo.jpg.php", "<?php echo 1;")
	write(t, dir, "wp-includes/.cache.php", "<?php @system($_GET['c']);")
	write(t, dir, "wp-config.php", "<?php define('DB_PASSWORD','x');")
	write(t, dir, "wp-content/themes/acme/functions.php", "<?php add_action('init', 'acme');")
	write(t, dir, "wp-content/uploads/2026/10/photo.jpg", "jpeg")
	os.Chmod(filepath.Join(dir, "wp-content/themes/acme"), 0o777)
	for _, f := range []string{"wp-config.php", "wp-content/themes/acme/functions.php", "wp-content/uploads/2026/10/photo.jpg"} {
		os.Chtimes(filepath.Join(dir, f), old, old)
	}

	m := scanVolume(t, e, map[string]any{})
	r := rules(m)
	check := func(p, rule string) {
		t.Helper()
		if !has(r[p], rule) {
			t.Errorf("%s should be flagged %s, got %v", p, rule, r[p])
		}
	}
	check("wp-content/uploads/2026/10/thumb.php", "script_in_uploads")
	check("wp-content/uploads/2026/10/thumb.php", "malware_pattern")
	check("wp-content/uploads/2026/10/photo.jpg.php", "double_extension")
	check("wp-includes/.cache.php", "hidden_script")
	check("wp-includes/.cache.php", "malware_pattern")
	check("wp-config.php", "sensitive_readable")
	check("wp-content/themes/acme", "world_writable")
	check("wp-content/uploads/2026/10/thumb.php", "recently_changed")
	if len(r["wp-content/themes/acme/functions.php"]) != 0 || len(r["wp-content/uploads/2026/10/photo.jpg"]) != 0 {
		t.Errorf("clean, old files must not be flagged: %v", r)
	}
	counts := m["counts"].(map[string]any)
	if counts["critical"].(float64) != 2 {
		t.Errorf("expected 2 critical findings, got %v", counts)
	}
	if first := m["findings"].([]any)[0].(map[string]any)["severity"]; first != "critical" {
		t.Errorf("findings must be sorted worst first, got %v", first)
	}
}

func TestScanDoesNotFollowLinksOutOfTheVolume(t *testing.T) {
	e, dir := fsExec(t)
	outside := t.TempDir()
	write(t, outside, "uploads/evil.php", `<?php eval(base64_decode("x"));`)
	os.Symlink(outside, filepath.Join(dir, "escape"))
	if f := scanVolume(t, e, map[string]any{})["findings"].([]any); len(f) != 0 {
		t.Fatalf("scan followed a symlink out of the volume: %v", f)
	}
}
