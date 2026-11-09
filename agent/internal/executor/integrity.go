package executor

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

// File integrity monitoring (AV-10). The agent hashes a volume's code files
// (PHP, JavaScript, HTML, .htaccess and the like) into a baseline kept in its
// state directory, outside the volume. A later check reports what was added,
// changed or removed since. The panel takes a baseline after each deployment,
// so a change found by a check happened outside one.

// IntegrityMaxFiles caps how many files one baseline holds.
const IntegrityMaxFiles = 200000

var integrityExt = map[string]bool{
	".php": true, ".phtml": true, ".php5": true, ".php7": true, ".phar": true, ".inc": true,
	".js": true, ".mjs": true, ".cjs": true, ".html": true, ".htm": true, ".py": true, ".pl": true,
	".cgi": true, ".sh": true, ".rb": true, ".asp": true, ".aspx": true, ".jsp": true,
}

type integrityPayload struct {
	Volume string `json:"volume"`
}

type integrityBaseline struct {
	At    string            `json:"at"`
	Files map[string]string `json:"files"` // path => sha256
}

func isCodeFile(rel string) bool {
	base := strings.ToLower(path.Base(rel))
	return base == ".htaccess" || base == ".user.ini" || integrityExt[path.Ext(base)]
}

func (e *Executor) integrityFile(volume string) (string, error) {
	if e.StateDir == "" {
		return "", errors.New("the agent has no state directory for integrity baselines")
	}
	dir := filepath.Join(e.StateDir, "integrity")
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return "", err
	}
	return filepath.Join(dir, volume+".json"), nil
}

func (e *Executor) integrity(ctx context.Context, typ string, raw json.RawMessage) (any, error) {
	var p integrityPayload
	if err := decode(raw, &p); err != nil {
		return nil, err
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
	file, err := e.integrityFile(p.Volume)
	if err != nil {
		return nil, err
	}
	current, truncated, err := hashCode(ctx, root)
	if err != nil {
		return nil, err
	}
	if typ == "fim.baseline" {
		b, _ := json.Marshal(integrityBaseline{At: e.now().UTC().Format(time.RFC3339), Files: current})
		tmp := file + ".tmp"
		if err := os.WriteFile(tmp, b, 0o600); err != nil {
			return nil, err
		}
		if err := os.Rename(tmp, file); err != nil {
			return nil, err
		}
		return map[string]any{"files": len(current), "truncated": truncated}, nil
	}
	b, err := os.ReadFile(file)
	if errors.Is(err, os.ErrNotExist) {
		return nil, errors.New("this volume has no integrity baseline yet")
	} else if err != nil {
		return nil, err
	}
	var base integrityBaseline
	if err := json.Unmarshal(b, &base); err != nil {
		return nil, fmt.Errorf("the integrity baseline is damaged: %w", err)
	}
	added, changed, removed := []string{}, []string{}, []string{}
	for rel, sum := range current {
		old, ok := base.Files[rel]
		switch {
		case !ok:
			added = append(added, rel)
		case old != sum:
			changed = append(changed, rel)
		}
	}
	for rel := range base.Files {
		if _, ok := current[rel]; !ok {
			removed = append(removed, rel)
		}
	}
	sort.Strings(added)
	sort.Strings(changed)
	sort.Strings(removed)
	return map[string]any{"added": added, "changed": changed, "removed": removed, "files": len(current), "baseline_at": base.At, "truncated": truncated}, nil
}

// hashCode hashes every code file in the volume through the root, never
// following symlinks.
func hashCode(ctx context.Context, root *os.Root) (map[string]string, bool, error) {
	out := map[string]string{}
	truncated := false
	var walk func(rel string) error
	walk = func(rel string) error {
		if ctx.Err() != nil {
			return ctx.Err()
		}
		d, err := root.Open(rel)
		if err != nil {
			return err
		}
		des, err := d.ReadDir(-1)
		d.Close()
		if err != nil {
			return err
		}
		for _, de := range des {
			child := path.Join(rel, de.Name())
			if rel == "." {
				child = de.Name()
			}
			switch {
			case de.Type()&fs.ModeSymlink != 0:
				continue
			case de.IsDir():
				if err := walk(child); err != nil {
					return err
				}
			case de.Type().IsRegular() && isCodeFile(child):
				if len(out) >= IntegrityMaxFiles {
					truncated = true
					return nil
				}
				f, err := root.Open(child)
				if err != nil {
					continue // vanished or unreadable; the next check sees it again
				}
				h := sha256.New()
				_, err = io.Copy(h, f)
				f.Close()
				if err == nil {
					out[child] = hex.EncodeToString(h.Sum(nil))
				}
			}
		}
		return nil
	}
	return out, truncated, walk(".")
}
