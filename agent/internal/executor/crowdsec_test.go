package executor

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

type fakeCrowdSec struct {
	calls    []string
	rejectT  bool
	alertsJS string
}

func (f *fakeCrowdSec) run(_ context.Context, _ string, _ []string, name string, args ...string) ([]byte, error) {
	call := name + " " + strings.Join(args, " ")
	f.calls = append(f.calls, call)
	switch {
	case strings.HasSuffix(call, "crowdsec -t") && f.rejectT:
		return []byte("level=fatal msg=\"bad scenario dmp-fw-a.yaml\""), errors.New("exit 1")
	case strings.Contains(call, "cscli alerts list"):
		return []byte(f.alertsJS), nil
	}
	return nil, nil
}

func csExec(t *testing.T) (*Executor, *fakeCrowdSec, string) {
	dir := t.TempDir()
	f := &fakeCrowdSec{}
	return &Executor{CrowdSecDir: dir, Run: f.run}, f, dir
}

func applyCS(e *Executor, scenarios map[string]string, profiles string) error {
	raw, _ := json.Marshal(map[string]any{"scenarios": scenarios, "profiles": profiles})
	_, err := e.crowdsecApply(context.Background(), raw)
	return err
}

func TestCrowdSecApplyWritesChecksAndReloads(t *testing.T) {
	e, f, dir := csExec(t)
	os.MkdirAll(filepath.Join(dir, "scenarios"), 0o755)
	os.WriteFile(filepath.Join(dir, "scenarios", "dmp-old.yaml"), []byte("old"), 0o644)
	os.WriteFile(filepath.Join(dir, "scenarios", "crowdsecurity-http-probing.yaml"), []byte("hub"), 0o644)

	if err := applyCS(e, map[string]string{"dmp-fw-a.yaml": "type: leaky"}, "name: dmp_ban"); err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(filepath.Join(dir, "scenarios", "dmp-fw-a.yaml")); string(b) != "type: leaky" {
		t.Fatal("scenario not written")
	}
	if _, err := os.Stat(filepath.Join(dir, "scenarios", "dmp-old.yaml")); err == nil {
		t.Fatal("scenarios the panel no longer renders must be removed")
	}
	if _, err := os.Stat(filepath.Join(dir, "scenarios", "crowdsecurity-http-probing.yaml")); err != nil {
		t.Fatal("files the panel does not own must be left alone")
	}
	want := []string{"docker exec crowdsec crowdsec -t", "docker kill --signal SIGHUP crowdsec"}
	if strings.Join(f.calls, "|") != strings.Join(want, "|") {
		t.Fatalf("calls = %v, want check then reload", f.calls)
	}
}

func TestCrowdSecApplyRestoresThePreviousConfigWhenRejected(t *testing.T) {
	e, f, dir := csExec(t)
	if err := applyCS(e, map[string]string{"dmp-fw-a.yaml": "good"}, "profiles v1"); err != nil {
		t.Fatal(err)
	}
	f.rejectT = true
	f.calls = nil
	err := applyCS(e, map[string]string{"dmp-fw-b.yaml": "broken"}, "profiles v2")
	if err == nil || !strings.Contains(err.Error(), "previous one is kept") {
		t.Fatalf("a rejected config must fail clearly: %v", err)
	}
	if b, _ := os.ReadFile(filepath.Join(dir, "scenarios", "dmp-fw-a.yaml")); string(b) != "good" {
		t.Fatal("the previous scenario must be restored")
	}
	if _, err := os.Stat(filepath.Join(dir, "scenarios", "dmp-fw-b.yaml")); err == nil {
		t.Fatal("the rejected scenario must be removed")
	}
	if b, _ := os.ReadFile(filepath.Join(dir, "profiles.yaml")); string(b) != "profiles v1" {
		t.Fatal("the previous profiles must be restored")
	}
	for _, c := range f.calls {
		if strings.Contains(c, "SIGHUP") {
			t.Fatal("a rejected config must never be reloaded")
		}
	}
}

func TestCrowdSecApplyValidatesInput(t *testing.T) {
	e, _, _ := csExec(t)
	if err := applyCS(e, map[string]string{"../../etc/crowdsec/config.yaml": "x"}, "p"); err == nil {
		t.Fatal("scenario names must not be paths")
	}
	if err := applyCS(e, nil, " "); err == nil {
		t.Fatal("empty profiles would stop every decision and must be refused")
	}
	e.CrowdSecContainer = "crowdsec; rm -rf /"
	if err := applyCS(e, nil, "p"); err == nil {
		t.Fatal("container names are validated")
	}
}

func TestCrowdSecAlertsAreCompacted(t *testing.T) {
	e, f, _ := csExec(t)
	f.alertsJS = `[{"id":42,"scenario":"dmp/fw-0a1b","created_at":"2026-10-30T01:00:00Z",
	  "source":{"ip":"203.0.113.9","cn":"RU"},"decisions":[{"type":"captcha"}],
	  "events":[{"meta":[{"key":"target_fqdn","value":"shop.example.lk"},{"key":"http_verb","value":"POST"},{"key":"http_path","value":"/wp-login.php"}]}]}]`
	raw, _ := json.Marshal(map[string]any{"since": "10m"})
	out, err := e.crowdsecAlerts(context.Background(), raw)
	if err != nil {
		t.Fatal(err)
	}
	a := out.(map[string]any)["alerts"].([]CrowdSecAlert)[0]
	if a.ID != 42 || a.IP != "203.0.113.9" || a.Country != "RU" || a.Decision != "captcha" || a.Host != "shop.example.lk" || a.Path != "/wp-login.php" || a.Method != "POST" {
		t.Fatalf("unexpected %+v", a)
	}
	if !strings.Contains(f.calls[0], "--since 10m") {
		t.Fatal("since must be passed through")
	}
	raw, _ = json.Marshal(map[string]any{"since": "10m; reboot"})
	if _, err := e.crowdsecAlerts(context.Background(), raw); err == nil {
		t.Fatal("since is validated")
	}
}

func TestCrowdSecApplyWritesAndRemovesTheAllowList(t *testing.T) {
	e, f, dir := csExec(t)
	allow := filepath.Join(dir, "parsers", "s02-enrich", "dmp-allowlist.yaml")
	apply := func(wl string) error {
		raw, _ := json.Marshal(map[string]any{"scenarios": map[string]string{}, "profiles": "p", "whitelist": wl})
		_, err := e.crowdsecApply(context.Background(), raw)
		return err
	}
	if err := apply("name: dmp/allowlist"); err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(allow); string(b) != "name: dmp/allowlist" {
		t.Fatal("allow list not written")
	}

	f.rejectT = true
	if err := apply("name: broken"); err == nil {
		t.Fatal("a rejected config must fail")
	}
	if b, _ := os.ReadFile(allow); string(b) != "name: dmp/allowlist" {
		t.Fatal("the previous allow list must be restored")
	}

	f.rejectT = false
	if err := apply(""); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(allow); err == nil {
		t.Fatal("an empty allow list removes the parser")
	}
}
