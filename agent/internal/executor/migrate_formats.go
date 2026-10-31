package executor

import (
	"archive/tar"
	"bufio"
	"bytes"
	"compress/gzip"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path"
	"regexp"
	"sort"
	"strings"
)

// Backup formats for the migration wizard. Each is read as one virtual tree so the
// rest of the code does not care where a backup came from:
//
//	cPanel   cpmove-<user>.tar.gz   homedir/<docroot>/…, mysql/<db>.sql (as stored)
//	HestiaCP <user>.<date>.tar      web/<domain>/domain_data.tar.{gz,zst} → web/<domain>/public_html/…
//	                                db/<db>/<db>.mysql.sql.{gz,zst}       → mysql/<db>.sql
//	Plesk    backup export .tar     …/domains/<domain>/*user-data*.tgz    → domains/<domain>/httpdocs/…
//	                                …/databases/<db>_1/*.tgz | .sql.gz    → mysql/<db>.sql
//
// Nested archives are decompressed as streams; nothing is unpacked to disk first.

const (
	fmtCPanel = "cpanel"
	fmtHestia = "hestia"
	fmtPlesk  = "plesk"
)

var (
	reHestiaWeb = regexp.MustCompile(`^web/([^/]+)/domain_data\.tar(\.gz|\.zst)?$`)
	reHestiaDB  = regexp.MustCompile(`^db/([A-Za-z0-9_]+)/[^/]+\.(?:mysql|mariadb)\.sql(\.gz|\.zst)?$`)
	reHestiaDom = regexp.MustCompile(`^web/([^/]+)/`)
	rePleskData = regexp.MustCompile(`(?:^|/)domains/([^/]+)/[^/]*user-data[^/]*$`)
	rePleskDB   = regexp.MustCompile(`(?:^|/)databases/([A-Za-z0-9_]+?)(?:_\d+)?/[^/]+$`)
	rePleskDom  = regexp.MustCompile(`(?:^|/)domains/([^/]+)/`)
	reDomain    = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$`)
)

// zstdCommand decompresses zstd (HestiaCP's default); the host's zstd tool does it.
var zstdCommand = func(ctx context.Context, r io.Reader) (io.Reader, func() error, error) {
	if _, err := exec.LookPath("zstd"); err != nil {
		return nil, nil, errors.New("this backup is compressed with zstd; install zstd on the server (apt install zstd)")
	}
	cmd := exec.CommandContext(ctx, "zstd", "-dc")
	cmd.Stdin = r
	out, err := cmd.StdoutPipe()
	if err != nil {
		return nil, nil, err
	}
	if err := cmd.Start(); err != nil {
		return nil, nil, err
	}
	return out, cmd.Wait, nil
}

// decoded unwraps gzip or zstd by their magic bytes; anything else is returned as is.
func decoded(ctx context.Context, r io.Reader) (io.Reader, func() error, error) {
	br := bufio.NewReaderSize(r, 64<<10)
	magic, _ := br.Peek(4)
	switch {
	case len(magic) >= 2 && magic[0] == 0x1f && magic[1] == 0x8b:
		gz, err := gzip.NewReader(br)
		if err != nil {
			return nil, nil, err
		}
		return gz, gz.Close, nil
	case bytes.Equal(magic, []byte{0x28, 0xb5, 0x2f, 0xfd}):
		return zstdCommand(ctx, br)
	}
	return br, func() error { return nil }, nil
}

// isTar reports whether r starts with a tar header (ustar magic at 257).
func isTar(br *bufio.Reader) bool {
	b, _ := br.Peek(262)
	return len(b) >= 262 && string(b[257:262]) == "ustar"
}

// walkRaw calls fn for every entry of the outer archive (gzip or plain tar), names cleaned.
func walkRaw(ctx context.Context, archive string, fn func(name string, h *tar.Header, r io.Reader) error) error {
	f, err := os.Open(archive)
	if err != nil {
		return fmt.Errorf("the archive has not been fetched: %w", err)
	}
	defer f.Close()
	r, done, err := decoded(ctx, f)
	if err != nil {
		return fmt.Errorf("reading the archive: %w", err)
	}
	defer done()
	tr := tar.NewReader(r)
	for {
		h, err := tr.Next()
		if err == io.EOF {
			return nil
		}
		if err != nil {
			return fmt.Errorf("reading the archive: %w", err)
		}
		name := strings.TrimPrefix(path.Clean("/"+h.Name), "/")
		if name == "" {
			continue
		}
		if err := fn(name, h, tr); err != nil {
			return err
		}
	}
}

var errFound = errors.New("found")

// detectFormat reads entry names until a marker of one of the formats shows up.
func detectFormat(ctx context.Context, archive string) (string, error) {
	kind := ""
	err := walkRaw(ctx, archive, func(name string, _ *tar.Header, _ io.Reader) error {
		_, rest, _ := strings.Cut(name, "/")
		switch {
		case rest == "userdata/main":
			kind = fmtCPanel
		case name == "hestia/user.conf" || name == "pam/passwd" || reHestiaWeb.MatchString(name):
			kind = fmtHestia
		case strings.HasPrefix(path.Base(name), "backup_info_") && strings.HasSuffix(name, ".xml"), rePleskData.MatchString(name):
			kind = fmtPlesk
		default:
			return nil
		}
		return errFound
	})
	if err != nil && !errors.Is(err, errFound) {
		return "", err
	}
	if kind == "" {
		return "", errors.New("this is not a cPanel, Plesk or HestiaCP backup")
	}
	return kind, nil
}

// walkSource walks the backup as a virtual tree (see the top of this file). want is asked
// before a nested archive is opened, with the prefix its entries get ("web/<d>/",
// "mysql/<db>.sql"); returning false skips it (its entry is still reported, empty).
func walkSource(ctx context.Context, archive string, want func(prefix string) bool, fn func(vpath string, h *tar.Header, r io.Reader) error) (kind, top string, err error) {
	kind, err = detectFormat(ctx, archive)
	if err != nil {
		return "", "", err
	}
	if kind == fmtCPanel {
		top, err = walkArchive(archive, fn)
		return kind, top, err
	}
	sqlEntry := func(db string, r io.Reader) error {
		return fn("mysql/"+db+".sql", &tar.Header{Typeflag: tar.TypeReg, Size: -1}, r)
	}
	err = walkRaw(ctx, archive, func(name string, h *tar.Header, r io.Reader) error {
		if err := ctx.Err(); err != nil {
			return err
		}
		if h.Typeflag != tar.TypeReg {
			return nil
		}
		var prefix, db string
		if kind == fmtHestia {
			if m := reHestiaWeb.FindStringSubmatch(name); m != nil {
				prefix = "web/" + m[1] + "/"
			} else if m := reHestiaDB.FindStringSubmatch(name); m != nil {
				db = m[1]
			}
		} else {
			if m := rePleskData.FindStringSubmatch(name); m != nil {
				prefix = "domains/" + m[1] + "/"
			} else if m := rePleskDB.FindStringSubmatch(name); m != nil {
				db = m[1]
			}
		}
		switch {
		case prefix != "":
			if want != nil && !want(prefix) {
				return fn(prefix, &tar.Header{Typeflag: tar.TypeDir}, strings.NewReader(""))
			}
			return walkInnerTar(ctx, r, prefix, fn)
		case db != "":
			if want != nil && !want("mysql/"+db+".sql") {
				return sqlEntry(db, strings.NewReader(""))
			}
			return openSQL(ctx, r, func(sql io.Reader) error { return sqlEntry(db, sql) })
		}
		return fn(name, h, r)
	})
	return kind, "", err
}

// walkInnerTar emits the entries of a nested (possibly compressed) tar under prefix.
func walkInnerTar(ctx context.Context, r io.Reader, prefix string, fn func(string, *tar.Header, io.Reader) error) error {
	inner, done, err := decoded(ctx, r)
	if err != nil {
		return err
	}
	tr := tar.NewReader(inner)
	for {
		h, err := tr.Next()
		if err == io.EOF {
			break
		}
		if err != nil {
			done()
			return fmt.Errorf("reading %s: %w", strings.TrimSuffix(prefix, "/"), err)
		}
		name := strings.TrimPrefix(path.Clean("/"+h.Name), "/")
		if name == "" {
			continue
		}
		if err := fn(prefix+name, h, tr); err != nil {
			done()
			return err
		}
	}
	return done()
}

// openSQL hands fn the SQL dump inside r: a (compressed) .sql file, or the first .sql
// file of a (compressed) tar, as Plesk stores them.
func openSQL(ctx context.Context, r io.Reader, fn func(io.Reader) error) error {
	inner, done, err := decoded(ctx, r)
	if err != nil {
		return err
	}
	br := bufio.NewReaderSize(inner, 64<<10)
	if !isTar(br) {
		if err := fn(br); err != nil {
			done()
			return err
		}
		return done()
	}
	tr := tar.NewReader(br)
	for {
		h, err := tr.Next()
		if err == io.EOF {
			done()
			return errors.New("the database archive has no .sql file")
		}
		if err != nil {
			done()
			return err
		}
		if h.Typeflag == tar.TypeReg && (strings.HasSuffix(h.Name, ".sql") || !strings.Contains(path.Base(h.Name), ".")) {
			if err := fn(tr); err != nil {
				done()
				return err
			}
			return done()
		}
	}
}

// inspectPanel is the pre-flight (MIG-002) for HestiaCP and Plesk backups.
func inspectPanel(ctx context.Context, archive string) (any, error) {
	domains := map[string]bool{}
	wordpress := map[string]bool{}
	dbs := map[string]bool{}
	var files, size int64
	user := ""
	kind, _, err := walkSource(ctx, archive, func(prefix string) bool { return !strings.HasPrefix(prefix, "mysql/") }, func(vpath string, h *tar.Header, r io.Reader) error {
		if h.Typeflag == tar.TypeReg && h.Size >= 0 {
			files++
			size += h.Size
		}
		switch {
		case strings.HasPrefix(vpath, "mysql/"):
			dbs[strings.TrimSuffix(path.Base(vpath), ".sql")] = true
		case path.Base(vpath) == "wp-config.php":
			wordpress[path.Dir(vpath)] = true
		case vpath == "pam/passwd" && user == "":
			b, _ := io.ReadAll(io.LimitReader(r, 4096))
			user, _, _ = strings.Cut(string(b), ":")
		}
		for _, re := range []*regexp.Regexp{reHestiaDom, rePleskDom} {
			if m := re.FindStringSubmatch(vpath); m != nil && reDomain.MatchString(m[1]) {
				domains[m[1]] = true
			}
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	type site struct {
		Domain    string `json:"domain"`
		Kind      string `json:"kind"`
		Docroot   string `json:"docroot"`
		WordPress bool   `json:"wordpress"`
	}
	names := make([]string, 0, len(domains))
	for d := range domains {
		names = append(names, d)
	}
	sort.Strings(names)
	sites := []site{}
	for i, d := range names {
		root := "web/" + d + "/public_html"
		if kind == fmtPlesk {
			root = "domains/" + d + "/httpdocs"
		}
		k := "addon"
		if i == 0 {
			k = "main"
		}
		sites = append(sites, site{Domain: d, Kind: k, Docroot: root, WordPress: wordpress[root]})
	}
	databases := make([]string, 0, len(dbs))
	for d := range dbs {
		databases = append(databases, d)
	}
	sort.Strings(databases)
	return map[string]any{"source": kind, "user": user, "sites": sites, "databases": databases, "files": files, "bytes": size}, nil
}
