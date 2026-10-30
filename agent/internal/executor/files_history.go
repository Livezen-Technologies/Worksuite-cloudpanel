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
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
	"unicode/utf8"
)

// File history (FM-014): before the file manager overwrites or permanently
// deletes a file, the agent keeps the previous content in its own state
// folder (never inside the volume, where the site could serve it).

const (
	HistoryKeep      = 20
	HistoryRetention = 30 * 24 * time.Hour
	MaxHistoryBytes  = 10 << 20
)

var reVersion = regexp.MustCompile(`^[0-9]{1,20}-[a-z-]{1,20}$`)

// FileVersion is one saved earlier version of a file.
type FileVersion struct {
	ID      string `json:"id"`
	SavedAt string `json:"saved_at"`
	Reason  string `json:"reason"` // edit|upload|delete|restore
	Size    int64  `json:"size"`
}

func (e *Executor) historyDir(volume, rel string) string {
	sum := sha256.Sum256([]byte(rel))
	return filepath.Join(e.StateDir, "history", volume, hex.EncodeToString(sum[:16]))
}

// snapshot keeps the current content of rel before it changes. It is best
// effort: a file manager write never fails because history could not be kept.
func (e *Executor) snapshot(volume string, root *os.Root, rel, reason string) {
	if e.StateDir == "" {
		return
	}
	fi, err := root.Lstat(rel)
	if err != nil || !fi.Mode().IsRegular() || fi.Size() > MaxHistoryBytes {
		return
	}
	in, err := root.Open(rel)
	if err != nil {
		return
	}
	defer in.Close()
	dir := e.historyDir(volume, rel)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return
	}
	_ = os.WriteFile(filepath.Join(dir, "path"), []byte(rel), 0o600)
	name := strconv.FormatInt(e.now().UnixNano(), 10) + "-" + reason
	out, err := os.OpenFile(filepath.Join(dir, name), os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600)
	if err != nil {
		return
	}
	_, err = io.Copy(out, io.LimitReader(in, MaxHistoryBytes))
	out.Close()
	if err != nil {
		os.Remove(filepath.Join(dir, name))
		return
	}
	e.pruneHistory(dir)
}

func (e *Executor) versions(dir string) []FileVersion {
	des, _ := os.ReadDir(dir)
	out := []FileVersion{}
	for _, de := range des {
		if !reVersion.MatchString(de.Name()) {
			continue
		}
		ns, _ := strconv.ParseInt(strings.SplitN(de.Name(), "-", 2)[0], 10, 64)
		fi, err := de.Info()
		if err != nil {
			continue
		}
		out = append(out, FileVersion{ID: de.Name(), SavedAt: time.Unix(0, ns).UTC().Format(time.RFC3339), Reason: strings.SplitN(de.Name(), "-", 2)[1], Size: fi.Size()})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].ID > out[j].ID }) // newest first
	return out
}

func (e *Executor) pruneHistory(dir string) {
	cutoff := e.now().Add(-HistoryRetention)
	for i, v := range e.versions(dir) {
		t, _ := time.Parse(time.RFC3339, v.SavedAt)
		if i >= HistoryKeep || t.Before(cutoff) {
			os.Remove(filepath.Join(dir, v.ID))
		}
	}
}

type historyPayload struct {
	Volume string `json:"volume"`
	Path   string `json:"path"`
	ID     string `json:"id"`
}

func (e *Executor) history(ctx context.Context, typ string, raw json.RawMessage) (any, error) {
	var p historyPayload
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if e.StateDir == "" {
		return nil, errors.New("the agent has no state directory for file history")
	}
	rel, err := cleanRel(p.Path)
	if err != nil || rel == "." {
		return nil, errors.New("a file path is required")
	}
	vdir, err := e.volumeRoot(ctx, p.Volume)
	if err != nil {
		return nil, err
	}
	dir := e.historyDir(p.Volume, rel)
	e.pruneHistory(dir)
	if typ == "fs.history_list" {
		return map[string]any{"path": rel, "versions": e.versions(dir)}, nil
	}
	if !reVersion.MatchString(p.ID) {
		return nil, fmt.Errorf("invalid version %q", p.ID)
	}
	src := filepath.Join(dir, p.ID)
	if _, err := os.Stat(src); err != nil {
		return nil, fmt.Errorf("version %s no longer exists", p.ID)
	}
	if typ == "fs.history_read" {
		b, err := os.ReadFile(src)
		if err != nil {
			return nil, err
		}
		if len(b) > MaxEditBytes || !utf8.Valid(b) || strings.IndexByte(string(b), 0) >= 0 {
			return map[string]any{"path": rel, "id": p.ID, "binary": true, "size": len(b)}, nil
		}
		return map[string]any{"path": rel, "id": p.ID, "content": string(b), "size": len(b)}, nil
	}
	// fs.history_restore: keep what is there now, so a restore can be undone.
	root, err := os.OpenRoot(vdir)
	if err != nil {
		return nil, err
	}
	defer root.Close()
	e.snapshot(p.Volume, root, rel, "restore")
	in, err := os.Open(src)
	if err != nil {
		return nil, err
	}
	defer in.Close()
	if err := mkdirAll(root, path.Dir(rel)); err != nil {
		return nil, err
	}
	mode := fs.FileMode(0o644)
	existing, err := root.Lstat(rel)
	if err == nil {
		if !existing.Mode().IsRegular() {
			return nil, fmt.Errorf("%s is not a regular file", rel)
		}
		mode = existing.Mode().Perm()
	}
	out, err := root.OpenFile(rel, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, mode)
	if err != nil {
		return nil, err
	}
	if existing == nil {
		inheritOwner(root, path.Dir(rel), out)
	}
	n, err := io.Copy(out, in)
	if cerr := out.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return nil, err
	}
	return map[string]any{"path": rel, "restored": p.ID, "bytes": n}, nil
}
