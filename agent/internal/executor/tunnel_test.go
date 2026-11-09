package executor

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/livezen-technologies/cloudpanel/agent/internal/protocol"
)

func TestTunnelLockdown(t *testing.T) {
	dir := t.TempDir()
	g := &fakeWG{}
	e := &Executor{HMACKey: key, Journal: &Journal{Path: filepath.Join(dir, "j.json")}, Report: (&fakePanel{results: map[string][]protocol.Result{}}).report,
		StateDir: dir, Run: g.run, Now: func() time.Time { return now }, LookPath: func(string) (string, error) { return "/usr/sbin/nft", nil }}
	if _, err := e.Handle(context.Background(), cmd("t1", "tunnel.lockdown", `{"enabled":true,"allow":["198.51.100.7"]}`, now)); err != nil {
		t.Fatal(err)
	}
	rules, _ := os.ReadFile(filepath.Join(dir, "tunnel", "lockdown.nft"))
	for _, want := range []string{"hook prerouting priority -150", "ip saddr { 198.51.100.7/32 } accept", "fib daddr type local tcp dport { 80, 443 } drop"} {
		if !strings.Contains(string(rules), want) {
			t.Errorf("rules lack %q:\n%s", want, rules)
		}
	}
	g.calls = nil
	e.Handle(context.Background(), cmd("t2", "tunnel.lockdown", `{"enabled":false}`, now))
	if len(g.calls) != 1 || strings.Join(g.calls[0], " ") != "nft delete table inet dockermgr_tunnel" {
		t.Fatalf("calls %v", g.calls)
	}
	if _, err := e.Handle(context.Background(), cmd("t3", "tunnel.lockdown", `{"enabled":true,"ports":[0]}`, now)); err == nil {
		t.Fatal("bad port accepted")
	}
}
