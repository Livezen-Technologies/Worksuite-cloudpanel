package executor

import (
	"archive/tar"
	"archive/zip"
	"compress/gzip"
	"context"
	"crypto/rand"
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

// Trash, copy/move and archives (FM-006, FM-007, FM-008). Like every fs.*
// command these work through os.Root, so nothing outside the volume is read,
// written or followed.

// TrashRetention is how long a trashed item can be restored.
const TrashRetention = 7 * 24 * time.Hour

// MaxExtractBytes and MaxExtractEntries stop an archive from filling the disk.
const (
	MaxExtractBytes   = 10 << 30
	MaxExtractEntries = 100000
)

type opsPayload struct {
	Volume    string   `json:"volume"`
	Paths     []string `json:"paths"`
	Dest      string   `json:"dest"`
	IDs       []string `json:"ids"`
	Format    string   `json:"format"`
	Name      string   `json:"name"`
	Path      string   `json:"path"`
	Overwrite bool     `json:"overwrite"`
}

// TrashItem describes one restorable item.
type TrashItem struct {
	ID        string `json:"id"`
	Path      string `json:"path"`
	Type      string `json:"type"`
	Size      int64  `json:"size"`
	DeletedAt string `json:"deleted_at"`
	ExpiresAt string `json:"expires_at"`
}

func (e *Executor) fileOps(ctx context.Context, typ string, raw json.RawMessage) (any, error) {
	var p opsPayload
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
	rels := make([]string, 0, len(p.Paths))
	for _, raw := range p.Paths {
		rel, err := cleanRel(raw)
		if err != nil {
			return nil, err
		}
		if rel == "." {
			return nil, errors.New("the volume root cannot be selected")
		}
		rels = append(rels, rel)
	}
	switch typ {
	case "fs.copy", "fs.move":
		dest, err := cleanRel(p.Dest)
		if err != nil {
			return nil, err
		}
		return copyMove(root, rels, dest, typ == "fs.move")
	case "fs.compress":
		return compress(root, rels, p.Dest, p.Name, p.Format)
	case "fs.extract":
		rel, err := cleanRel(p.Path)
		if err != nil {
			return nil, err
		}
		dest, err := cleanRel(p.Dest)
		if err != nil {
			return nil, err
		}
		return extract(root, rel, dest, p.Overwrite)
	}
	trash, err := e.openTrash(p.Volume)
	if err != nil {
		return nil, err
	}
	defer trash.Close()
	purgeExpired(trash, e.now())
	switch typ {
	case "fs.trash":
		return e.toTrash(root, trash, rels)
	case "fs.trash_list":
		return listTrash(trash)
	case "fs.trash_restore":
		return restoreTrash(root, trash, p.IDs)
	case "fs.trash_purge":
		return purgeTrash(trash, p.IDs)
	}
	return nil, fmt.Errorf("unknown file command %s", typ)
}

// ---- copy and move ----

func copyMove(root *os.Root, rels []string, dest string, move bool) (any, error) {
	if len(rels) == 0 {
		return nil, errors.New("nothing selected")
	}
	if fi, err := root.Stat(dest); err != nil || !fi.IsDir() {
		return nil, fmt.Errorf("the destination %s is not a folder", dest)
	}
	files, skipped := 0, 0
	for _, rel := range rels {
		if dest == rel || strings.HasPrefix(dest+"/", rel+"/") {
			return nil, fmt.Errorf("%s cannot be put inside itself", rel)
		}
		name := path.Base(rel)
		target := path.Join(dest, name)
		if path.Dir(rel) == dest {
			if move {
				continue // already there
			}
			target = freeName(root, dest, name)
		} else if _, err := root.Lstat(target); err == nil {
			return nil, fmt.Errorf("%s already exists", target)
		}
		if move {
			if err := moveWithin(root, rel, target); err != nil {
				return nil, err
			}
			files++
			continue
		}
		n, s, err := copyTree(root, rel, root, target)
		files, skipped = files+n, skipped+s
		if err != nil {
			return nil, err
		}
	}
	return map[string]any{"items": len(rels), "files": files, "skipped_links": skipped}, nil
}

// freeName finds "name (copy)", "name (copy 2)", … for a copy into the same folder.
func freeName(root *os.Root, dir, name string) string {
	ext := path.Ext(name)
	stem := strings.TrimSuffix(name, ext)
	if stem == "" { // dotfiles such as .htaccess
		stem, ext = name, ""
	}
	for i := 1; ; i++ {
		suffix := " (copy)"
		if i > 1 {
			suffix = fmt.Sprintf(" (copy %d)", i)
		}
		c := path.Join(dir, stem+suffix+ext)
		if _, err := root.Lstat(c); errors.Is(err, fs.ErrNotExist) {
			return c
		}
	}
}

func moveWithin(root *os.Root, from, to string) error {
	src, err := root.Open(path.Dir(from))
	if err != nil {
		return err
	}
	defer src.Close()
	dst, err := root.Open(path.Dir(to))
	if err != nil {
		return err
	}
	defer dst.Close()
	return renameBetween(src, path.Base(from), dst, path.Base(to))
}

// copyTree copies srcRel in src to dstRel in dst, keeping permissions. Symlinks
// and special files are skipped and counted, never followed.
func copyTree(src *os.Root, srcRel string, dst *os.Root, dstRel string) (files, skipped int, err error) {
	fi, err := src.Lstat(srcRel)
	if err != nil {
		return 0, 0, err
	}
	switch {
	case fi.IsDir():
		if err := dst.Mkdir(dstRel, fi.Mode().Perm()|0o700); err != nil {
			return 0, 0, err
		}
		d, err := src.Open(srcRel)
		if err != nil {
			return 0, 0, err
		}
		des, err := d.ReadDir(-1)
		d.Close()
		if err != nil {
			return 0, 0, err
		}
		for _, de := range des {
			f, s, err := copyTree(src, path.Join(srcRel, de.Name()), dst, path.Join(dstRel, de.Name()))
			files, skipped = files+f, skipped+s
			if err != nil {
				return files, skipped, err
			}
		}
		return files, skipped, nil
	case fi.Mode().IsRegular():
		in, err := src.Open(srcRel)
		if err != nil {
			return 0, 0, err
		}
		defer in.Close()
		out, err := dst.OpenFile(dstRel, os.O_WRONLY|os.O_CREATE|os.O_EXCL, fi.Mode().Perm())
		if err != nil {
			return 0, 0, err
		}
		_, err = io.Copy(out, in)
		if cerr := out.Close(); err == nil {
			err = cerr
		}
		return 1, 0, err
	}
	return 0, 1, nil
}

// ---- trash ----

func (e *Executor) openTrash(volume string) (*os.Root, error) {
	if e.StateDir == "" {
		return nil, errors.New("the agent has no state directory for the trash")
	}
	dir := filepath.Join(e.StateDir, "trash", volume)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, err
	}
	return os.OpenRoot(dir)
}

func newTrashID(now time.Time) string {
	b := make([]byte, 4)
	_, _ = rand.Read(b)
	return now.UTC().Format("20060102T150405") + "-" + hex.EncodeToString(b)
}

// toTrash moves each item out of the volume into the agent's own trash, so a
// trashed file is no longer served by the site. Each item keeps its original path.
func (e *Executor) toTrash(root, trash *os.Root, rels []string) (any, error) {
	if len(rels) == 0 {
		return nil, errors.New("nothing selected")
	}
	items := make([]TrashItem, 0, len(rels))
	for _, rel := range rels {
		fi, err := root.Lstat(rel)
		if err != nil {
			return items, err
		}
		now := e.now()
		id := newTrashID(now)
		if err := trash.Mkdir(id, 0o700); err != nil {
			return items, err
		}
		item := TrashItem{ID: id, Path: rel, Type: entryOf(fi.Name(), fi).Type, Size: treeSize(root, rel),
			DeletedAt: now.UTC().Format(time.RFC3339), ExpiresAt: now.Add(TrashRetention).UTC().Format(time.RFC3339)}
		if err := transferOut(root, rel, trash, path.Join(id, "item")); err != nil {
			_ = trash.Remove(id)
			return items, err
		}
		meta, _ := json.Marshal(item)
		if f, err := trash.OpenFile(path.Join(id, "meta.json"), os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600); err == nil {
			f.Write(meta)
			f.Close()
		}
		items = append(items, item)
	}
	return map[string]any{"items": items}, nil
}

// transferOut moves srcRel from one root to another, copying and deleting
// when they are on different filesystems.
func transferOut(src *os.Root, srcRel string, dst *os.Root, dstRel string) error {
	from, err := src.Open(path.Dir(srcRel))
	if err != nil {
		return err
	}
	defer from.Close()
	to, err := dst.Open(path.Dir(dstRel))
	if err != nil {
		return err
	}
	defer to.Close()
	err = renameBetween(from, path.Base(srcRel), to, path.Base(dstRel))
	if err == nil || !isCrossDevice(err) {
		return err
	}
	if _, _, err := copyTree(src, srcRel, dst, dstRel); err != nil {
		_, _ = removeTree(dst, dstRel)
		return err
	}
	_, err = removeTree(src, srcRel)
	return err
}

func treeSize(root *os.Root, rel string) int64 {
	fi, err := root.Lstat(rel)
	if err != nil {
		return 0
	}
	if !fi.IsDir() {
		return fi.Size()
	}
	d, err := root.Open(rel)
	if err != nil {
		return 0
	}
	des, _ := d.ReadDir(-1)
	d.Close()
	var n int64
	for _, de := range des {
		n += treeSize(root, path.Join(rel, de.Name()))
	}
	return n
}

func readTrashItem(trash *os.Root, id string) (TrashItem, error) {
	var it TrashItem
	f, err := trash.Open(path.Join(id, "meta.json"))
	if err != nil {
		return it, err
	}
	defer f.Close()
	err = json.NewDecoder(io.LimitReader(f, 64<<10)).Decode(&it)
	return it, err
}

func listTrash(trash *os.Root) (any, error) {
	d, err := trash.Open(".")
	if err != nil {
		return nil, err
	}
	des, err := d.ReadDir(-1)
	d.Close()
	if err != nil {
		return nil, err
	}
	items := []TrashItem{}
	for _, de := range des {
		if it, err := readTrashItem(trash, de.Name()); err == nil {
			items = append(items, it)
		}
	}
	sort.Slice(items, func(i, j int) bool { return items[i].DeletedAt > items[j].DeletedAt })
	return map[string]any{"items": items}, nil
}

func checkTrashID(id string) error {
	if id == "" || strings.ContainsAny(id, "/\\.\x00") || len(id) > 64 {
		return fmt.Errorf("invalid trash item %q", id)
	}
	return nil
}

func restoreTrash(root, trash *os.Root, ids []string) (any, error) {
	if len(ids) == 0 {
		return nil, errors.New("nothing selected to restore")
	}
	restored := []string{}
	for _, id := range ids {
		if err := checkTrashID(id); err != nil {
			return nil, err
		}
		it, err := readTrashItem(trash, id)
		if err != nil {
			return nil, fmt.Errorf("trash item %s not found", id)
		}
		rel, err := cleanRel(it.Path)
		if err != nil || rel == "." {
			return nil, fmt.Errorf("trash item %s has an invalid path", id)
		}
		if _, err := root.Lstat(rel); err == nil {
			return map[string]any{"restored": restored}, fmt.Errorf("%s already exists; rename it before restoring", rel)
		}
		if err := mkdirAll(root, path.Dir(rel)); err != nil {
			return nil, err
		}
		if err := transferOut(trash, path.Join(id, "item"), root, rel); err != nil {
			return nil, err
		}
		_, _ = removeTree(trash, id)
		restored = append(restored, rel)
	}
	return map[string]any{"restored": restored}, nil
}

func purgeTrash(trash *os.Root, ids []string) (any, error) {
	purged := 0
	for _, id := range ids {
		if err := checkTrashID(id); err != nil {
			return nil, err
		}
		if _, err := removeTree(trash, id); err != nil && !errors.Is(err, fs.ErrNotExist) {
			return nil, err
		}
		purged++
	}
	return map[string]any{"purged": purged}, nil
}

// purgeExpired deletes items past their retention; it runs before every trash command.
func purgeExpired(trash *os.Root, now time.Time) {
	d, err := trash.Open(".")
	if err != nil {
		return
	}
	des, _ := d.ReadDir(-1)
	d.Close()
	for _, de := range des {
		it, err := readTrashItem(trash, de.Name())
		if err != nil {
			continue
		}
		if exp, err := time.Parse(time.RFC3339, it.ExpiresAt); err == nil && now.After(exp) {
			_, _ = removeTree(trash, de.Name())
		}
	}
}

// ---- archives ----

func compress(root *os.Root, rels []string, destDir, name, format string) (any, error) {
	if len(rels) == 0 {
		return nil, errors.New("nothing selected")
	}
	dest, err := cleanRel(destDir)
	if err != nil {
		return nil, err
	}
	ext := map[string]string{"zip": ".zip", "tar": ".tar", "tar.gz": ".tar.gz"}[format]
	if ext == "" {
		return nil, fmt.Errorf("unsupported archive format %q", format)
	}
	if name == "" {
		name = "archive"
		if len(rels) == 1 {
			name = path.Base(rels[0])
		}
	}
	if !strings.HasSuffix(name, ext) {
		name += ext
	}
	if err := checkName(name); err != nil {
		return nil, err
	}
	target := path.Join(dest, name)
	out, err := root.OpenFile(target, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o644)
	if err != nil {
		if errors.Is(err, fs.ErrExist) {
			return nil, fmt.Errorf("%s already exists", target)
		}
		return nil, err
	}
	inheritOwner(root, dest, out)
	var files int
	switch format {
	case "zip":
		zw := zip.NewWriter(out)
		z := &zipper{root: root, w: zw}
		for _, rel := range rels {
			if err = z.add(rel, path.Base(rel)); err != nil {
				break
			}
		}
		if cerr := zw.Close(); err == nil {
			err = cerr
		}
		files = z.files
	default:
		var w io.Writer = out
		var gz *gzip.Writer
		if format == "tar.gz" {
			gz = gzip.NewWriter(out)
			w = gz
		}
		tw := tar.NewWriter(w)
		t := &tarrer{root: root, w: tw}
		for _, rel := range rels {
			if err = t.add(rel, path.Base(rel)); err != nil {
				break
			}
		}
		if cerr := tw.Close(); err == nil {
			err = cerr
		}
		if gz != nil {
			if cerr := gz.Close(); err == nil {
				err = cerr
			}
		}
		files = t.files
	}
	if cerr := out.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		_ = root.Remove(target)
		return nil, err
	}
	var size int64
	if fi, err := root.Stat(target); err == nil {
		size = fi.Size()
	}
	return map[string]any{"path": target, "files": files, "bytes": size}, nil
}

type tarrer struct {
	root  *os.Root
	w     *tar.Writer
	files int
	bytes int64
}

func (t *tarrer) add(rel, name string) error {
	fi, err := t.root.Lstat(rel)
	if err != nil {
		return err
	}
	switch {
	case fi.IsDir():
		hdr, err := tar.FileInfoHeader(fi, "")
		if err != nil {
			return err
		}
		hdr.Name = name + "/"
		if err := t.w.WriteHeader(hdr); err != nil {
			return err
		}
		d, err := t.root.Open(rel)
		if err != nil {
			return err
		}
		des, err := d.ReadDir(-1)
		d.Close()
		if err != nil {
			return err
		}
		for _, de := range des {
			if err := t.add(path.Join(rel, de.Name()), path.Join(name, de.Name())); err != nil {
				return err
			}
		}
	case fi.Mode().IsRegular():
		t.bytes += fi.Size()
		if t.bytes > MaxTransferBytes {
			return fmt.Errorf("the selection is larger than the %d byte limit", int64(MaxTransferBytes))
		}
		hdr, err := tar.FileInfoHeader(fi, "")
		if err != nil {
			return err
		}
		hdr.Name = name
		if err := t.w.WriteHeader(hdr); err != nil {
			return err
		}
		f, err := t.root.Open(rel)
		if err != nil {
			return err
		}
		_, err = io.Copy(t.w, f)
		f.Close()
		t.files++
		return err
	}
	return nil
}

// archiveEntry is one file or folder read from a ZIP or TAR.
type archiveEntry struct {
	name string
	dir  bool
	mode fs.FileMode
	size int64
	open func() (io.ReadCloser, error)
}

func extract(root *os.Root, rel, dest string, overwrite bool) (any, error) {
	f, err := root.Open(rel)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil || !fi.Mode().IsRegular() {
		return nil, fmt.Errorf("%s is not an archive file", rel)
	}
	if st, err := root.Stat(dest); err != nil || !st.IsDir() {
		return nil, fmt.Errorf("the destination %s is not a folder", dest)
	}
	lower := strings.ToLower(rel)
	var entries []archiveEntry
	switch {
	case strings.HasSuffix(lower, ".zip"):
		zr, err := zip.NewReader(f, fi.Size())
		if err != nil {
			return nil, fmt.Errorf("%s is not a valid ZIP: %w", rel, err)
		}
		for _, zf := range zr.File {
			zf := zf
			if zf.Mode()&fs.ModeSymlink != 0 {
				continue
			}
			entries = append(entries, archiveEntry{name: zf.Name, dir: zf.FileInfo().IsDir(), mode: zf.Mode().Perm(), size: int64(zf.UncompressedSize64), open: zf.Open})
		}
		return writeEntries(root, entries, dest, overwrite)
	case strings.HasSuffix(lower, ".tar.gz"), strings.HasSuffix(lower, ".tgz"), strings.HasSuffix(lower, ".tar"):
		var r io.Reader = f
		if !strings.HasSuffix(lower, ".tar") {
			gz, err := gzip.NewReader(f)
			if err != nil {
				return nil, fmt.Errorf("%s is not a valid gzip file: %w", rel, err)
			}
			defer gz.Close()
			r = gz
		}
		return extractTar(root, tar.NewReader(r), dest, overwrite)
	}
	return nil, fmt.Errorf("%s is not a ZIP, TAR or TAR.GZ file", rel)
}

// safeEntry maps an archive entry name to a path under dest, or "" to skip it.
func safeEntry(dest, name string) (string, error) {
	name = strings.ReplaceAll(name, "\\", "/")
	if strings.ContainsRune(name, 0) {
		return "", errors.New("archive entry with an invalid name")
	}
	clean := path.Clean("/" + name)
	if clean == "/" {
		return "", nil
	}
	for _, part := range strings.Split(strings.TrimPrefix(name, "/"), "/") {
		if part == ".." {
			return "", fmt.Errorf("archive entry %q points outside the folder", name)
		}
	}
	return path.Join(dest, strings.TrimPrefix(clean, "/")), nil
}

func writeEntries(root *os.Root, entries []archiveEntry, dest string, overwrite bool) (any, error) {
	if len(entries) > MaxExtractEntries {
		return nil, fmt.Errorf("the archive has %d entries; the limit is %d", len(entries), MaxExtractEntries)
	}
	var total int64
	for _, en := range entries {
		total += en.size
	}
	if total > MaxExtractBytes {
		return nil, fmt.Errorf("the archive expands to %d bytes; the limit is %d", total, int64(MaxExtractBytes))
	}
	files := 0
	var written int64
	for _, en := range entries {
		target, err := safeEntry(dest, en.name)
		if err != nil {
			return nil, err
		}
		if target == "" {
			continue
		}
		if en.dir {
			if err := mkdirAll(root, target); err != nil {
				return nil, err
			}
			continue
		}
		rc, err := en.open()
		if err != nil {
			return nil, err
		}
		n, err := writeEntry(root, target, rc, en.mode, overwrite, MaxExtractBytes-written)
		rc.Close()
		written += n
		if err != nil {
			return nil, fmt.Errorf("%s: %w", target, err)
		}
		files++
	}
	return map[string]any{"dest": dest, "files": files, "bytes": written}, nil
}

func extractTar(root *os.Root, tr *tar.Reader, dest string, overwrite bool) (any, error) {
	files, count := 0, 0
	var written int64
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return nil, fmt.Errorf("reading the archive: %w", err)
		}
		if count++; count > MaxExtractEntries {
			return nil, fmt.Errorf("the archive has more than %d entries", MaxExtractEntries)
		}
		target, err := safeEntry(dest, hdr.Name)
		if err != nil {
			return nil, err
		}
		if target == "" {
			continue
		}
		switch hdr.Typeflag {
		case tar.TypeDir:
			if err := mkdirAll(root, target); err != nil {
				return nil, err
			}
		case tar.TypeReg:
			n, err := writeEntry(root, target, io.NopCloser(tr), fs.FileMode(hdr.Mode).Perm(), overwrite, MaxExtractBytes-written)
			written += n
			if err != nil {
				return nil, fmt.Errorf("%s: %w", target, err)
			}
			files++
		}
		// Links, devices and other types are skipped.
	}
	return map[string]any{"dest": dest, "files": files, "bytes": written}, nil
}

func writeEntry(root *os.Root, target string, r io.Reader, mode fs.FileMode, overwrite bool, budget int64) (int64, error) {
	if err := mkdirAll(root, path.Dir(target)); err != nil {
		return 0, err
	}
	if mode == 0 {
		mode = 0o644
	}
	existing, err := root.Lstat(target)
	switch {
	case err == nil && !overwrite:
		return 0, errors.New("already exists")
	case err == nil && !existing.Mode().IsRegular():
		return 0, errors.New("exists and is not a regular file")
	}
	flags := os.O_WRONLY | os.O_CREATE | os.O_TRUNC
	if !overwrite {
		flags |= os.O_EXCL
	}
	out, err := root.OpenFile(target, flags, mode&0o777)
	if err != nil {
		return 0, err
	}
	if existing == nil {
		inheritOwner(root, path.Dir(target), out)
	}
	n, err := io.Copy(out, io.LimitReader(r, budget+1))
	if cerr := out.Close(); err == nil {
		err = cerr
	}
	if err == nil && n > budget {
		err = errors.New("the archive expands past the size limit")
	}
	return n, err
}
