package executor

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

func TestProxyAdoptScanRetireRestore(t *testing.T) {
	e, _, _, dir := setup(t)
	e.ProxyDir = filepath.Join(dir, "dynamic")
	os.MkdirAll(e.ProxyDir, 0o755)
	os.WriteFile(filepath.Join(e.ProxyDir, "giantforests.yml"), []byte("http:\n  routers: {}\n"), 0o644)
	os.WriteFile(filepath.Join(e.ProxyDir, "dmp-domains.yml"), []byte("x"), 0o644)
	os.WriteFile(filepath.Join(e.ProxyDir, "notes.txt"), []byte("x"), 0o644)

	r, err := e.Handle(context.Background(), cmd("s1", "proxy.scan", `{}`, now))
	if err != nil {
		t.Fatal(err)
	}
	if b, _ := json.Marshal(r.Output.(map[string]any)["files"]); string(b) != `[{"name":"giantforests.yml","content":"http:\n  routers: {}\n"}]` {
		t.Fatalf("scan must list only the operator's YAML files: %s", b)
	}

	if _, err := e.Handle(context.Background(), cmd("r1", "proxy.retire", `{"file":"giantforests.yml"}`, now)); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(e.ProxyDir, "giantforests.yml.adopted")); err != nil {
		t.Fatal("not parked")
	}
	if r, _ := e.Handle(context.Background(), cmd("r2", "proxy.retire", `{"file":"giantforests.yml"}`, now)); r.Output.(map[string]any)["already"] != true {
		t.Fatal("a second retire must be a no-op")
	}
	if r, _ := e.Handle(context.Background(), cmd("s2", "proxy.scan", `{}`, now)); r.Output.(map[string]any)["adopted"].([]string)[0] != "giantforests.yml" {
		t.Fatal("scan must report retired files")
	}
	if _, err := e.Handle(context.Background(), cmd("x1", "proxy.restore", `{"file":"giantforests.yml"}`, now)); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(e.ProxyDir, "giantforests.yml")); err != nil {
		t.Fatal("not restored")
	}
	for _, bad := range []string{`{"file":"dmp-domains.yml"}`, `{"file":"../x.yml"}`, `{"file":"notes.txt"}`} {
		if _, err := e.Handle(context.Background(), cmd("b", "proxy.retire", bad, now)); err == nil {
			t.Errorf("%s accepted", bad)
		}
	}
}
