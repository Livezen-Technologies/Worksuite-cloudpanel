package executor

import (
	"archive/tar"
	"archive/zip"
	"bytes"
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func runOps(t *testing.T, e *Executor, typ string, p map[string]any) (map[string]any, error) {
	t.Helper()
	p["volume"] = "site"
	raw, _ := json.Marshal(p)
	out, err := e.fileOps(context.Background(), typ, raw)
	b, _ := json.Marshal(out)
	var m map[string]any
	_ = json.Unmarshal(b, &m)
	return m, err
}

func write(t *testing.T, dir, rel, content string) {
	t.Helper()
	os.MkdirAll(filepath.Dir(filepath.Join(dir, rel)), 0o755)
	if err := os.WriteFile(filepath.Join(dir, rel), []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
}

func read(dir, rel string) string {
	b, err := os.ReadFile(filepath.Join(dir, rel))
	if err != nil {
		return "<missing>"
	}
	return string(b)
}

func TestTrashMovesOutOfTheVolumeAndRestores(t *testing.T) {
	e, dir := fsExec(t)
	e.StateDir = t.TempDir()
	write(t, dir, "wp-content/themes/old/style.css", "a{}")
	out, err := runOps(t, e, "fs.trash", map[string]any{"paths": []string{"wp-content/themes/old"}})
	if err != nil {
		t.Fatal(err)
	}
	if read(dir, "wp-content/themes/old/style.css") != "<missing>" {
		t.Fatal("a trashed folder must leave the volume, so the site cannot serve it")
	}
	id := out["items"].([]any)[0].(map[string]any)["id"].(string)
	list, _ := runOps(t, e, "fs.trash_list", map[string]any{})
	if items := list["items"].([]any); len(items) != 1 || items[0].(map[string]any)["path"] != "wp-content/themes/old" {
		t.Fatalf("trash list: %v", list)
	}
	if _, err := runOps(t, e, "fs.trash_restore", map[string]any{"ids": []string{id}}); err != nil {
		t.Fatal(err)
	}
	if read(dir, "wp-content/themes/old/style.css") != "a{}" {
		t.Fatal("restore must put the folder back where it was")
	}
	if list, _ := runOps(t, e, "fs.trash_list", map[string]any{}); len(list["items"].([]any)) != 0 {
		t.Fatal("a restored item must leave the trash")
	}
}

func TestTrashRefusesToRestoreOverAnExistingFileAndExpires(t *testing.T) {
	e, dir := fsExec(t)
	e.StateDir = t.TempDir()
	now := time.Date(2026, 10, 1, 12, 0, 0, 0, time.UTC)
	e.Now = func() time.Time { return now }
	write(t, dir, "index.php", "old")
	out, _ := runOps(t, e, "fs.trash", map[string]any{"paths": []string{"index.php"}})
	id := out["items"].([]any)[0].(map[string]any)["id"].(string)
	write(t, dir, "index.php", "new")
	if _, err := runOps(t, e, "fs.trash_restore", map[string]any{"ids": []string{id}}); err == nil {
		t.Fatal("restoring over a newer file must be refused")
	}
	if read(dir, "index.php") != "new" {
		t.Fatal("the newer file was overwritten")
	}
	if _, err := runOps(t, e, "fs.trash_restore", map[string]any{"ids": []string{"../../etc"}}); err == nil {
		t.Fatal("trash ids must not be paths")
	}
	now = now.Add(8 * 24 * time.Hour)
	if list, _ := runOps(t, e, "fs.trash_list", map[string]any{}); len(list["items"].([]any)) != 0 {
		t.Fatal("items older than 7 days must be purged")
	}
}

func TestCopyAndMove(t *testing.T) {
	e, dir := fsExec(t)
	outside := t.TempDir()
	write(t, outside, "secret", "s")
	write(t, dir, "a/one.txt", "1")
	write(t, dir, "a/sub/two.txt", "2")
	os.Symlink(outside, filepath.Join(dir, "a", "link"))
	os.Mkdir(filepath.Join(dir, "b"), 0o755)

	out, err := runOps(t, e, "fs.copy", map[string]any{"paths": []string{"a"}, "dest": "b"})
	if err != nil || out["files"].(float64) != 2 || out["skipped_links"].(float64) != 1 {
		t.Fatalf("copy: %v %v", out, err)
	}
	if read(dir, "b/a/sub/two.txt") != "2" {
		t.Fatal("folder copy missed a nested file")
	}
	if _, err := os.Lstat(filepath.Join(dir, "b", "a", "link")); err == nil {
		t.Fatal("copy must not copy symlinks (they could point outside)")
	}
	if _, err := runOps(t, e, "fs.copy", map[string]any{"paths": []string{"a/one.txt"}, "dest": "a"}); err != nil || read(dir, "a/one (copy).txt") != "1" {
		t.Fatalf("copy into the same folder must make a (copy): %v", err)
	}
	if _, err := runOps(t, e, "fs.move", map[string]any{"paths": []string{"a"}, "dest": "a/sub"}); err == nil {
		t.Fatal("a folder cannot be moved into itself")
	}
	if _, err := runOps(t, e, "fs.move", map[string]any{"paths": []string{"a/one.txt"}, "dest": "b"}); err != nil {
		t.Fatal(err)
	}
	if read(dir, "b/one.txt") != "1" || read(dir, "a/one.txt") != "<missing>" {
		t.Fatal("move did not move")
	}
	if _, err := runOps(t, e, "fs.move", map[string]any{"paths": []string{"b/one.txt"}, "dest": "a/link"}); err == nil {
		t.Fatal("a move must not follow a symlink out of the volume")
	}
}

func TestCompressAndExtractRoundTrip(t *testing.T) {
	for _, format := range []string{"zip", "tar", "tar.gz"} {
		e, dir := fsExec(t)
		write(t, dir, "site/index.html", "<h1>hi</h1>")
		write(t, dir, "site/css/a.css", "a{}")
		out, err := runOps(t, e, "fs.compress", map[string]any{"paths": []string{"site"}, "dest": "", "format": format})
		if err != nil {
			t.Fatalf("%s: %v", format, err)
		}
		archive := out["path"].(string)
		if archive != "site."+format || out["files"].(float64) != 2 {
			t.Fatalf("%s: %v", format, out)
		}
		os.Mkdir(filepath.Join(dir, "restore"), 0o755)
		if _, err := runOps(t, e, "fs.extract", map[string]any{"path": archive, "dest": "restore"}); err != nil {
			t.Fatalf("%s extract: %v", format, err)
		}
		if read(dir, "restore/site/css/a.css") != "a{}" {
			t.Fatalf("%s: round trip lost a file", format)
		}
		if _, err := runOps(t, e, "fs.extract", map[string]any{"path": archive, "dest": "restore"}); err == nil {
			t.Fatalf("%s: extracting over existing files must need overwrite", format)
		}
	}
}

func TestExtractRefusesEntriesOutsideTheFolder(t *testing.T) {
	e, dir := fsExec(t)
	var zb bytes.Buffer
	zw := zip.NewWriter(&zb)
	w, _ := zw.Create("../../evil.php")
	w.Write([]byte("<?php system($_GET[1]);"))
	zw.Close()
	os.WriteFile(filepath.Join(dir, "slip.zip"), zb.Bytes(), 0o644)
	if _, err := runOps(t, e, "fs.extract", map[string]any{"path": "slip.zip", "dest": ""}); err == nil {
		t.Fatal("a ../ entry must be refused")
	}

	var tb bytes.Buffer
	tw := tar.NewWriter(&tb)
	tw.WriteHeader(&tar.Header{Name: "link", Typeflag: tar.TypeSymlink, Linkname: "/etc/passwd"})
	tw.WriteHeader(&tar.Header{Name: "ok.txt", Typeflag: tar.TypeReg, Mode: 0o644, Size: 2})
	tw.Write([]byte("ok"))
	tw.Close()
	os.WriteFile(filepath.Join(dir, "links.tar"), tb.Bytes(), 0o644)
	if _, err := runOps(t, e, "fs.extract", map[string]any{"path": "links.tar", "dest": ""}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Lstat(filepath.Join(dir, "link")); err == nil {
		t.Fatal("symlink entries must be skipped")
	}
	if read(dir, "ok.txt") != "ok" {
		t.Fatal("regular entries must still be extracted")
	}
}
