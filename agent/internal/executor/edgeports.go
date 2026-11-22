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

// XNODE-3. Host ports an app node publishes so the ingress/edge servers can reach
// an app at <node public ip>:<port>, open to those servers' addresses only.
//
// Docker's published ports bypass UFW, and DOCKER-USER sees the packet after
// DNAT (container port, not host port). This table hooks prerouting at -150,
// before Docker's NAT (-100), so it matches the host port the edge connects to.
// It is its own table: the firewall (dockermgr), egress and Docker rules are
// never touched, and it is replaced atomically. An empty rule list removes it.

const edgePortsTable = "dockermgr_edge"

type edgePortRule struct {
	Port  int      `json:"port"`
	Allow []string `json:"allow"` // ingress IPs/CIDRs; empty = nobody (port dropped)
}

type edgePortsPayload struct {
	Rules  []edgePortRule `json:"rules"`
	DryRun bool           `json:"dry_run"`
}

func renderEdgePorts(p edgePortsPayload) (string, error) {
	rules := append([]edgePortRule(nil), p.Rules...)
	sort.Slice(rules, func(i, j int) bool { return rules[i].Port < rules[j].Port })
	var b strings.Builder
	b.WriteString("# Managed by Docker Manager Portal (edge.ports). Do not edit.\n")
	fmt.Fprintf(&b, "table inet %s {}\ndelete table inet %s\ntable inet %s {\n", edgePortsTable, edgePortsTable, edgePortsTable)
	b.WriteString("  chain prerouting {\n")
	b.WriteString("    type filter hook prerouting priority -150; policy accept;\n")
	b.WriteString("    ct state established,related accept\n")
	b.WriteString("    iif lo accept\n")
	seen := map[int]bool{}
	for _, r := range rules {
		if r.Port < 1 || r.Port > 65535 {
			return "", fmt.Errorf("invalid port %d", r.Port)
		}
		if seen[r.Port] {
			return "", fmt.Errorf("port %d listed twice", r.Port)
		}
		seen[r.Port] = true
		v4, v6, err := parseCIDRs("allow", r.Allow)
		if err != nil {
			return "", err
		}
		if len(v4) > 0 {
			fmt.Fprintf(&b, "    tcp dport %d ip saddr { %s } accept\n", r.Port, strings.Join(v4, ", "))
		}
		if len(v6) > 0 {
			fmt.Fprintf(&b, "    tcp dport %d ip6 saddr { %s } accept\n", r.Port, strings.Join(v6, ", "))
		}
		fmt.Fprintf(&b, "    tcp dport %d counter drop\n", r.Port)
	}
	b.WriteString("  }\n}\n")
	return b.String(), nil
}

func (e *Executor) applyEdgePorts(ctx context.Context, raw json.RawMessage) (any, error) {
	var p edgePortsPayload
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	look := e.LookPath
	if look == nil {
		look = execLookPath
	}
	if len(p.Rules) == 0 {
		if p.DryRun {
			return map[string]any{"applied": false, "rules": 0}, nil
		}
		if _, err := look("nft"); err == nil {
			_, _ = e.Run(ctx, "", nil, "nft", "delete", "table", "inet", edgePortsTable)
		}
		_ = os.Remove(filepath.Join(e.StateDir, "edge-ports", "ruleset.nft"))
		return map[string]any{"applied": true, "rules": 0}, nil
	}
	doc, err := renderEdgePorts(p)
	if err != nil {
		return nil, err
	}
	if p.DryRun {
		return map[string]any{"ruleset": doc, "applied": false, "rules": len(p.Rules)}, nil
	}
	if _, err := look("nft"); err != nil {
		// Refuse: publishing the port without this filter would expose the app to everyone.
		return nil, fmt.Errorf("nftables is not installed on this server: %w", err)
	}
	dir := filepath.Join(e.StateDir, "edge-ports")
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
	return map[string]any{"applied": true, "rules": len(p.Rules)}, nil
}

// RestoreEdgePorts reloads the last applied edge.ports ruleset (agent start: the
// table does not survive a reboot, while Docker's published ports do).
func (e *Executor) RestoreEdgePorts(ctx context.Context) {
	path := filepath.Join(e.StateDir, "edge-ports", "ruleset.nft")
	if _, err := os.Stat(path); err != nil || e.Run == nil {
		return
	}
	if out, err := e.Run(ctx, "", nil, "nft", "-f", path); err != nil {
		e.logf("edge.ports restore failed: %s", strings.TrimSpace(string(out)))
	}
}
