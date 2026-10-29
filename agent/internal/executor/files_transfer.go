package executor

import (
	"archive/zip"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"net/http"
	"os"
	"path"
	"strings"
	"time"
)

// Uploads and downloads (FM-002, FM-003). File bytes never travel inside a
// command: the panel hands the agent short-lived signed URLs, and the agent
// fetches uploads from them or sends downloads to them. Paths are confined to
// the volume exactly as for the other fs.* commands.

// MaxTransferBytes caps one uploaded file and one download (a file or a ZIP).
const MaxTransferBytes = 5 << 30

type transferURL struct {
	URL    string `json:"url"`
	Method string `json:"method"`
}

type uploadItem struct {
	Path string `json:"path"`
	URL  string `json:"url"`
}

type transferPayload struct {
	Volume    string       `json:"volume"`
	Paths     []string     `json:"paths"`
	Upload    transferURL  `json:"upload"`
	Files     []uploadItem `json:"files"`
	Overwrite bool         `json:"overwrite"`
}

func (e *Executor) httpClient() *http.Client {
	if e.HTTP != nil {
		return e.HTTP
	}
	return &http.Client{Timeout: 6 * time.Hour}
}

func checkURL(u string) error {
	if !strings.HasPrefix(u, "https://") && !strings.HasPrefix(u, "http://") {
		return fmt.Errorf("invalid transfer URL")
	}
	return nil
}

func (e *Executor) transfer(ctx context.Context, typ string, raw json.RawMessage) (any, error) {
	var p transferPayload
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
	if typ == "fs.upload" {
		return e.fsUpload(ctx, root, p)
	}
	return e.fsDownload(ctx, root, p)
}

// ---- upload: fetch each file from the panel and write it into the volume ----

func (e *Executor) fsUpload(ctx context.Context, root *os.Root, p transferPayload) (any, error) {
	if len(p.Files) == 0 {
		return nil, errors.New("no files to upload")
	}
	var total int64
	for _, f := range p.Files {
		rel, err := cleanRel(f.Path)
		if err != nil {
			return nil, err
		}
		if rel == "." {
			return nil, errors.New("an upload needs a file path")
		}
		for _, part := range strings.Split(rel, "/") {
			if err := checkName(part); err != nil {
				return nil, err
			}
		}
		if err := checkURL(f.URL); err != nil {
			return nil, err
		}
		if err := mkdirAll(root, path.Dir(rel)); err != nil {
			return nil, err
		}
		n, err := e.fetchInto(ctx, root, rel, f.URL, p.Overwrite)
		if err != nil {
			return map[string]any{"written": total}, fmt.Errorf("%s: %w", rel, err)
		}
		total += n
	}
	return map[string]any{"files": len(p.Files), "bytes": total}, nil
}

// mkdirAll creates each missing folder of rel through the root, so a symlink
// on the way cannot lead outside the volume. New folders take their parent's owner.
func mkdirAll(root *os.Root, rel string) error {
	if rel == "." || rel == "" {
		return nil
	}
	cur := "."
	for _, part := range strings.Split(rel, "/") {
		next := path.Join(cur, part)
		fi, err := root.Lstat(next)
		switch {
		case err == nil && fi.IsDir():
		case err == nil:
			return fmt.Errorf("%s exists and is not a folder", next)
		case errors.Is(err, fs.ErrNotExist):
			if err := root.Mkdir(next, 0o755); err != nil && !errors.Is(err, fs.ErrExist) {
				return err
			}
			if d, err := root.Open(next); err == nil {
				inheritOwner(root, cur, d)
				d.Close()
			}
		default:
			return err
		}
		cur = next
	}
	return nil
}

func (e *Executor) fetchInto(ctx context.Context, root *os.Root, rel, url string, overwrite bool) (int64, error) {
	existing, err := root.Lstat(rel)
	switch {
	case err == nil && !overwrite:
		return 0, errors.New("already exists")
	case err == nil && !existing.Mode().IsRegular():
		return 0, errors.New("exists and is not a regular file")
	case err != nil && !errors.Is(err, fs.ErrNotExist):
		return 0, err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return 0, err
	}
	resp, err := e.httpClient().Do(req)
	if err != nil {
		return 0, fmt.Errorf("fetch: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return 0, fmt.Errorf("fetch: HTTP %d", resp.StatusCode)
	}
	if resp.ContentLength > MaxTransferBytes {
		return 0, fmt.Errorf("is %d bytes; the limit is %d", resp.ContentLength, int64(MaxTransferBytes))
	}
	mode := fs.FileMode(0o644)
	if existing != nil {
		mode = existing.Mode().Perm()
	}
	flags := os.O_WRONLY | os.O_CREATE | os.O_TRUNC
	if !overwrite {
		flags |= os.O_EXCL
	}
	out, err := root.OpenFile(rel, flags, mode)
	if err != nil {
		return 0, err
	}
	if existing == nil {
		inheritOwner(root, path.Dir(rel), out)
	}
	n, err := io.Copy(out, io.LimitReader(resp.Body, MaxTransferBytes+1))
	if cerr := out.Close(); err == nil {
		err = cerr
	}
	if err == nil && n > MaxTransferBytes {
		err = fmt.Errorf("is larger than the %d byte limit", int64(MaxTransferBytes))
	}
	if err != nil {
		if existing == nil {
			_ = root.Remove(rel) // never leave half a new file behind
		}
		return n, err
	}
	return n, nil
}

// ---- download: one file as is, anything else as a ZIP ----

func (e *Executor) fsDownload(ctx context.Context, root *os.Root, p transferPayload) (any, error) {
	if len(p.Paths) == 0 {
		return nil, errors.New("nothing selected to download")
	}
	if err := checkURL(p.Upload.URL); err != nil {
		return nil, err
	}
	rels := make([]string, 0, len(p.Paths))
	for _, raw := range p.Paths {
		rel, err := cleanRel(raw)
		if err != nil {
			return nil, err
		}
		rels = append(rels, rel)
	}
	if len(rels) == 1 && rels[0] != "." {
		if fi, err := root.Lstat(rels[0]); err == nil && fi.Mode().IsRegular() {
			f, err := root.Open(rels[0])
			if err != nil {
				return nil, err
			}
			defer f.Close()
			if fi.Size() > MaxTransferBytes {
				return nil, fmt.Errorf("%s is larger than the %d byte limit", rels[0], int64(MaxTransferBytes))
			}
			if err := e.send(ctx, p.Upload, f, fi.Size(), "application/octet-stream"); err != nil {
				return nil, err
			}
			return map[string]any{"format": "file", "name": path.Base(rels[0]), "bytes": fi.Size(), "files": 1}, nil
		}
	}
	tmp, err := os.CreateTemp("", "fs-download-*.zip")
	if err != nil {
		return nil, err
	}
	defer os.Remove(tmp.Name())
	defer tmp.Close()
	zw := zip.NewWriter(tmp)
	z := &zipper{root: root, w: zw}
	for _, rel := range rels {
		// Entries are named from the selection's own name, so a folder keeps its name in the ZIP.
		prefix := path.Base(rel)
		if rel == "." {
			prefix = ""
		}
		if err := z.add(rel, prefix); err != nil {
			return nil, err
		}
	}
	if err := zw.Close(); err != nil {
		return nil, err
	}
	size, err := tmp.Seek(0, io.SeekCurrent)
	if err != nil {
		return nil, err
	}
	if _, err := tmp.Seek(0, io.SeekStart); err != nil {
		return nil, err
	}
	if err := e.send(ctx, p.Upload, tmp, size, "application/zip"); err != nil {
		return nil, err
	}
	name := "download.zip"
	if len(rels) == 1 && rels[0] != "." {
		name = path.Base(rels[0]) + ".zip"
	}
	return map[string]any{"format": "zip", "name": name, "bytes": size, "files": z.files}, nil
}

type zipper struct {
	root  *os.Root
	w     *zip.Writer
	files int
	bytes int64
}

// add writes rel into the ZIP as name. Symlinks and special files are skipped,
// never followed.
func (z *zipper) add(rel, name string) error {
	fi, err := z.root.Lstat(rel)
	if err != nil {
		return err
	}
	switch {
	case fi.IsDir():
		d, err := z.root.Open(rel)
		if err != nil {
			return err
		}
		des, err := d.ReadDir(-1)
		d.Close()
		if err != nil {
			return err
		}
		if name != "" {
			if _, err := z.w.Create(name + "/"); err != nil {
				return err
			}
		}
		for _, de := range des {
			if err := z.add(path.Join(rel, de.Name()), path.Join(name, de.Name())); err != nil {
				return err
			}
		}
		return nil
	case fi.Mode().IsRegular():
		z.bytes += fi.Size()
		if z.bytes > MaxTransferBytes {
			return fmt.Errorf("the selection is larger than the %d byte limit", int64(MaxTransferBytes))
		}
		hdr, err := zip.FileInfoHeader(fi)
		if err != nil {
			return err
		}
		hdr.Name, hdr.Method = name, zip.Deflate
		w, err := z.w.CreateHeader(hdr)
		if err != nil {
			return err
		}
		f, err := z.root.Open(rel)
		if err != nil {
			return err
		}
		_, err = io.Copy(w, f)
		f.Close()
		z.files++
		return err
	}
	return nil
}

func (e *Executor) send(ctx context.Context, to transferURL, body io.Reader, size int64, contentType string) error {
	method := strings.ToUpper(firstNonEmpty(to.Method, "PUT"))
	if method != "PUT" && method != "POST" {
		return fmt.Errorf("invalid upload method %q", method)
	}
	req, err := http.NewRequestWithContext(ctx, method, to.URL, body)
	if err != nil {
		return err
	}
	req.ContentLength = size
	req.Header.Set("Content-Type", contentType)
	resp, err := e.httpClient().Do(req)
	if err != nil {
		return fmt.Errorf("send: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return fmt.Errorf("send: HTTP %d: %s", resp.StatusCode, string(b))
	}
	return nil
}
