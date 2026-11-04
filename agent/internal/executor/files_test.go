package executor

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// fsExec returns an executor whose volume "site" is a temp dir, plus that dir.
func fsExec(t *testing.T) (*Executor, string) {
	t.Helper()
	dir := t.TempDir()
	e := &Executor{VolumeRoot: func(_ context.Context, name string) (string, error) { return dir, nil }}
	return e, dir
}

func runFS(t *testing.T, e *Executor, typ string, p map[string]any) (map[string]any, error) {
	t.Helper()
	p["volume"] = "site"
	raw, _ := json.Marshal(p)
	out, err := e.files(context.Background(), typ, raw)
	if err != nil {
		return nil, err
	}
	b, _ := json.Marshal(out)
	var m map[string]any
	_ = json.Unmarshal(b, &m)
	return m, nil
}

func TestFilesListSortsFoldersFirst(t *testing.T) {
	e, dir := fsExec(t)
	os.WriteFile(filepath.Join(dir, "a.txt"), []byte("hi"), 0o644)
	os.Mkdir(filepath.Join(dir, "z-dir"), 0o755)
	out, err := runFS(t, e, "fs.list", map[string]any{"path": "/"})
	if err != nil {
		t.Fatal(err)
	}
	entries := out["entries"].([]any)
	if len(entries) != 2 || entries[0].(map[string]any)["name"] != "z-dir" || entries[1].(map[string]any)["size"].(float64) != 2 {
		t.Fatalf("unexpected listing: %v", entries)
	}
}

func TestFilesRefusePathsOutsideTheVolume(t *testing.T) {
	e, dir := fsExec(t)
	outside := t.TempDir()
	os.WriteFile(filepath.Join(outside, "secret"), []byte("x"), 0o600)
	os.Symlink(outside, filepath.Join(dir, "escape"))
	os.Symlink(filepath.Join(outside, "secret"), filepath.Join(dir, "link"))

	// ".." is cleaned away, so this reads the volume's own top level.
	if out, err := runFS(t, e, "fs.list", map[string]any{"path": "../../"}); err != nil || out["path"] != "." {
		t.Fatalf("../ must stay at the volume root: %v %v", out, err)
	}
	for _, c := range []struct{ typ, path string }{
		{"fs.list", "escape"},
		{"fs.read", "link"},
		{"fs.read", "escape/secret"},
		{"fs.write", "escape/new.txt"},
		{"fs.mkdir", "escape/sub"},
	} {
		if _, err := runFS(t, e, c.typ, map[string]any{"path": c.path, "content": "x"}); err == nil {
			t.Errorf("%s %s followed a symlink out of the volume", c.typ, c.path)
		}
	}
	if _, err := os.Stat(filepath.Join(outside, "new.txt")); err == nil {
		t.Fatal("a file was written outside the volume")
	}
}

func TestFilesWriteReadAndRefuseBinary(t *testing.T) {
	e, dir := fsExec(t)
	if _, err := runFS(t, e, "fs.write", map[string]any{"path": "conf/app.ini", "content": "x=1\n", "create": true}); err == nil {
		t.Fatal("writing into a missing folder must fail")
	}
	if _, err := runFS(t, e, "fs.write", map[string]any{"path": "app.ini", "content": "x=1\n", "create": true}); err != nil {
		t.Fatal(err)
	}
	if _, err := runFS(t, e, "fs.write", map[string]any{"path": "app.ini", "content": "y", "create": true}); err == nil {
		t.Fatal("create must not overwrite an existing file")
	}
	os.Chmod(filepath.Join(dir, "app.ini"), 0o600)
	if _, err := runFS(t, e, "fs.write", map[string]any{"path": "app.ini", "content": "x=2\n"}); err != nil {
		t.Fatal(err)
	}
	out, err := runFS(t, e, "fs.read", map[string]any{"path": "app.ini"})
	if err != nil || out["content"] != "x=2\n" || out["mode"] != "0600" {
		t.Fatalf("read back %v %v; saving must keep content and permissions", out, err)
	}
	os.WriteFile(filepath.Join(dir, "logo.png"), []byte{0x89, 'P', 'N', 'G', 0, 1}, 0o644)
	if _, err := runFS(t, e, "fs.read", map[string]any{"path": "logo.png"}); err == nil || !strings.Contains(err.Error(), "not a text file") {
		t.Fatalf("binary files must be refused by the editor: %v", err)
	}
}

func TestFilesRenameValidatesNames(t *testing.T) {
	e, dir := fsExec(t)
	os.WriteFile(filepath.Join(dir, "a.txt"), nil, 0o644)
	os.WriteFile(filepath.Join(dir, "b.txt"), nil, 0o644)
	for _, bad := range []string{"", "..", "x/y", "b.txt"} {
		if _, err := runFS(t, e, "fs.rename", map[string]any{"path": "a.txt", "new_name": bad}); err == nil {
			t.Errorf("rename to %q must be refused", bad)
		}
	}
	if _, err := runFS(t, e, "fs.rename", map[string]any{"path": "a.txt", "new_name": "c.txt"}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(dir, "c.txt")); err != nil {
		t.Fatal("rename did not happen")
	}
}

func TestFilesDeleteNeedsRecursiveAndNeverFollowsLinks(t *testing.T) {
	e, dir := fsExec(t)
	outside := t.TempDir()
	os.WriteFile(filepath.Join(outside, "keep"), []byte("x"), 0o644)
	os.MkdirAll(filepath.Join(dir, "cache", "deep"), 0o755)
	os.WriteFile(filepath.Join(dir, "cache", "deep", "f"), []byte("x"), 0o644)
	os.Symlink(outside, filepath.Join(dir, "cache", "out"))

	if _, err := runFS(t, e, "fs.delete", map[string]any{"path": "cache"}); err == nil {
		t.Fatal("a non-empty folder must need recursive")
	}
	out, err := runFS(t, e, "fs.delete", map[string]any{"path": "cache", "recursive": true})
	if err != nil || out["removed"].(float64) != 4 {
		t.Fatalf("delete: %v %v", out, err)
	}
	if _, err := os.Stat(filepath.Join(outside, "keep")); err != nil {
		t.Fatal("deleting a folder followed a symlink and removed files outside the volume")
	}
	if _, err := runFS(t, e, "fs.delete", map[string]any{"path": "/", "recursive": true}); err == nil {
		t.Fatal("the volume root must never be deleted")
	}
}
