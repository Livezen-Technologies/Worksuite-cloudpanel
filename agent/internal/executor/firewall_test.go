package executor

import (
	"strings"
	"testing"
)

func TestRenderFirewallBlocksListedSources(t *testing.T) {
	doc, err := renderFirewall(firewallPayload{
		Block:      []string{"185.220.101.4", "5.188.62.0/24", "2001:db8::/32"},
		NeverBlock: []string{"51.89.7.73"},
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	for _, want := range []string{
		"185.220.101.4/32", "5.188.62.0/24", "2001:db8::/32",
		"ip saddr @block4 drop", "ip6 saddr @block6 drop",
		"ct state established,related accept",
		"ip saddr @never4 accept",
		"51.89.7.73/32",
	} {
		if !strings.Contains(doc, want) {
			t.Errorf("ruleset missing %q\n%s", want, doc)
		}
	}
	// The safety list must be accepted before any drop, or it is not a safety list.
	if strings.Index(doc, "@never4 accept") > strings.Index(doc, "@block4 drop") {
		t.Error("never_block must be evaluated before the block list")
	}
}

func TestRenderFirewallRefusesAllowOnlyWithEmptyList(t *testing.T) {
	_, err := renderFirewall(firewallPayload{AllowOnly: true})
	if err == nil {
		t.Fatal("an empty allow-only policy would drop every packet; it must be refused")
	}
	if !strings.Contains(err.Error(), "block all traffic") {
		t.Errorf("the error should say why: %v", err)
	}
}

func TestRenderFirewallAllowOnlyAcceptsListedAndDropsTheRest(t *testing.T) {
	doc, err := renderFirewall(firewallPayload{
		AllowOnly:  true,
		Allow:      []string{"203.94.66.0/24"},
		NeverBlock: []string{"51.89.7.73"},
		Ports:      []int{80, 443},
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	if !strings.Contains(doc, "ip saddr @allow4 accept") {
		t.Error("listed sources must be accepted")
	}
	if !strings.Contains(doc, "tcp dport { 80, 443 } drop") {
		t.Errorf("the catch-all drop must be scoped to the covered ports, so SSH survives:\n%s", doc)
	}
}

func TestRenderFirewallRejectsBadInputRatherThanIgnoringIt(t *testing.T) {
	for _, bad := range []string{"not-an-ip", "999.1.1.1", "10.0.0.0/99"} {
		if _, err := renderFirewall(firewallPayload{Block: []string{bad}}); err == nil {
			t.Errorf("%q should be rejected, not silently dropped", bad)
		}
	}
	if _, err := renderFirewall(firewallPayload{Block: []string{"1.2.3.4"}, Ports: []int{0}}); err == nil {
		t.Error("port 0 should be rejected")
	}
}

func TestRenderFirewallIsAtomicAndSelfContained(t *testing.T) {
	doc, err := renderFirewall(firewallPayload{Block: []string{"1.2.3.4"}})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	// Create-then-delete-then-define is the idiom that makes `nft -f` atomic
	// and idempotent whether or not the table already exists.
	if !strings.Contains(doc, "table inet dockermgr\ndelete table inet dockermgr\n") {
		t.Errorf("ruleset must delete and recreate its own table atomically:\n%s", doc)
	}
	// It must never touch anyone else's tables or chains. ("filter" on its own
	// is nftables syntax - `type filter hook ...` - not a foreign reference.)
	for _, foreign := range []string{"DOCKER-USER", "ufw", "f2b", "table ip filter", "table inet filter"} {
		if strings.Contains(doc, foreign) {
			t.Errorf("ruleset must not reference %q", foreign)
		}
	}
	// Exactly one table, and it is ours.
	if n := strings.Count(doc, "table inet "); n != 3 {
		t.Errorf("expected create/delete/define of one table, got %d references", n)
	}
}

func TestParseCIDRsNormalisesAndSplitsFamilies(t *testing.T) {
	v4, v6, err := parseCIDRs("block", []string{"10.0.0.5", "  192.168.1.0/24 ", "2001:db8::1", ""})
	if err != nil {
		t.Fatalf("parse: %v", err)
	}
	if len(v4) != 2 || v4[0] != "10.0.0.5/32" || v4[1] != "192.168.1.0/24" {
		t.Errorf("v4 = %v", v4)
	}
	if len(v6) != 1 || v6[0] != "2001:db8::1/128" {
		t.Errorf("v6 = %v", v6)
	}
}
