package executor

import (
	"archive/zip"
	"bytes"
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"testing"
)

func runTransfer(t *testing.T, e *Executor, typ string, p map[string]any) (map[string]any, error) {
	t.Helper()
	p["volume"] = "site"
	raw, _ := json.Marshal(p)
	out, err := e.transfer(context.Background(), typ, raw)
	b, _ := json.Marshal(out)
	var m map[string]any
	_ = json.Unmarshal(b, &m)
	return m, err
}

func TestUploadWritesFilesAndCreatesFolders(t *testing.T) {
	e, dir := fsExec(t)
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		io.WriteString(w, "content of "+r.URL.Path)
	}))
	defer srv.Close()
	out, err := runTransfer(t, e, "fs.upload", map[string]any{"files": []map[string]string{
		{"path": "wp-content/themes/acme/style.css", "url": srv.URL + "/a"},
		{"path": "index.html", "url": srv.URL + "/b"},
	}})
	if err != nil || out["files"].(float64) != 2 {
		t.Fatalf("upload: %v %v", out, err)
	}
	b, _ := os.ReadFile(filepath.Join(dir, "wp-content/themes/acme/style.css"))
	if string(b) != "content of /a" {
		t.Fatalf("wrong content %q", b)
	}
	if _, err := runTransfer(t, e, "fs.upload", map[string]any{"files": []map[string]string{{"path": "index.html", "url": srv.URL + "/c"}}}); err == nil {
		t.Fatal("an upload must not replace an existing file unless overwrite is set")
	}
	if _, err := runTransfer(t, e, "fs.upload", map[string]any{"overwrite": true, "files": []map[string]string{{"path": "index.html", "url": srv.URL + "/c"}}}); err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(filepath.Join(dir, "index.html")); string(b) != "content of /c" {
		t.Fatalf("overwrite did not replace the file: %q", b)
	}
}

func TestUploadStaysInsideTheVolume(t *testing.T) {
	e, dir := fsExec(t)
	outside := t.TempDir()
	os.Symlink(outside, filepath.Join(dir, "escape"))
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { io.WriteString(w, "x") }))
	defer srv.Close()
	for _, p := range []string{"escape/evil.php", "escape/sub/evil.php"} {
		if _, err := runTransfer(t, e, "fs.upload", map[string]any{"files": []map[string]string{{"path": p, "url": srv.URL}}}); err == nil {
			t.Errorf("upload to %s followed a symlink out of the volume", p)
		}
	}
	entries, _ := os.ReadDir(outside)
	if len(entries) != 0 {
		t.Fatalf("files were written outside the volume: %v", entries)
	}
	if _, err := runTransfer(t, e, "fs.upload", map[string]any{"files": []map[string]string{{"path": "a.txt", "url": "file:///etc/passwd"}}}); err == nil {
		t.Fatal("only http(s) sources may be fetched")
	}
}

func TestUploadRemovesAPartialFileWhenTheSourceFails(t *testing.T) {
	e, dir := fsExec(t)
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { http.Error(w, "gone", http.StatusGone) }))
	defer srv.Close()
	if _, err := runTransfer(t, e, "fs.upload", map[string]any{"files": []map[string]string{{"path": "a.txt", "url": srv.URL}}}); err == nil {
		t.Fatal("a failed fetch must fail the upload")
	}
	if _, err := os.Stat(filepath.Join(dir, "a.txt")); err == nil {
		t.Fatal("a failed upload left a file behind")
	}
}

// receiver captures what the agent sends to the panel.
func receiver(t *testing.T) (*httptest.Server, *bytes.Buffer, *string) {
	t.Helper()
	var got bytes.Buffer
	ctype := ""
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPut {
			http.Error(w, "method", http.StatusMethodNotAllowed)
			return
		}
		ctype = r.Header.Get("Content-Type")
		io.Copy(&got, r.Body)
		w.WriteHeader(http.StatusCreated)
	}))
	t.Cleanup(srv.Close)
	return srv, &got, &ctype
}

func TestDownloadSendsASingleFileAsIs(t *testing.T) {
	e, dir := fsExec(t)
	os.WriteFile(filepath.Join(dir, "wp-config.php"), []byte("<?php // config"), 0o640)
	srv, got, ctype := receiver(t)
	out, err := runTransfer(t, e, "fs.download", map[string]any{"paths": []string{"wp-config.php"}, "upload": map[string]string{"url": srv.URL, "method": "PUT"}})
	if err != nil || out["format"] != "file" || out["name"] != "wp-config.php" {
		t.Fatalf("download: %v %v", out, err)
	}
	if got.String() != "<?php // config" || *ctype != "application/octet-stream" {
		t.Fatalf("sent %q as %s", got.String(), *ctype)
	}
}

func TestDownloadZipsFoldersWithoutFollowingLinks(t *testing.T) {
	e, dir := fsExec(t)
	outside := t.TempDir()
	os.WriteFile(filepath.Join(outside, "secret"), []byte("s"), 0o600)
	os.MkdirAll(filepath.Join(dir, "theme", "css"), 0o755)
	os.WriteFile(filepath.Join(dir, "theme", "css", "a.css"), []byte("a{}"), 0o644)
	os.WriteFile(filepath.Join(dir, "readme.txt"), []byte("hi"), 0o644)
	os.Symlink(outside, filepath.Join(dir, "theme", "out"))
	srv, got, _ := receiver(t)
	out, err := runTransfer(t, e, "fs.download", map[string]any{"paths": []string{"theme", "readme.txt"}, "upload": map[string]string{"url": srv.URL}})
	if err != nil || out["format"] != "zip" || out["files"].(float64) != 2 {
		t.Fatalf("download: %v %v", out, err)
	}
	zr, err := zip.NewReader(bytes.NewReader(got.Bytes()), int64(got.Len()))
	if err != nil {
		t.Fatal(err)
	}
	var names []string
	for _, f := range zr.File {
		names = append(names, f.Name)
	}
	sort.Strings(names)
	want := "readme.txt theme/ theme/css/ theme/css/a.css"
	if strings.Join(names, " ") != want {
		t.Fatalf("zip has %v, want %s (the symlink must be skipped)", names, want)
	}
}
