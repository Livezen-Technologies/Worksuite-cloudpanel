package executor

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path"
	"sort"
	"strings"
	"time"
	"unicode/utf8"
)

// File manager commands (SRS 4.17). Every operation is confined to one Docker
// volume through os.Root, which refuses any path - "..", an absolute path or a
// symlink - that resolves outside the volume (FM-013). The panel sends paths
// relative to the volume; "" and "/" mean its top level.

// MaxEditBytes is the largest file fs.read returns and fs.write accepts.
const MaxEditBytes = 1 << 20

// MaxListEntries caps one fs.list result.
const MaxListEntries = 5000

type fsPayload struct {
	Volume    string `json:"volume"`
	Path      string `json:"path"`
	NewName   string `json:"new_name"`
	Content   string `json:"content"`
	Recursive bool   `json:"recursive"`
	// Create refuses to overwrite: fs.write for a new file, fs.mkdir always.
	Create bool `json:"create"`
}

// FileEntry is one item of an fs.list result.
type FileEntry struct {
	Name     string `json:"name"`
	Type     string `json:"type"` // dir|file|symlink|other
	Size     int64  `json:"size"`
	Mode     string `json:"mode"` // octal permission bits, e.g. "0644"
	Modified string `json:"modified"`
	UID      int    `json:"uid"`
	GID      int    `json:"gid"`
}

// volumeRoot returns the host directory of a local Docker volume.
func (e *Executor) volumeRoot(ctx context.Context, name string) (string, error) {
	if err := checkRef("volume", name); err != nil {
		return "", err
	}
	if e.VolumeRoot != nil {
		return e.VolumeRoot(ctx, name)
	}
	vols, err := e.Docker.ListVolumes(ctx)
	if err != nil {
		return "", err
	}
	for _, v := range vols {
		if v.Name != name {
			continue
		}
		if v.Driver != "local" || v.Mountpoint == "" {
			return "", fmt.Errorf("volume %s is not a local volume", name)
		}
		return v.Mountpoint, nil
	}
	return "", fmt.Errorf("volume %s not found", name)
}

// cleanRel turns a panel path into a path relative to the volume root.
func cleanRel(p string) (string, error) {
	if strings.ContainsRune(p, 0) {
		return "", errors.New("invalid path")
	}
	rel := strings.TrimPrefix(path.Clean("/"+p), "/")
	if rel == "" {
		rel = "."
	}
	return rel, nil
}

// checkName validates a single file or folder name (FM-005).
func checkName(n string) error {
	if n == "" || n == "." || n == ".." || len(n) > 255 || strings.ContainsAny(n, "/\x00") {
		return fmt.Errorf("invalid name %q", n)
	}
	return nil
}

func (e *Executor) files(ctx context.Context, typ string, raw json.RawMessage) (any, error) {
	var p fsPayload
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	dir, err := e.volumeRoot(ctx, p.Volume)
	if err != nil {
		return nil, err
	}
	rel, err := cleanRel(p.Path)
	if err != nil {
		return nil, err
	}
	root, err := os.OpenRoot(dir)
	if err != nil {
		return nil, err
	}
	defer root.Close()
	switch typ {
	case "fs.list":
		return fsList(root, rel)
	case "fs.read":
		return fsRead(root, rel)
	case "fs.write":
		if !p.Create {
			e.snapshot(p.Volume, root, rel, "edit")
		}
		return fsWrite(root, rel, p.Content, p.Create)
	case "fs.mkdir":
		return fsMkdir(root, rel)
	case "fs.rename":
		return fsRename(root, rel, p.NewName)
	case "fs.delete":
		e.snapshot(p.Volume, root, rel, "delete") // single files only; folders go to Trash
		return fsDelete(root, rel, p.Recursive)
	}
	return nil, fmt.Errorf("unknown file command %s", typ)
}

func entryOf(name string, fi fs.FileInfo) FileEntry {
	t := "other"
	switch {
	case fi.Mode()&fs.ModeSymlink != 0:
		t = "symlink"
	case fi.IsDir():
		t = "dir"
	case fi.Mode().IsRegular():
		t = "file"
	}
	uid, gid := ownerOf(fi)
	return FileEntry{Name: name, Type: t, Size: fi.Size(), Mode: fmt.Sprintf("%04o", fi.Mode().Perm()), Modified: fi.ModTime().UTC().Format(time.RFC3339), UID: uid, GID: gid}
}

func fsList(root *os.Root, rel string) (any, error) {
	f, err := root.Open(rel)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	des, err := f.ReadDir(MaxListEntries + 1)
	if err != nil && !errors.Is(err, io.EOF) {
		return nil, err
	}
	truncated := len(des) > MaxListEntries
	if truncated {
		des = des[:MaxListEntries]
	}
	entries := make([]FileEntry, 0, len(des))
	for _, de := range des {
		fi, err := de.Info()
		if err != nil {
			continue // removed while listing
		}
		entries = append(entries, entryOf(de.Name(), fi))
	}
	// Folders first, then by name.
	sort.Slice(entries, func(i, j int) bool {
		if (entries[i].Type == "dir") != (entries[j].Type == "dir") {
			return entries[i].Type == "dir"
		}
		return entries[i].Name < entries[j].Name
	})
	return map[string]any{"path": rel, "entries": entries, "truncated": truncated}, nil
}

func fsRead(root *os.Root, rel string) (any, error) {
	f, err := root.Open(rel)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil {
		return nil, err
	}
	if !fi.Mode().IsRegular() {
		return nil, fmt.Errorf("%s is not a regular file", rel)
	}
	if fi.Size() > MaxEditBytes {
		return nil, fmt.Errorf("%s is %d bytes; the editor opens files up to %d bytes", rel, fi.Size(), MaxEditBytes)
	}
	b, err := io.ReadAll(io.LimitReader(f, MaxEditBytes+1))
	if err != nil {
		return nil, err
	}
	if !utf8.Valid(b) || strings.IndexByte(string(b), 0) >= 0 {
		return nil, fmt.Errorf("%s is not a text file", rel)
	}
	return map[string]any{"path": rel, "content": string(b), "size": len(b), "mode": fmt.Sprintf("%04o", fi.Mode().Perm()), "modified": fi.ModTime().UTC().Format(time.RFC3339)}, nil
}

func fsWrite(root *os.Root, rel, content string, create bool) (any, error) {
	if rel == "." {
		return nil, errors.New("a file path is required")
	}
	if len(content) > MaxEditBytes {
		return nil, fmt.Errorf("content is %d bytes; the limit is %d", len(content), MaxEditBytes)
	}
	if err := checkName(path.Base(rel)); err != nil {
		return nil, err
	}
	mode := fs.FileMode(0o644)
	fi, err := root.Lstat(rel)
	switch {
	case err == nil && create:
		return nil, fmt.Errorf("%s already exists", rel)
	case err == nil && !fi.Mode().IsRegular():
		return nil, fmt.Errorf("%s is not a regular file", rel)
	case err == nil:
		mode = fi.Mode().Perm()
	case !errors.Is(err, fs.ErrNotExist):
		return nil, err
	}
	flags := os.O_WRONLY | os.O_CREATE | os.O_TRUNC
	if create {
		flags |= os.O_EXCL
	}
	f, err := root.OpenFile(rel, flags, mode)
	if err != nil {
		return nil, err
	}
	if fi == nil {
		inheritOwner(root, path.Dir(rel), f)
	}
	if _, err := io.WriteString(f, content); err != nil {
		f.Close()
		return nil, err
	}
	if err := f.Close(); err != nil {
		return nil, err
	}
	return map[string]any{"path": rel, "size": len(content)}, nil
}

func fsMkdir(root *os.Root, rel string) (any, error) {
	if rel == "." {
		return nil, errors.New("a folder path is required")
	}
	if err := checkName(path.Base(rel)); err != nil {
		return nil, err
	}
	if err := root.Mkdir(rel, 0o755); err != nil {
		return nil, err
	}
	if d, err := root.Open(rel); err == nil {
		inheritOwner(root, path.Dir(rel), d)
		d.Close()
	}
	return map[string]any{"path": rel}, nil
}

func fsRename(root *os.Root, rel, newName string) (any, error) {
	if rel == "." {
		return nil, errors.New("the volume root cannot be renamed")
	}
	if err := checkName(newName); err != nil {
		return nil, err
	}
	parent, old := path.Dir(rel), path.Base(rel)
	if _, err := root.Lstat(path.Join(parent, newName)); err == nil {
		return nil, fmt.Errorf("%s already exists", path.Join(parent, newName))
	} else if !errors.Is(err, fs.ErrNotExist) {
		return nil, err
	}
	d, err := root.Open(parent)
	if err != nil {
		return nil, err
	}
	defer d.Close()
	// Both names are single components resolved against a directory opened
	// through the root, so the rename cannot leave the volume.
	if err := renameAt(d, old, newName); err != nil {
		return nil, err
	}
	return map[string]any{"path": path.Join(parent, newName)}, nil
}

func fsDelete(root *os.Root, rel string, recursive bool) (any, error) {
	if rel == "." {
		return nil, errors.New("the volume root cannot be deleted")
	}
	fi, err := root.Lstat(rel)
	if err != nil {
		return nil, err
	}
	if fi.IsDir() && !recursive {
		if err := root.Remove(rel); err != nil {
			return nil, fmt.Errorf("%s is a folder that is not empty; delete it recursively", rel)
		}
		return map[string]any{"path": rel, "removed": 1}, nil
	}
	n, err := removeTree(root, rel)
	return map[string]any{"path": rel, "removed": n}, err
}

// removeTree deletes rel and, for a folder, everything under it. Symlinks are
// removed as links and never followed.
func removeTree(root *os.Root, rel string) (int, error) {
	fi, err := root.Lstat(rel)
	if err != nil {
		return 0, err
	}
	n := 0
	if fi.IsDir() {
		d, err := root.Open(rel)
		if err != nil {
			return 0, err
		}
		des, err := d.ReadDir(-1)
		d.Close()
		if err != nil {
			return 0, err
		}
		for _, de := range des {
			c, err := removeTree(root, path.Join(rel, de.Name()))
			n += c
			if err != nil {
				return n, err
			}
		}
	}
	if err := root.Remove(rel); err != nil {
		return n, err
	}
	return n + 1, nil
}
