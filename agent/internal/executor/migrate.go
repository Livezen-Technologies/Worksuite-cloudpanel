package executor

import (
	"archive/tar"
	"bufio"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"strings"
)

// Migration wizard (MIG): a cPanel, Plesk or HestiaCP backup (migrate_formats.go) is fetched
// into the agent's state directory, inspected, and unpacked into app volumes and
// database containers. Nothing from the archive is ever executed.

var reMigID = regexp.MustCompile(`^[A-Za-z0-9-]{1,64}$`)
var reDBName = regexp.MustCompile(`^[A-Za-z0-9_]{1,64}$`)

type migratePayload struct {
	ID     string `json:"id"`
	URL    string `json:"url"`
	SHA256 string `json:"sha256"`
	// From is a folder under the cPanel home directory (extract), e.g. public_html.
	From   string `json:"from"`
	Volume string `json:"volume"`
	// Owner is the uid:gid the extracted files get (default 33:33, www-data).
	Owner int `json:"owner"`
	// SQL names mysql/<sql>.sql in the archive (import_db).
	SQL string   `json:"sql"`
	DB  DumpSpec `json:"db"`
}

func (e *Executor) migrationDir(id string) (string, error) {
	if !reMigID.MatchString(id) {
		return "", errors.New("invalid migration id")
	}
	if e.StateDir == "" {
		return "", errors.New("the agent has no state directory")
	}
	return filepath.Join(e.StateDir, "migrations", id), nil
}

func (e *Executor) migrate(ctx context.Context, typ string, raw json.RawMessage) (any, error) {
	var p migratePayload
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	dir, err := e.migrationDir(p.ID)
	if err != nil {
		return nil, err
	}
	archive := filepath.Join(dir, "archive.tar.gz")
	switch typ {
	case "migrate.fetch":
		return e.migrateFetch(ctx, dir, archive, p)
	case "migrate.inspect":
		kind, err := detectFormat(ctx, archive)
		if err != nil {
			return nil, err
		}
		if kind != fmtCPanel {
			return inspectPanel(ctx, archive)
		}
		return inspectCPanel(archive)
	case "migrate.extract":
		return e.migrateExtract(ctx, archive, p)
	case "migrate.import_db":
		return e.migrateImportDB(ctx, archive, p)
	case "migrate.cleanup":
		return map[string]any{"removed": true}, os.RemoveAll(dir)
	}
	return nil, fmt.Errorf("unknown migration command %s", typ)
}

// migrateFetch downloads the archive. A finished download (same SHA-256, or any
// complete file when no checksum is given) is kept, so a retried job resumes.
func (e *Executor) migrateFetch(ctx context.Context, dir, archive string, p migratePayload) (any, error) {
	if err := checkURL(p.URL); err != nil {
		return nil, err
	}
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, err
	}
	if fi, err := os.Stat(archive); err == nil {
		if sum, err := fileSHA256(archive); err == nil && (p.SHA256 == "" || strings.EqualFold(sum, p.SHA256)) {
			return map[string]any{"bytes": fi.Size(), "sha256": sum, "reused": true}, nil
		}
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, p.URL, nil)
	if err != nil {
		return nil, err
	}
	res, err := e.httpClient().Do(req)
	if err != nil {
		return nil, err
	}
	defer res.Body.Close()
	if res.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("download failed: HTTP %d", res.StatusCode)
	}
	tmp := archive + ".part"
	f, err := os.OpenFile(tmp, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, 0o600)
	if err != nil {
		return nil, err
	}
	h := sha256.New()
	n, err := io.Copy(io.MultiWriter(f, h), res.Body)
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		os.Remove(tmp)
		return nil, fmt.Errorf("download failed: %w", err)
	}
	sum := hex.EncodeToString(h.Sum(nil))
	if p.SHA256 != "" && !strings.EqualFold(sum, p.SHA256) {
		os.Remove(tmp)
		return nil, fmt.Errorf("checksum mismatch: got %s", sum)
	}
	if err := os.Rename(tmp, archive); err != nil {
		return nil, err
	}
	return map[string]any{"bytes": n, "sha256": sum}, nil
}

func fileSHA256(name string) (string, error) {
	f, err := os.Open(name)
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

// walkArchive calls fn for every entry, with the name relative to the archive's
// top folder (cpmove-<user>/ is stripped).
func walkArchive(archive string, fn func(rel string, h *tar.Header, r io.Reader) error) (string, error) {
	f, err := os.Open(archive)
	if err != nil {
		return "", fmt.Errorf("the archive has not been fetched: %w", err)
	}
	defer f.Close()
	gz, err := gzip.NewReader(bufio.NewReader(f))
	if err != nil {
		return "", fmt.Errorf("not a gzip archive: %w", err)
	}
	tr := tar.NewReader(gz)
	top := ""
	for {
		h, err := tr.Next()
		if err == io.EOF {
			return top, nil
		}
		if err != nil {
			return top, fmt.Errorf("reading the archive: %w", err)
		}
		name := strings.TrimPrefix(path.Clean("/"+h.Name), "/")
		first, rest, _ := strings.Cut(name, "/")
		if top == "" {
			top = first
		}
		if first != top || rest == "" {
			continue
		}
		if err := fn(rest, h, tr); err != nil {
			return top, err
		}
	}
}

// inspectCPanel is the pre-flight (MIG-002): account, domains with their document
// roots, databases, size, and which document roots hold WordPress.
func inspectCPanel(archive string) (any, error) {
	var mainYAML string
	docroots := map[string]string{}
	var databases []string
	wordpress := map[string]bool{}
	var files, bytes int64
	userdata := map[string]string{}
	top, err := walkArchive(archive, func(rel string, h *tar.Header, r io.Reader) error {
		if h.Typeflag == tar.TypeReg {
			files++
			bytes += h.Size
		}
		switch {
		case rel == "userdata/main":
			b, _ := io.ReadAll(io.LimitReader(r, 1<<20))
			mainYAML = string(b)
		case isUserdataDomain(rel):
			b, _ := io.ReadAll(io.LimitReader(r, 1<<20))
			userdata[path.Base(rel)] = string(b)
		case strings.HasPrefix(rel, "mysql/") && strings.HasSuffix(rel, ".sql") && strings.Count(rel, "/") == 1:
			databases = append(databases, strings.TrimSuffix(path.Base(rel), ".sql"))
		case strings.HasPrefix(rel, "homedir/") && path.Base(rel) == "wp-config.php":
			wordpress[path.Dir(strings.TrimPrefix(rel, "homedir/"))] = true
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	if mainYAML == "" {
		return nil, errors.New("this is not a cPanel backup: userdata/main is missing")
	}
	user := strings.TrimPrefix(top, "cpmove-")
	for d, body := range userdata {
		if root := yamlValue(body, "documentroot"); root != "" {
			docroots[d] = root
		}
	}
	home := "/home/" + user + "/"
	type site struct {
		Domain    string `json:"domain"`
		Kind      string `json:"kind"`
		Docroot   string `json:"docroot"`
		WordPress bool   `json:"wordpress"`
	}
	var sites []site
	add := func(domain, kind string) {
		if domain == "" {
			return
		}
		root := docroots[domain]
		if root == "" && kind == "main" {
			root = home + "public_html"
		}
		rel := strings.TrimPrefix(root, home)
		sites = append(sites, site{Domain: domain, Kind: kind, Docroot: rel, WordPress: wordpress[rel]})
	}
	add(yamlValue(mainYAML, "main_domain"), "main")
	for _, d := range yamlList(mainYAML, "addon_domains") {
		// An addon domain is served from the subdomain it is mapped to.
		if docroots[d] == "" {
			docroots[d] = docroots[yamlMapValue(mainYAML, "addon_domains", d)]
		}
		add(d, "addon")
	}
	for _, d := range yamlList(mainYAML, "sub_domains") {
		add(d, "subdomain")
	}
	return map[string]any{"source": fmtCPanel, "user": user, "sites": sites, "databases": databases, "files": files, "bytes": bytes}, nil
}

// isUserdataDomain matches userdata/<domain>, not its _SSL, .cache or .yaml siblings.
func isUserdataDomain(rel string) bool {
	name, ok := strings.CutPrefix(rel, "userdata/")
	if !ok || strings.Contains(name, "/") || !strings.Contains(name, ".") {
		return false
	}
	for _, suffix := range []string{"_SSL", ".cache", ".yaml", ".json", ".db"} {
		if strings.HasSuffix(name, suffix) {
			return false
		}
	}
	return true
}

// yamlValue reads a top-level "key: value" from cPanel's simple YAML.
func yamlValue(doc, key string) string {
	for _, line := range strings.Split(doc, "\n") {
		if k, v, ok := strings.Cut(line, ":"); ok && k == key {
			return strings.Trim(strings.TrimSpace(v), `'"`)
		}
	}
	return ""
}

// yamlMapValue reads one entry of an indented map under a top-level key.
func yamlMapValue(doc, key, name string) string {
	in := false
	for _, line := range strings.Split(doc, "\n") {
		if !strings.HasPrefix(line, " ") {
			k, _, _ := strings.Cut(line, ":")
			in = k == key
			continue
		}
		if k, v, ok := strings.Cut(strings.TrimSpace(line), ":"); in && ok && strings.Trim(k, `'"`) == name {
			return strings.Trim(strings.TrimSpace(v), `'"`)
		}
	}
	return ""
}

// yamlList reads the entries under a top-level key: "- item" lines, or the keys
// of an indented "name: value" map (addon_domains).
func yamlList(doc, key string) []string {
	var out []string
	in := false
	for _, line := range strings.Split(doc, "\n") {
		if !strings.HasPrefix(line, " ") && !strings.HasPrefix(line, "-") {
			k, v, _ := strings.Cut(line, ":")
			in = k == key && strings.TrimSpace(v) == ""
			continue
		}
		if !in {
			continue
		}
		t := strings.TrimSpace(line)
		if item, ok := strings.CutPrefix(t, "- "); ok {
			out = append(out, strings.Trim(item, `'"`))
		} else if k, _, ok := strings.Cut(t, ":"); ok {
			out = append(out, strings.Trim(k, `'"`))
		}
	}
	return out
}

// migrateExtract unpacks homedir/<from>/ into a volume (MIG-004). Regular files and
// folders only; links and devices are skipped. A file already there with the same
// size is skipped, so an interrupted copy resumes where it stopped.
func (e *Executor) migrateExtract(ctx context.Context, archive string, p migratePayload) (any, error) {
	from, err := cleanRel(p.From)
	if err != nil || from == "." {
		return nil, errors.New("a source folder under the home directory is required")
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
	owner := p.Owner
	if owner == 0 {
		owner = 33
	}
	kind, err := detectFormat(ctx, archive)
	if err != nil {
		return nil, err
	}
	// cPanel document roots are relative to the home directory; the others are virtual paths.
	prefix := from + "/"
	if kind == fmtCPanel {
		prefix = "homedir/" + prefix
	}
	want := func(p string) bool { return strings.HasPrefix(prefix, p) || strings.HasPrefix(p, prefix) }
	var files, skipped, resumed, bytes int64
	_, _, err = walkSource(ctx, archive, want, func(rel string, h *tar.Header, r io.Reader) error {
		if err := ctx.Err(); err != nil {
			return err
		}
		if !strings.HasPrefix(rel, prefix) {
			return nil
		}
		name, err := cleanRel(strings.TrimPrefix(rel, prefix))
		if err != nil || name == "." {
			return nil
		}
		switch h.Typeflag {
		case tar.TypeDir:
			if err := mkdirAll(root, name); err != nil {
				return err
			}
			chownIn(root, name, owner)
		case tar.TypeReg:
			if fi, err := root.Lstat(name); err == nil && fi.Mode().IsRegular() && fi.Size() == h.Size {
				resumed++
				return nil
			}
			if err := mkdirAll(root, path.Dir(name)); err != nil {
				return err
			}
			f, err := root.OpenFile(name, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, 0o644)
			if err != nil {
				return err
			}
			_ = f.Chown(owner, owner)
			n, err := io.Copy(f, r)
			if cerr := f.Close(); err == nil {
				err = cerr
			}
			if err != nil {
				return err
			}
			files++
			bytes += n
		default:
			skipped++
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	if files+resumed == 0 {
		return nil, fmt.Errorf("the archive has no files under %s", from)
	}
	return map[string]any{"files": files, "bytes": bytes, "resumed": resumed, "skipped": skipped}, nil
}

// migrateImportDB creates the database if needed and loads mysql/<sql>.sql into it.
func (e *Executor) migrateImportDB(ctx context.Context, archive string, p migratePayload) (any, error) {
	if !reDBName.MatchString(p.SQL) || !reDBName.MatchString(p.DB.Database) {
		return nil, errors.New("invalid database name")
	}
	if p.DB.Engine != "mysql" && p.DB.Engine != "mariadb" {
		return nil, errors.New("databases from a backup import into MySQL or MariaDB")
	}
	if !reRef.MatchString(p.DB.Container) {
		return nil, errors.New("invalid container name")
	}
	docker, err := e.docker()
	if err != nil {
		return nil, err
	}
	client := p.DB.Engine
	args, env := p.DB.execEnv(true)
	base := append(args, client, "-u", p.DB.user("root"))
	var sink strings.Builder
	create := strings.NewReader("CREATE DATABASE IF NOT EXISTS `" + p.DB.Database + "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;")
	if err := e.stream(ctx, env, create, &sink, docker, base...); err != nil {
		return nil, fmt.Errorf("creating the database: %w", err)
	}
	found := false
	var bytes int64
	want := "mysql/" + p.SQL + ".sql"
	_, _, err = walkSource(ctx, archive, func(prefix string) bool { return prefix == want }, func(rel string, h *tar.Header, r io.Reader) error {
		if rel != want || h.Typeflag != tar.TypeReg || found {
			return nil
		}
		found = true
		c := &countingReader{r: r}
		err := e.stream(ctx, env, c, &sink, docker, append(base, p.DB.Database)...)
		bytes = c.n
		return err
	})
	if err != nil {
		return nil, fmt.Errorf("importing %s: %w", p.SQL, err)
	}
	if !found {
		return nil, fmt.Errorf("mysql/%s.sql is not in the archive", p.SQL)
	}
	return map[string]any{"database": p.DB.Database, "bytes": bytes}, nil
}

// chownIn sets the owner of a folder opened through the root (best effort: the
// agent may not run as root in tests).
func chownIn(root *os.Root, name string, uid int) {
	if d, err := root.Open(name); err == nil {
		_ = d.Chown(uid, uid)
		d.Close()
	}
}

type countingReader struct {
	r io.Reader
	n int64
}

func (c *countingReader) Read(p []byte) (int, error) {
	n, err := c.r.Read(p)
	c.n += int64(n)
	return n, err
}
