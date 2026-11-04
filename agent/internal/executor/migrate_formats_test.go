package executor

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"encoding/json"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

type tarFile struct{ name, body string }

func tarOf(t *testing.T, compress bool, files ...tarFile) []byte {
	t.Helper()
	var buf bytes.Buffer
	var w io.Writer = &buf
	var gz *gzip.Writer
	if compress {
		gz = gzip.NewWriter(&buf)
		w = gz
	}
	tw := tar.NewWriter(w)
	for _, f := range files {
		typ := byte(tar.TypeReg)
		if strings.HasSuffix(f.name, "/") {
			typ = tar.TypeDir
		}
		if err := tw.WriteHeader(&tar.Header{Name: f.name, Typeflag: typ, Size: int64(len(f.body)), Mode: 0o644, Format: tar.FormatUSTAR}); err != nil {
			t.Fatal(err)
		}
		tw.Write([]byte(f.body))
	}
	tw.Close()
	if gz != nil {
		gz.Close()
	}
	return buf.Bytes()
}

func gzipOf(s string) string {
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	gz.Write([]byte(s))
	gz.Close()
	return buf.String()
}

// hestiaArchive is a HestiaCP user backup: a plain tar with per-domain data archives.
func hestiaArchive(t *testing.T, webData string) []byte {
	shop := tarOf(t, true, tarFile{"./public_html/", ""}, tarFile{"./public_html/index.php", "<?php echo 1;"}, tarFile{"./public_html/wp-config.php", "<?php // wp"},
		tarFile{"./public_html/../../escape.txt", "x"}, tarFile{"./document_errors/404.html", "nf"})
	blog := tarOf(t, true, tarFile{"./public_html/index.html", "blog"})
	if webData != "" {
		shop = []byte(webData)
	}
	return tarOf(t, false,
		tarFile{"./pam/passwd", "shop:x:1001:1001::/home/shop:/bin/bash\n"},
		tarFile{"./hestia/user.conf", "NAME='Shop'\n"},
		tarFile{"./web/shop.lk/hestia/web.conf", "DOMAIN='shop.lk' ALIAS='www.shop.lk'\n"},
		tarFile{"./web/shop.lk/domain_data.tar.gz", string(shop)},
		tarFile{"./web/blog.lk/domain_data.tar.gz", string(blog)},
		tarFile{"./db/shop_wp/hestia/db.conf", "DB='shop_wp' TYPE='mysql'\n"},
		tarFile{"./db/shop_wp/shop_wp.mysql.sql.gz", gzipOf("CREATE TABLE wp_posts (id int);\n")},
	)
}

// pleskArchive is a Plesk backup export: XML description, user data and database dumps as tgz.
func pleskArchive(t *testing.T) []byte {
	data := tarOf(t, true, tarFile{"httpdocs/index.php", "<?php echo 2;"}, tarFile{"httpdocs/wp-config.php", "<?php // wp"}, tarFile{"httpdocs/wp-content/a.jpg", "jpeg"}, tarFile{"logs/access_log", "x"})
	dump := tarOf(t, true, tarFile{"wpdb", "CREATE TABLE wp_options (id int);\n"})
	return tarOf(t, true,
		tarFile{"backup_info_2611011200.xml", "<migration-dump/>"},
		tarFile{"clients/acme/domains/shop.lk/backup_user-data_2611011200.tgz", string(data)},
		tarFile{"clients/acme/domains/shop.lk/databases/wpdb_1/backup_sqldump_2611011200.tgz", string(dump)},
		tarFile{"clients/acme/domains/static.lk/backup_info_2611011200.xml", "<domain/>"},
	)
}

func runMigrate(t *testing.T, e *Executor, typ string, p map[string]any) (map[string]any, error) {
	t.Helper()
	raw, _ := json.Marshal(p)
	out, err := e.migrate(context.Background(), typ, raw)
	if err != nil {
		return nil, err
	}
	b, _ := json.Marshal(out)
	var m map[string]any
	json.Unmarshal(b, &m)
	return m, nil
}

func stage(t *testing.T, state, id string, data []byte) {
	dir := filepath.Join(state, "migrations", id)
	os.MkdirAll(dir, 0o700)
	if err := os.WriteFile(filepath.Join(dir, "archive.tar.gz"), data, 0o600); err != nil {
		t.Fatal(err)
	}
}

func TestMigrateHestiaAndPleskBackups(t *testing.T) {
	for _, c := range []struct {
		name, from, sql, sqlWant, file, sites string
		data                                  []byte
		files                                 float64
	}{
		{"hestia", "web/shop.lk/public_html", "shop_wp", "wp_posts", "index.php", `[{"docroot":"web/blog.lk/public_html","domain":"blog.lk","kind":"main","wordpress":false},{"docroot":"web/shop.lk/public_html","domain":"shop.lk","kind":"addon","wordpress":true}]`, hestiaArchive(t, ""), 2},
		{"plesk", "domains/shop.lk/httpdocs", "wpdb", "wp_options", "wp-content/a.jpg", `[{"docroot":"domains/shop.lk/httpdocs","domain":"shop.lk","kind":"main","wordpress":true},{"docroot":"domains/static.lk/httpdocs","domain":"static.lk","kind":"addon","wordpress":false}]`, pleskArchive(t), 3},
	} {
		t.Run(c.name, func(t *testing.T) {
			state, vol := t.TempDir(), t.TempDir()
			var inputs []string
			e := &Executor{StateDir: state, VolumeRoot: func(context.Context, string) (string, error) { return vol, nil },
				LookPath: func(string) (string, error) { return "docker", nil },
				Stream: func(_ context.Context, _ []string, stdin io.Reader, _ io.Writer, _ string, _ ...string) error {
					b, _ := io.ReadAll(stdin)
					inputs = append(inputs, string(b))
					return nil
				}}
			stage(t, state, "m1", c.data)

			inv, err := runMigrate(t, e, "migrate.inspect", map[string]any{"id": "m1"})
			if err != nil {
				t.Fatal(err)
			}
			b, _ := json.Marshal(inv["sites"])
			if inv["source"] != c.name || string(b) != c.sites {
				t.Fatalf("inspect %v\n got %s\nwant %s", inv["source"], b, c.sites)
			}
			if d, _ := json.Marshal(inv["databases"]); string(d) != `["`+c.sql+`"]` {
				t.Fatalf("databases %s", d)
			}

			out, err := runMigrate(t, e, "migrate.extract", map[string]any{"id": "m1", "from": c.from, "volume": "app-shop-data"})
			if err != nil || out["files"].(float64) != c.files {
				t.Fatalf("extract: %v %v", out, err)
			}
			if _, err := os.Stat(filepath.Join(vol, c.file)); err != nil {
				t.Fatalf("%s missing: %v", c.file, err)
			}
			if _, err := os.Stat(filepath.Join(filepath.Dir(vol), "escape.txt")); err == nil {
				t.Fatal("an entry escaped the volume")
			}
			if _, err := os.Stat(filepath.Join(vol, "document_errors")); err == nil {
				t.Fatal("only the document root is copied")
			}
			if out, _ := runMigrate(t, e, "migrate.extract", map[string]any{"id": "m1", "from": c.from, "volume": "v"}); out["resumed"].(float64) != c.files {
				t.Fatalf("a second run must resume: %v", out)
			}

			out, err = runMigrate(t, e, "migrate.import_db", map[string]any{"id": "m1", "sql": c.sql, "db": map[string]any{"engine": "mariadb", "container": "db-shop", "database": "shop"}})
			if err != nil || len(inputs) != 2 || !strings.Contains(inputs[1], c.sqlWant) || out["bytes"].(float64) == 0 {
				t.Fatalf("import %v %v %q", out, err, inputs)
			}
			if _, err := runMigrate(t, e, "migrate.import_db", map[string]any{"id": "m1", "sql": "missing", "db": map[string]any{"engine": "mysql", "container": "c", "database": "d"}}); err == nil {
				t.Fatal("a missing database must fail")
			}
		})
	}
}

func TestMigrateDetectsUnknownBackups(t *testing.T) {
	state := t.TempDir()
	stage(t, state, "m1", tarOf(t, true, tarFile{"random/file.txt", "x"}))
	e := &Executor{StateDir: state}
	if _, err := runMigrate(t, e, "migrate.inspect", map[string]any{"id": "m1"}); err == nil || !strings.Contains(err.Error(), "cPanel, Plesk or HestiaCP") {
		t.Fatalf("got %v", err)
	}
}

func TestMigrateHestiaZstd(t *testing.T) {
	if _, err := exec.LookPath("zstd"); err != nil {
		t.Skip("zstd is not installed")
	}
	inner := tarOf(t, false, tarFile{"./public_html/index.php", "<?php echo 3;"})
	cmd := exec.Command("zstd", "-c")
	cmd.Stdin = bytes.NewReader(inner)
	zst, err := cmd.Output()
	if err != nil {
		t.Fatal(err)
	}
	state, vol := t.TempDir(), t.TempDir()
	stage(t, state, "m1", hestiaArchive(t, string(zst)))
	e := &Executor{StateDir: state, VolumeRoot: func(context.Context, string) (string, error) { return vol, nil }}
	if out, err := runMigrate(t, e, "migrate.extract", map[string]any{"id": "m1", "from": "web/shop.lk/public_html", "volume": "v"}); err != nil || out["files"].(float64) != 1 {
		t.Fatalf("zstd extract: %v %v", out, err)
	}
}
