package executor

import (
	"context"
	"encoding/json"
	"strings"
	"testing"
)

func TestRenderEgressDropsPoolsAndLimitsSMTP(t *testing.T) {
	doc, err := renderEgress(egressPayload{Enabled: true, BlockMining: true, PoolAddresses: []string{"198.51.100.7", "2001:db8::/64"}, SMTPPerMinute: 30})
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{
		"delete table inet ws_egress", "hook forward priority -5; policy accept;", "hook output priority -5; policy accept;",
		"tcp dport { 3333, 4444,", "ip daddr @pool4 counter drop", "ip6 daddr @pool6 counter drop",
		"add @smtp4 { ip saddr limit rate over 30/minute } counter drop", "198.51.100.7",
	} {
		if !strings.Contains(doc, want) {
			t.Fatalf("ruleset lacks %q:\n%s", want, doc)
		}
	}
	for _, port := range []string{" 25,", " 80,", " 443,", " 587,"} {
		if strings.Contains(strings.Split(doc, "mining pool port")[0], port) {
			t.Fatalf("a common port %s must never be in the mining list", port)
		}
	}
	if _, err := renderEgress(egressPayload{Enabled: true, PoolAddresses: []string{"not-an-ip"}}); err == nil {
		t.Fatal("a bad pool address must be refused, not ignored")
	}
	plain, _ := renderEgress(egressPayload{Enabled: true})
	if strings.Contains(plain, "drop") {
		t.Fatal("with nothing switched on the table must drop nothing")
	}
}

func TestApplyEgressChecksThenLoadsAndCanBeTurnedOff(t *testing.T) {
	var calls []string
	e := &Executor{StateDir: t.TempDir(), LookPath: func(string) (string, error) { return "/usr/sbin/nft", nil },
		Run: func(_ context.Context, _ string, _ []string, name string, args ...string) ([]byte, error) {
			calls = append(calls, name+" "+strings.Join(args, " "))
			return nil, nil
		}}
	if _, err := e.applyEgress(context.Background(), json.RawMessage(`{"enabled":true,"block_mining":true,"smtp_per_minute":20}`)); err != nil {
		t.Fatal(err)
	}
	if len(calls) != 2 || !strings.HasPrefix(calls[0], "nft --check -f ") || !strings.HasPrefix(calls[1], "nft -f ") {
		t.Fatalf("calls: %v", calls)
	}
	calls = nil
	if _, err := e.applyEgress(context.Background(), json.RawMessage(`{"enabled":false}`)); err != nil {
		t.Fatal(err)
	}
	if len(calls) != 1 || calls[0] != "nft delete table inet ws_egress" {
		t.Fatalf("turning off must delete the table: %v", calls)
	}
}
