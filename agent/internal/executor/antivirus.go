package executor

import (
	"bufio"
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path"
	"path/filepath"
	"strings"
	"time"
)

// Antivirus (SRS 4.25: AV-1, AV-4, AV-5, AV-7). ClamAV runs as a container
// with the Docker volumes mounted read-only at the same path; ClamAV also
// loads the YARA web-shell rules shipped next to it, so one engine covers both.
// The agent asks clamd to scan a volume, hashes what it reports through the
// volume's os.Root, and moves confirmed threats into a quarantine outside the
// volume where nothing can execute or serve them.

const DefaultClamAVContainer = "clamav"

// Threat is one infected file found in a volume.
type Threat struct {
	Path      string `json:"path"` // relative to the volume
	Signature string `json:"signature"`
	SHA256    string `json:"sha256"`
	Size      int64  `json:"size"`
}

type avPayload struct {
	Volume    string   `json:"volume"`
	Path      string   `json:"path"`
	ID        string   `json:"id"`
	Allowlist []string `json:"allowlist"` // sha256 hashes marked "not a threat"
}

func (e *Executor) clamContainer() string {
	return firstNonEmpty(e.ClamAVContainer, DefaultClamAVContainer)
}

func (e *Executor) antivirus(ctx context.Context, typ string, raw json.RawMessage) (any, error) {
	var p avPayload
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if typ == "av.delete" {
		return e.avDelete(p.ID)
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
	switch typ {
	case "av.scan":
		return e.avScan(ctx, root, dir, p)
	case "av.quarantine":
		return e.avQuarantine(root, p)
	case "av.restore":
		return e.avRestore(root, p)
	}
	return nil, fmt.Errorf("unknown antivirus command %s", typ)
}

func (e *Executor) avScan(ctx context.Context, root *os.Root, dir string, p avPayload) (any, error) {
	if !reRef.MatchString(e.clamContainer()) {
		return nil, errors.New("invalid ClamAV container name")
	}
	start, err := cleanRel(p.Path)
	if err != nil {
		return nil, err
	}
	target := filepath.Join(dir, filepath.FromSlash(start))
	began := e.now()
	out, err := e.Run(ctx, "", nil, "docker", "exec", e.clamContainer(), "clamdscan", "--multiscan", "--infected", "--no-summary", "--stdout", target)
	// clamdscan exits 1 when it found something and 2 on an error.
	threats, parseErr := parseClam(out, dir)
	if parseErr != nil {
		return nil, parseErr
	}
	if err != nil && len(threats) == 0 {
		return nil, fmt.Errorf("clamdscan: %s", tailString(strings.TrimSpace(string(out)), 400))
	}
	allowed := map[string]bool{}
	for _, h := range p.Allowlist {
		allowed[strings.ToLower(h)] = true
	}
	found := []Threat{}
	for _, t := range threats {
		fi, err := root.Lstat(t.Path)
		if err != nil || !fi.Mode().IsRegular() {
			continue // gone, or not a plain file inside the volume
		}
		sum, err := hashIn(root, t.Path)
		if err != nil {
			continue
		}
		if allowed[sum] {
			continue
		}
		t.SHA256, t.Size = sum, fi.Size()
		found = append(found, t)
	}
	return map[string]any{"threats": found, "path": start, "seconds": int(e.now().Sub(began).Seconds())}, nil
}

// parseClam reads "<abs path>: <signature> FOUND" lines and keeps those inside dir.
func parseClam(out []byte, dir string) ([]Threat, error) {
	var res []Threat
	prefix := strings.TrimRight(dir, "/") + "/"
	sc := bufio.NewScanner(bytes.NewReader(out))
	sc.Buffer(make([]byte, 64<<10), 1<<20)
	for sc.Scan() {
		line := sc.Text()
		if !strings.HasSuffix(line, " FOUND") {
			continue
		}
		i := strings.LastIndex(line, ": ")
		if i < 0 {
			continue
		}
		abs, sig := line[:i], strings.TrimSuffix(line[i+2:], " FOUND")
		if !strings.HasPrefix(abs, prefix) {
			continue
		}
		rel, err := cleanRel(strings.TrimPrefix(abs, prefix))
		if err != nil || rel == "." {
			continue
		}
		res = append(res, Threat{Path: rel, Signature: sig})
	}
	return res, sc.Err()
}

func hashIn(root *os.Root, rel string) (string, error) {
	f, err := root.Open(rel)
	if err != nil {
		return "", err
	}
	defer f.Close()
	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return "", err
	}
	return hex.EncodeToString(h.Sum(nil)), nil
}

func (e *Executor) quarantineRoot() (*os.Root, error) {
	if e.StateDir == "" {
		return nil, errors.New("the agent has no state directory for the quarantine")
	}
	dir := filepath.Join(e.StateDir, "quarantine")
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, err
	}
	return os.OpenRoot(dir)
}

type quarantineMeta struct {
	Volume string `json:"volume"`
	Path   string `json:"path"`
	SHA256 string `json:"sha256"`
	Mode   uint32 `json:"mode"`
	At     string `json:"at"`
}

// avQuarantine moves one infected file out of the volume. p.ID is chosen by
// the panel (its threat id) so both sides name the same quarantined item.
func (e *Executor) avQuarantine(root *os.Root, p avPayload) (any, error) {
	if err := checkTrashID(p.ID); err != nil {
		return nil, err
	}
	rel, err := cleanRel(p.Path)
	if err != nil || rel == "." {
		return nil, errors.New("a file path is required")
	}
	fi, err := root.Lstat(rel)
	if err != nil {
		return nil, err
	}
	if !fi.Mode().IsRegular() {
		return nil, fmt.Errorf("%s is not a regular file", rel)
	}
	sum, err := hashIn(root, rel)
	if err != nil {
		return nil, err
	}
	q, err := e.quarantineRoot()
	if err != nil {
		return nil, err
	}
	defer q.Close()
	if err := q.Mkdir(p.ID, 0o700); err != nil {
		return nil, fmt.Errorf("quarantine item %s: %w", p.ID, err)
	}
	if err := transferOut(root, rel, q, path.Join(p.ID, "file")); err != nil {
		q.Remove(p.ID)
		return nil, err
	}
	// Nothing in the quarantine may be read, run or served by anyone but root.
	if f, err := q.Open(path.Join(p.ID, "file")); err == nil {
		f.Chmod(0)
		f.Close()
	}
	meta, _ := json.Marshal(quarantineMeta{Volume: p.Volume, Path: rel, SHA256: sum, Mode: uint32(fi.Mode().Perm()), At: e.now().UTC().Format(time.RFC3339)})
	if f, err := q.OpenFile(path.Join(p.ID, "meta.json"), os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600); err == nil {
		f.Write(meta)
		f.Close()
	}
	return map[string]any{"id": p.ID, "path": rel, "sha256": sum}, nil
}

func (e *Executor) avRestore(root *os.Root, p avPayload) (any, error) {
	if err := checkTrashID(p.ID); err != nil {
		return nil, err
	}
	q, err := e.quarantineRoot()
	if err != nil {
		return nil, err
	}
	defer q.Close()
	f, err := q.Open(path.Join(p.ID, "meta.json"))
	if err != nil {
		return nil, fmt.Errorf("quarantine item %s not found", p.ID)
	}
	var m quarantineMeta
	err = json.NewDecoder(io.LimitReader(f, 64<<10)).Decode(&m)
	f.Close()
	if err != nil || m.Volume != p.Volume {
		return nil, fmt.Errorf("quarantine item %s does not belong to this volume", p.ID)
	}
	rel, err := cleanRel(m.Path)
	if err != nil || rel == "." {
		return nil, errors.New("invalid quarantine record")
	}
	if _, err := root.Lstat(rel); err == nil {
		return nil, fmt.Errorf("%s already exists; move it before restoring", rel)
	}
	if err := mkdirAll(root, path.Dir(rel)); err != nil {
		return nil, err
	}
	// Give the file its old mode while it is still in the quarantine: it has none
	// there, so it cannot be opened to change it, but its folder can.
	if d, err := q.Open(p.ID); err == nil {
		err = chmodAt(d, "file", os.FileMode(m.Mode&0o777))
		d.Close()
		if err != nil {
			return nil, err
		}
	}
	if err := transferOut(q, path.Join(p.ID, "file"), root, rel); err != nil {
		return nil, err
	}
	removeTree(q, p.ID)
	return map[string]any{"id": p.ID, "path": rel}, nil
}

func (e *Executor) avDelete(id string) (any, error) {
	if err := checkTrashID(id); err != nil {
		return nil, err
	}
	q, err := e.quarantineRoot()
	if err != nil {
		return nil, err
	}
	defer q.Close()
	if _, err := removeTree(q, id); err != nil && !errors.Is(err, os.ErrNotExist) {
		return nil, err
	}
	return map[string]any{"id": id, "deleted": true}, nil
}
