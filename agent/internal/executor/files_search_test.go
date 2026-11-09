package executor

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"sort"
	"testing"
	"time"
)

func searchFor(t *testing.T, e *Executor, p map[string]any) ([]string, map[string]any, error) {
	t.Helper()
	p["volume"] = "site"
	raw, _ := json.Marshal(p)
	out, err := e.search(context.Background(), raw)
	if err != nil {
		return nil, nil, err
	}
	b, _ := json.Marshal(out)
	var m map[string]any
	json.Unmarshal(b, &m)
	var paths []string
	for _, h := range m["hits"].([]any) {
		paths = append(paths, h.(map[string]any)["path"].(string))
	}
	sort.Strings(paths)
	return paths, m, nil
}

func TestSearchByNameExtensionSizeAndDate(t *testing.T) {
	e, dir := fsExec(t)
	write(t, dir, "wp-content/themes/acme/style.css", "a{}")
	write(t, dir, "wp-content/themes/acme/functions.php", "<?php")
	write(t, dir, "wp-content/uploads/2026/backup.zip", string(make([]byte, 5000)))
	write(t, dir, "index.php", "<?php")
	old := time.Date(2025, 1, 1, 0, 0, 0, 0, time.UTC)
	os.Chtimes(filepath.Join(dir, "index.php"), old, old)

	if got, _, _ := searchFor(t, e, map[string]any{"name": "ACME"}); len(got) != 1 || got[0] != "wp-content/themes/acme" {
		t.Fatalf("name search is case-insensitive and includes folders: %v", got)
	}
	if got, _, _ := searchFor(t, e, map[string]any{"extensions": []string{".php"}}); len(got) != 2 {
		t.Fatalf("extension search: %v", got)
	}
	if got, _, _ := searchFor(t, e, map[string]any{"min_size": 1000}); len(got) != 1 || got[0] != "wp-content/uploads/2026/backup.zip" {
		t.Fatalf("size search: %v", got)
	}
	if got, _, _ := searchFor(t, e, map[string]any{"extensions": []string{"php"}, "modified_before": "2025-06-30"}); len(got) != 1 || got[0] != "index.php" {
		t.Fatalf("date search: %v", got)
	}
	if got, _, _ := searchFor(t, e, map[string]any{"extensions": []string{"php"}, "path": "wp-content"}); len(got) != 1 {
		t.Fatalf("search must start at the given folder: %v", got)
	}
	if _, _, err := searchFor(t, e, map[string]any{}); err == nil {
		t.Fatal("a search with no conditions must be refused")
	}
}

func TestSearchNeverFollowsLinksOutOfTheVolume(t *testing.T) {
	e, dir := fsExec(t)
	outside := t.TempDir()
	write(t, outside, "secret.php", "x")
	os.Symlink(outside, filepath.Join(dir, "escape"))
	got, _, err := searchFor(t, e, map[string]any{"name": "secret"})
	if err != nil || len(got) != 0 {
		t.Fatalf("search followed a symlink out of the volume: %v %v", got, err)
	}
}
