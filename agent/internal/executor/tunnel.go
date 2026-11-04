package executor

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
)

// tunnelTable drops public web traffic while a Cloudflare Tunnel serves the
// server's sites (CFT). It hooks prerouting before Docker's DNAT, like the panel
// firewall, and only matches packets addressed to the host itself, so cloudflared
// reaching Traefik over the Docker network is unaffected.
const tunnelTable = "dockermgr_tunnel"

type tunnelLockdown struct {
	Enabled bool     `json:"enabled"`
	Ports   []int    `json:"ports"`
	Allow   []string `json:"allow"`
}

func (e *Executor) tunnelLock(ctx context.Context, raw []byte) (any, error) {
	var p tunnelLockdown
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	dir := filepath.Join(e.StateDir, "tunnel")
	path := filepath.Join(dir, "lockdown.nft")
	if !p.Enabled {
		if _, err := os.Stat(path); err == nil {
			_, _ = e.run(ctx, "", baseEnv(), "nft", "delete", "table", "inet", tunnelTable)
			_ = os.Remove(path)
		}
		return map[string]any{"locked": false}, nil
	}
	if len(p.Ports) == 0 {
		p.Ports = []int{80, 443}
	}
	ports := make([]string, 0, len(p.Ports))
	for _, port := range p.Ports {
		if port < 1 || port > 65535 {
			return nil, fmt.Errorf("invalid port %d", port)
		}
		ports = append(ports, strconv.Itoa(port))
	}
	v4, v6, err := parseCIDRs("allow", p.Allow)
	if err != nil {
		return nil, err
	}
	if !e.meshLook("nft") {
		return nil, fmt.Errorf("nftables is not installed; ports cannot be closed")
	}
	var b strings.Builder
	b.WriteString("table inet " + tunnelTable + "\ndelete table inet " + tunnelTable + "\ntable inet " + tunnelTable + " {\n")
	b.WriteString("  chain pre {\n    type filter hook prerouting priority -150; policy accept;\n    iif lo accept\n")
	if len(v4) > 0 {
		b.WriteString("    ip saddr { " + strings.Join(v4, ", ") + " } accept\n")
	}
	if len(v6) > 0 {
		b.WriteString("    ip6 saddr { " + strings.Join(v6, ", ") + " } accept\n")
	}
	b.WriteString("    fib daddr type local tcp dport { " + strings.Join(ports, ", ") + " } drop\n  }\n}\n")
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, err
	}
	if err := os.WriteFile(path, []byte(b.String()), 0o600); err != nil {
		return nil, err
	}
	if o, err := e.run(ctx, "", baseEnv(), "nft", "--check", "-f", path); err != nil {
		return nil, fmt.Errorf("ruleset rejected by nft: %s", strings.TrimSpace(string(o)))
	}
	if o, err := e.run(ctx, "", baseEnv(), "nft", "-f", path); err != nil {
		return nil, fmt.Errorf("nft load failed: %s", strings.TrimSpace(string(o)))
	}
	return map[string]any{"locked": true, "ports": p.Ports}, nil
}
