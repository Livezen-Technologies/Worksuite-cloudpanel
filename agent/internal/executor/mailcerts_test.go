package executor

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestMailCertsCopyFromAcmeOrFallBack(t *testing.T) {
	vol, state := t.TempDir(), t.TempDir()
	acme := filepath.Join(state, "acme.json")
	e := &Executor{AcmeJSON: acme, VolumeRoot: func(context.Context, string) (string, error) { return vol, nil }}
	run := func(p string) map[string]any {
		out, err := e.mailCerts(context.Background(), json.RawMessage(p))
		if err != nil {
			t.Fatal(err)
		}
		return out.(map[string]any)
	}
	if out := run(`{"hostname":"mail.example.com","volume":"mailserver-config"}`); out["found"] != false {
		t.Fatalf("no acme, no fallback: %v", out)
	}
	out := run(`{"hostname":"mail.example.com","volume":"mailserver-config","fallback":true}`)
	if out["source"] != "self-signed" || out["changed"] != true {
		t.Fatalf("fallback: %v", out)
	}
	if fi, _ := os.Stat(filepath.Join(vol, "ssl/key.pem")); fi.Mode().Perm() != 0o600 {
		t.Fatal("the key must be 0600")
	}
	// A wildcard certificate in Traefik's store replaces the self-signed one.
	cert, key, _, _ := selfSigned("example.com")
	store := map[string]any{"le": map[string]any{"Certificates": []any{map[string]any{
		"domain": map[string]any{"main": "*.example.com"}, "certificate": base64.StdEncoding.EncodeToString(cert), "key": base64.StdEncoding.EncodeToString(key)}}}}
	b, _ := json.Marshal(store)
	os.WriteFile(acme, b, 0o600)
	if out := run(`{"hostname":"mail.example.com","volume":"v"}`); out["source"] != "acme" || out["changed"] != true {
		t.Fatalf("acme: %v", out)
	}
	if got, _ := os.ReadFile(filepath.Join(vol, "ssl/cert.pem")); string(got) != string(cert) {
		t.Fatal("cert not copied")
	}
	if out := run(`{"hostname":"mail.example.com","volume":"v"}`); out["changed"] != false {
		t.Fatal("an unchanged certificate is not rewritten")
	}
	if _, err := e.mailCerts(context.Background(), json.RawMessage(`{"hostname":"../x","volume":"v"}`)); err == nil || !strings.Contains(err.Error(), "hostname") {
		t.Fatal("bad hostname must be refused")
	}
}
