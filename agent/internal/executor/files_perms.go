package executor

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path"
	"strconv"
)

// Permissions and ownership (FM-009, FM-010). Changes are made on handles
// opened through the volume's os.Root, so a symlink can never redirect them to
// a file outside the volume; symlinks themselves are skipped.

type permsPayload struct {
	Volume    string   `json:"volume"`
	Paths     []string `json:"paths"`
	Mode      string   `json:"mode"`
	UID       *int     `json:"uid"`
	GID       *int     `json:"gid"`
	Recursive bool     `json:"recursive"`
}

// parseMode accepts the nine rwx bits only ("644", "0755"); setuid, setgid
// and sticky are refused because they change what a file can do, not who can use it.
func parseMode(s string) (fs.FileMode, error) {
	v, err := strconv.ParseUint(s, 8, 32)
	if err != nil || v > 0o777 {
		return 0, fmt.Errorf("invalid permissions %q: use three octal digits such as 644", s)
	}
	return fs.FileMode(v), nil
}

func (e *Executor) perms(ctx context.Context, typ string, raw json.RawMessage) (any, error) {
	var p permsPayload
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if len(p.Paths) == 0 {
		return nil, errors.New("nothing selected")
	}
	var apply func(f *os.File) error
	switch typ {
	case "fs.chmod":
		mode, err := parseMode(p.Mode)
		if err != nil {
			return nil, err
		}
		apply = func(f *os.File) error { return f.Chmod(mode) }
	case "fs.chown":
		if p.UID == nil && p.GID == nil {
			return nil, errors.New("an owner or a group is required")
		}
		uid, gid := -1, -1 // -1 leaves that half unchanged
		if p.UID != nil {
			uid = *p.UID
		}
		if p.GID != nil {
			gid = *p.GID
		}
		if uid < -1 || uid > 1<<31-2 || gid < -1 || gid > 1<<31-2 {
			return nil, errors.New("invalid owner or group id")
		}
		apply = func(f *os.File) error { return f.Chown(uid, gid) }
	default:
		return nil, fmt.Errorf("unknown file command %s", typ)
	}
	dir, err := e.volumeRoot(ctx, p.Volume)
	if err != nil {
		return nil, err
	}
	root, err := os.OpenRoot(dir)
	if err != nil {
		return nil, err
	}
	defer root.Close()
	changed, skipped := 0, 0
	for _, raw := range p.Paths {
		rel, err := cleanRel(raw)
		if err != nil {
			return nil, err
		}
		c, s, err := applyTree(root, rel, p.Recursive, apply)
		changed, skipped = changed+c, skipped+s
		if err != nil {
			return map[string]any{"changed": changed}, fmt.Errorf("%s: %w", rel, err)
		}
	}
	return map[string]any{"changed": changed, "skipped_links": skipped}, nil
}

func applyTree(root *os.Root, rel string, recursive bool, apply func(*os.File) error) (changed, skipped int, err error) {
	fi, err := root.Lstat(rel)
	if err != nil {
		return 0, 0, err
	}
	if fi.Mode()&fs.ModeSymlink != 0 {
		return 0, 1, nil
	}
	f, err := root.Open(rel)
	if err != nil {
		return 0, 0, err
	}
	err = apply(f)
	var des []fs.DirEntry
	if err == nil && recursive && fi.IsDir() {
		des, err = f.ReadDir(-1)
	}
	f.Close()
	if err != nil {
		return 0, 0, err
	}
	changed = 1
	for _, de := range des {
		c, s, err := applyTree(root, path.Join(rel, de.Name()), true, apply)
		changed, skipped = changed+c, skipped+s
		if err != nil {
			return changed, skipped, err
		}
	}
	return changed, skipped, nil
}
