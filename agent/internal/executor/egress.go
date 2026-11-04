package executor

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
)

// Outbound abuse controls (AV-12). A compromised site is most often used to mine
// cryptocurrency or send spam. This writes its own nftables table, hooked on the
// forward path (container traffic) and on output (the host itself), that drops
// connections to mining-pool ports and listed pool addresses and limits new SMTP
// connections per source. Like the firewall table, it is replaced atomically and
// never touches Docker's, UFW's or the firewall's own rules.

const egressTable = "ws_egress"

// MiningPoolPorts are the stratum ports public pools use. Common web and mail
// ports are deliberately absent so nothing legitimate is caught.
var MiningPoolPorts = []int{3333, 4444, 5555, 6666, 7777, 9999, 14433, 14444, 45560, 45700}

type egressPayload struct {
	Enabled       bool     `json:"enabled"`
	BlockMining   bool     `json:"block_mining"`
	PoolAddresses []string `json:"pool_addresses"` // IPs or CIDRs of known pools
	SMTPPerMinute int      `json:"smtp_per_minute"`
	DryRun        bool     `json:"dry_run"`
}

func renderEgress(p egressPayload) (string, error) {
	v4, v6, err := parseCIDRs("pool address", p.PoolAddresses)
	if err != nil {
		return "", err
	}
	if p.SMTPPerMinute < 0 || p.SMTPPerMinute > 10000 {
		return "", fmt.Errorf("smtp_per_minute must be 0 (no limit) to 10000")
	}
	var b strings.Builder
	fmt.Fprintf(&b, "table inet %s {}\ndelete table inet %s\ntable inet %s {\n", egressTable, egressTable, egressTable)
	b.WriteString(fwSet("pool4", "ipv4_addr", v4))
	b.WriteString(fwSet("pool6", "ipv6_addr", v6))
	if p.SMTPPerMinute > 0 {
		b.WriteString("  set smtp4 { type ipv4_addr; flags dynamic; timeout 1m; }\n")
		b.WriteString("  set smtp6 { type ipv6_addr; flags dynamic; timeout 1m; }\n")
	}
	var rules []string
	if p.BlockMining {
		ports := append([]int(nil), MiningPoolPorts...)
		sort.Ints(ports)
		ps := make([]string, len(ports))
		for i, n := range ports {
			ps[i] = fmt.Sprint(n)
		}
		rules = append(rules, fmt.Sprintf("tcp dport { %s } counter drop comment \"mining pool port\"", strings.Join(ps, ", ")))
	}
	if len(v4) > 0 {
		rules = append(rules, "ip daddr @pool4 counter drop comment \"mining pool\"")
	}
	if len(v6) > 0 {
		rules = append(rules, "ip6 daddr @pool6 counter drop comment \"mining pool\"")
	}
	if p.SMTPPerMinute > 0 {
		rules = append(rules,
			fmt.Sprintf("tcp dport 25 ct state new add @smtp4 { ip saddr limit rate over %d/minute } counter drop comment \"smtp limit\"", p.SMTPPerMinute),
			fmt.Sprintf("tcp dport 25 ct state new add @smtp6 { ip6 saddr limit rate over %d/minute } counter drop comment \"smtp limit\"", p.SMTPPerMinute))
	}
	// Before Docker's filter chains (priority 0), with accept as the default:
	// this table only ever drops what it names.
	for _, chain := range []string{"forward", "output"} {
		fmt.Fprintf(&b, "  chain %s {\n    type filter hook %s priority -5; policy accept;\n", chain, chain)
		for _, r := range rules {
			b.WriteString("    " + r + "\n")
		}
		b.WriteString("  }\n")
	}
	b.WriteString("}\n")
	return b.String(), nil
}

func (e *Executor) applyEgress(ctx context.Context, raw json.RawMessage) (any, error) {
	var p egressPayload
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	look := e.LookPath
	if look == nil {
		look = execLookPath
	}
	if !p.Enabled {
		if p.DryRun {
			return map[string]any{"applied": false, "enabled": false}, nil
		}
		if _, err := look("nft"); err == nil {
			_, _ = e.Run(ctx, "", nil, "nft", "delete", "table", "inet", egressTable)
		}
		return map[string]any{"applied": true, "enabled": false}, nil
	}
	doc, err := renderEgress(p)
	if err != nil {
		return nil, err
	}
	if p.DryRun {
		return map[string]any{"ruleset": doc, "applied": false}, nil
	}
	if _, err := look("nft"); err != nil {
		return nil, fmt.Errorf("nftables is not installed on this server: %w", err)
	}
	dir := filepath.Join(e.StateDir, "egress")
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, err
	}
	tmp := filepath.Join(dir, "ruleset.nft.new")
	if err := os.WriteFile(tmp, []byte(doc), 0o600); err != nil {
		return nil, err
	}
	if out, err := e.Run(ctx, "", nil, "nft", "--check", "-f", tmp); err != nil {
		return nil, fmt.Errorf("ruleset rejected by nft: %s", strings.TrimSpace(string(out)))
	}
	if out, err := e.Run(ctx, "", nil, "nft", "-f", tmp); err != nil {
		return nil, fmt.Errorf("nft load failed: %s", strings.TrimSpace(string(out)))
	}
	_ = os.Rename(tmp, filepath.Join(dir, "ruleset.nft"))
	return map[string]any{"applied": true, "enabled": true, "pool_addresses": len(p.PoolAddresses), "smtp_per_minute": p.SMTPPerMinute}, nil
}
