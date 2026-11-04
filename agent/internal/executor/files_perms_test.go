package executor

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

func runPerms(t *testing.T, e *Executor, typ string, p map[string]any) (map[string]any, error) {
	t.Helper()
	p["volume"] = "site"
	raw, _ := json.Marshal(p)
	out, err := e.perms(context.Background(), typ, raw)
	b, _ := json.Marshal(out)
	var m map[string]any
	_ = json.Unmarshal(b, &m)
	return m, err
}

func modeOf(t *testing.T, p string) os.FileMode {
	t.Helper()
	fi, err := os.Lstat(p)
	if err != nil {
		t.Fatal(err)
	}
	return fi.Mode().Perm()
}

func TestChmodRecursiveSkipsLinksAndStaysInside(t *testing.T) {
	e, dir := fsExec(t)
	outside := t.TempDir()
	secret := filepath.Join(outside, "secret")
	os.WriteFile(secret, []byte("s"), 0o600)
	write(t, dir, "uploads/a.jpg", "x")
	os.Symlink(secret, filepath.Join(dir, "uploads", "link"))

	out, err := runPerms(t, e, "fs.chmod", map[string]any{"paths": []string{"uploads"}, "mode": "750", "recursive": true})
	if err != nil || out["changed"].(float64) != 2 || out["skipped_links"].(float64) != 1 {
		t.Fatalf("chmod: %v %v", out, err)
	}
	if modeOf(t, filepath.Join(dir, "uploads", "a.jpg")) != 0o750 {
		t.Fatal("recursive chmod missed a file")
	}
	if modeOf(t, secret) != 0o600 {
		t.Fatal("chmod followed a symlink out of the volume")
	}
	if _, err := runPerms(t, e, "fs.chmod", map[string]any{"paths": []string{"../" + filepath.Base(outside) + "/secret"}, "mode": "777"}); err == nil && modeOf(t, secret) != 0o600 {
		t.Fatal("chmod reached outside the volume")
	}
}

func TestChmodRefusesSpecialBits(t *testing.T) {
	e, dir := fsExec(t)
	write(t, dir, "run.sh", "#!/bin/sh")
	for _, m := range []string{"4755", "2755", "1777", "rwx", "", "999"} {
		if _, err := runPerms(t, e, "fs.chmod", map[string]any{"paths": []string{"run.sh"}, "mode": m}); err == nil {
			t.Errorf("mode %q must be refused", m)
		}
	}
	if modeOf(t, filepath.Join(dir, "run.sh")) != 0o644 {
		t.Fatal("a refused mode changed the file")
	}
}

func TestChownToTheCurrentOwnerAndListingShowsOwners(t *testing.T) {
	e, dir := fsExec(t)
	write(t, dir, "index.php", "<?php")
	uid, gid := os.Getuid(), os.Getgid()
	if _, err := runPerms(t, e, "fs.chown", map[string]any{"paths": []string{"index.php"}, "uid": uid, "gid": gid}); err != nil {
		t.Fatal(err)
	}
	if _, err := runPerms(t, e, "fs.chown", map[string]any{"paths": []string{"index.php"}}); err == nil {
		t.Fatal("chown without an owner or group must be refused")
	}
	out, _ := runFS(t, e, "fs.list", map[string]any{"path": ""})
	en := out["entries"].([]any)[0].(map[string]any)
	if int(en["uid"].(float64)) != uid || int(en["gid"].(float64)) != gid {
		t.Fatalf("listing should show the owner: %v", en)
	}
}
