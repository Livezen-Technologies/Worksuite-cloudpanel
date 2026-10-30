package executor

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func cpanelArchive(t *testing.T) []byte {
	t.Helper()
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	add := func(name, body string) {
		typ := byte(tar.TypeReg)
		if strings.HasSuffix(name, "/") {
			typ = tar.TypeDir
		}
		if err := tw.WriteHeader(&tar.Header{Name: name, Typeflag: typ, Size: int64(len(body)), Mode: 0o644}); err != nil {
			t.Fatal(err)
		}
		tw.Write([]byte(body))
	}
	add("cpmove-shop/", "")
	add("cpmove-shop/userdata/main", "---\naddon_domains:\n  blog.lk: blog.shop.lk\nmain_domain: shop.lk\nparked_domains: []\nsub_domains:\n  - blog.shop.lk\n")
	add("cpmove-shop/userdata/shop.lk", "documentroot: /home/shop/public_html\n")
	add("cpmove-shop/userdata/blog.shop.lk", "documentroot: /home/shop/blog\n")
	add("cpmove-shop/userdata/shop.lk_SSL", "documentroot: /home/shop/public_html\n")
	add("cpmove-shop/mysql/shop_wp.sql", "CREATE TABLE wp_posts (id int);\n")
	add("cpmove-shop/homedir/public_html/index.php", "<?php echo 1;")
	add("cpmove-shop/homedir/public_html/wp-config.php", "<?php // wp")
	add("cpmove-shop/homedir/public_html/wp-content/uploads/a.jpg", "jpeg")
	add("cpmove-shop/homedir/blog/index.html", "blog")
	tw.WriteHeader(&tar.Header{Name: "cpmove-shop/homedir/public_html/evil", Typeflag: tar.TypeSymlink, Linkname: "/etc/passwd"})
	add("cpmove-shop/homedir/public_html/../../escape.txt", "x")
	tw.Close()
	gz.Close()
	return buf.Bytes()
}

func TestMigrateFetchInspectExtract(t *testing.T) {
	data := cpanelArchive(t)
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { w.Write(data) }))
	defer srv.Close()
	state, vol := t.TempDir(), t.TempDir()
	e := &Executor{StateDir: state, VolumeRoot: func(context.Context, string) (string, error) { return vol, nil }}
	ctx := context.Background()
	run := func(typ string, p map[string]any) (map[string]any, error) {
		raw, _ := json.Marshal(p)
		out, err := e.migrate(ctx, typ, raw)
		if err != nil {
			return nil, err
		}
		b, _ := json.Marshal(out)
		var m map[string]any
		json.Unmarshal(b, &m)
		return m, nil
	}
	if _, err := run("migrate.fetch", map[string]any{"id": "m1", "url": srv.URL, "sha256": strings.Repeat("0", 64)}); err == nil {
		t.Fatal("a checksum mismatch must fail")
	}
	out, err := run("migrate.fetch", map[string]any{"id": "m1", "url": srv.URL})
	if err != nil || out["bytes"].(float64) != float64(len(data)) {
		t.Fatalf("fetch: %v %v", out, err)
	}
	if out, _ := run("migrate.fetch", map[string]any{"id": "m1", "url": srv.URL}); out["reused"] != true {
		t.Fatal("a finished download must be reused")
	}
	if _, err := run("migrate.fetch", map[string]any{"id": "../x", "url": srv.URL}); err == nil {
		t.Fatal("bad id must be refused")
	}

	inv, err := run("migrate.inspect", map[string]any{"id": "m1"})
	if err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(inv["sites"])
	want := `[{"docroot":"public_html","domain":"shop.lk","kind":"main","wordpress":true},{"docroot":"blog","domain":"blog.lk","kind":"addon","wordpress":false},{"docroot":"blog","domain":"blog.shop.lk","kind":"subdomain","wordpress":false}]`
	if string(b) != want || inv["user"] != "shop" {
		t.Fatalf("sites\n got %s\nwant %s", b, want)
	}
	if d, _ := json.Marshal(inv["databases"]); string(d) != `["shop_wp"]` {
		t.Fatalf("databases %s", d)
	}

	out, err = run("migrate.extract", map[string]any{"id": "m1", "from": "public_html", "volume": "app-shop-data"})
	if err != nil || out["files"].(float64) != 3 || out["skipped"].(float64) != 1 {
		t.Fatalf("extract: %v %v", out, err)
	}
	if b, _ := os.ReadFile(filepath.Join(vol, "wp-content/uploads/a.jpg")); string(b) != "jpeg" {
		t.Fatal("nested file missing")
	}
	if _, err := os.Lstat(filepath.Join(vol, "evil")); err == nil {
		t.Fatal("symlinks must not be extracted")
	}
	if _, err := os.Stat(filepath.Join(filepath.Dir(vol), "escape.txt")); err == nil {
		t.Fatal("an entry escaped the volume")
	}
	if out, _ = run("migrate.extract", map[string]any{"id": "m1", "from": "public_html", "volume": "v"}); out["resumed"].(float64) != 3 || out["files"].(float64) != 0 {
		t.Fatalf("a second run must resume: %v", out)
	}
	if _, err := run("migrate.extract", map[string]any{"id": "m1", "from": "../", "volume": "v"}); err == nil {
		t.Fatal("from must stay in the home directory")
	}
	if _, err := run("migrate.cleanup", map[string]any{"id": "m1"}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(state, "migrations", "m1")); err == nil {
		t.Fatal("cleanup left the archive")
	}
}

func TestMigrateImportDBStreamsTheDump(t *testing.T) {
	state := t.TempDir()
	dir := filepath.Join(state, "migrations", "m2")
	os.MkdirAll(dir, 0o700)
	os.WriteFile(filepath.Join(dir, "archive.tar.gz"), cpanelArchive(t), 0o600)
	var calls []string
	var inputs []string
	e := &Executor{StateDir: state, LookPath: func(string) (string, error) { return "docker", nil },
		Stream: func(_ context.Context, env []string, stdin io.Reader, _ io.Writer, name string, args ...string) error {
			b, _ := io.ReadAll(stdin)
			calls = append(calls, strings.Join(args, " "))
			inputs = append(inputs, string(b))
			return nil
		}}
	raw := `{"id":"m2","sql":"shop_wp","db":{"engine":"mariadb","container":"db-shop","database":"shop","password":"pw"}}`
	if _, err := e.migrate(context.Background(), "migrate.import_db", json.RawMessage(raw)); err != nil {
		t.Fatal(err)
	}
	if len(calls) != 2 || calls[1] != "exec -i -e MYSQL_PWD db-shop mariadb -u root shop" || !strings.Contains(inputs[0], "CREATE DATABASE IF NOT EXISTS `shop`") || !strings.Contains(inputs[1], "wp_posts") {
		t.Fatalf("calls %q inputs %q", calls, inputs)
	}
	for _, bad := range []string{`{"id":"m2","sql":"x;y","db":{"engine":"mysql","container":"c","database":"d"}}`, `{"id":"m2","sql":"shop_wp","db":{"engine":"postgres","container":"c","database":"d"}}`, `{"id":"m2","sql":"missing","db":{"engine":"mysql","container":"c","database":"d"}}`} {
		if _, err := e.migrate(context.Background(), "migrate.import_db", json.RawMessage(bad)); err == nil {
			t.Errorf("%s must fail", bad)
		}
	}
}
