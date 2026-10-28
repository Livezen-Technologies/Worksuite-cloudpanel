package update

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
)

func TestApplyAndRollback(t *testing.T) {
	pub, priv, _ := ed25519.GenerateKey(rand.Reader)
	newBin := []byte("#!/bin/sh\necho v2\n")
	sig := base64.StdEncoding.EncodeToString(ed25519.Sign(priv, newBin))
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write(newBin) }))
	defer srv.Close()

	dir := t.TempDir()
	bin := filepath.Join(dir, "agent")
	os.WriteFile(bin, []byte("v1"), 0o755)
	u := &Updater{PublicKey: pub, BinaryPath: bin, HTTP: srv.Client(),
		HealthCheck: func(context.Context, string) error { return nil }}

	if err := u.Apply(context.Background(), srv.URL, base64.StdEncoding.EncodeToString(make([]byte, 64)), ""); err == nil {
		t.Fatal("bad signature accepted")
	}
	if b, _ := os.ReadFile(bin); string(b) != "v1" {
		t.Fatal("binary changed after failed verify")
	}
	if err := u.Apply(context.Background(), srv.URL, sig, ""); err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(bin); string(b) != string(newBin) {
		t.Fatal("not swapped")
	}
	if b, _ := os.ReadFile(bin + ".old"); string(b) != "v1" {
		t.Fatal("old not kept")
	}
	if err := u.Rollback(); err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(bin); string(b) != "v1" {
		t.Fatal("rollback failed")
	}
	if _, err := ParsePublicKey(base64.StdEncoding.EncodeToString(pub)); err != nil {
		t.Fatal(err)
	}
}

func TestNoKeyRefuses(t *testing.T) {
	u := &Updater{BinaryPath: filepath.Join(t.TempDir(), "a")}
	if err := u.Apply(context.Background(), "https://x", "", ""); err == nil {
		t.Fatal("expected refusal")
	}
}
