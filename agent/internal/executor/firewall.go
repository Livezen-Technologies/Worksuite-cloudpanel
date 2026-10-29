package executor

import (
	"context"
	"fmt"
	"net/netip"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

// FW-002. Layer-3 enforcement of the panel's firewall rules.
//
// Why nftables and not UFW: Docker inserts its own rules ahead of UFW's, so a
// UFW rule does not filter traffic to a container at all - it would look
// applied in the panel and do nothing to the sites. This writes a dedicated
// table hooked at prerouting, which sees host and container traffic alike,
// before Docker's NAT. The table is ours alone, so UFW, Docker and fail2ban
// rules are never touched.
//
// Everything is applied as one atomic `nft -f` document that first deletes and
// recreates the table, so there is no window in which half a policy is live.

const fwTable = "dockermgr"

// fwDir holds the last applied ruleset and the pending-confirmation stamp.
func (e *Executor) fwDir() string { return filepath.Join(e.StateDir, "firewall") }

type firewallPayload struct {
	// Block and Allow are CIDRs or bare addresses, v4 and v6 mixed.
	Block []string `json:"block"`
	Allow []string `json:"allow"`
	// AllowOnly inverts the policy: everything not in Allow is dropped.
	AllowOnly bool `json:"allow_only"`
	// Ports the policy applies to. Empty means all ports.
	Ports []int `json:"ports"`
	// NeverBlock is the safety list - the panel's own address, the agent's
	// management network, the operator's SSH source. Always accepted, and the
	// panel is expected to include itself.
	NeverBlock []string `json:"never_block"`
	// ConfirmWithin rolls the ruleset back automatically unless the panel
	// confirms. Zero disables the timer (used by tests and dry runs).
	ConfirmWithin int `json:"confirm_within_seconds"`
	// DryRun validates and returns the ruleset without applying it.
	DryRun bool `json:"dry_run"`
}

// parseCIDRs normalises input to prefixes, dropping nothing silently: an
// unparseable entry is an error, because a firewall that quietly ignores a
// rule is worse than one that refuses to load.
func parseCIDRs(kind string, in []string) (v4 []string, v6 []string, err error) {
	for _, raw := range in {
		s := strings.TrimSpace(raw)
		if s == "" {
			continue
		}
		if !strings.Contains(s, "/") {
			addr, e := netip.ParseAddr(s)
			if e != nil {
				return nil, nil, fmt.Errorf("invalid %s address %q", kind, raw)
			}
			if addr.Is4() {
				s += "/32"
			} else {
				s += "/128"
			}
		}
		p, e := netip.ParsePrefix(s)
		if e != nil {
			return nil, nil, fmt.Errorf("invalid %s prefix %q", kind, raw)
		}
		p = p.Masked()
		if p.Addr().Is4() {
			v4 = append(v4, p.String())
		} else {
			v6 = append(v6, p.String())
		}
	}
	sort.Strings(v4)
	sort.Strings(v6)
	return v4, v6, nil
}

func fwSet(name, typ string, elems []string) string {
	var b strings.Builder
	fmt.Fprintf(&b, "  set %s {\n    type %s\n    flags interval\n", name, typ)
	if len(elems) > 0 {
		fmt.Fprintf(&b, "    elements = { %s }\n", strings.Join(elems, ", "))
	}
	b.WriteString("  }\n")
	return b.String()
}

// renderFirewall builds the complete nft document.
func renderFirewall(p firewallPayload) (string, error) {
	block4, block6, err := parseCIDRs("block", p.Block)
	if err != nil {
		return "", err
	}
	allow4, allow6, err := parseCIDRs("allow", p.Allow)
	if err != nil {
		return "", err
	}
	never4, never6, err := parseCIDRs("never_block", p.NeverBlock)
	if err != nil {
		return "", err
	}
	if p.AllowOnly && len(allow4)+len(allow6) == 0 {
		// Refuse rather than drop every packet on the box.
		return "", fmt.Errorf("allow_only with an empty allow list would block all traffic")
	}

	var b strings.Builder
	b.WriteString("# Managed by Docker Manager Portal (firewall.apply). Do not edit.\n")
	fmt.Fprintf(&b, "table inet %s\n", fwTable)
	fmt.Fprintf(&b, "delete table inet %s\n", fwTable)
	fmt.Fprintf(&b, "table inet %s {\n", fwTable)
	b.WriteString(fwSet("block4", "ipv4_addr", block4))
	b.WriteString(fwSet("block6", "ipv6_addr", block6))
	b.WriteString(fwSet("allow4", "ipv4_addr", allow4))
	b.WriteString(fwSet("allow6", "ipv6_addr", allow6))
	b.WriteString(fwSet("never4", "ipv4_addr", never4))
	b.WriteString(fwSet("never6", "ipv6_addr", never6))

	// priority -150 is after conntrack (raw, -300) and before Docker's NAT,
	// so established traffic is already marked and container traffic is seen.
	b.WriteString("  chain prerouting {\n")
	b.WriteString("    type filter hook prerouting priority -150; policy accept;\n")
	b.WriteString("    ct state established,related accept\n")
	b.WriteString("    iif lo accept\n")
	// The safety list wins over every other rule, in both modes.
	b.WriteString("    ip saddr @never4 accept\n")
	b.WriteString("    ip6 saddr @never6 accept\n")

	portMatch := ""
	if len(p.Ports) > 0 {
		parts := make([]string, 0, len(p.Ports))
		for _, port := range p.Ports {
			if port < 1 || port > 65535 {
				return "", fmt.Errorf("invalid port %d", port)
			}
			parts = append(parts, fmt.Sprint(port))
		}
		portMatch = fmt.Sprintf("tcp dport { %s } ", strings.Join(parts, ", "))
	}

	if p.AllowOnly {
		// Accept the listed sources, drop the rest - on the covered ports only,
		// so a port-scoped allow list cannot cut off SSH.
		b.WriteString("    ip saddr @allow4 accept\n")
		b.WriteString("    ip6 saddr @allow6 accept\n")
		fmt.Fprintf(&b, "    %sdrop\n", portMatch)
	} else {
		fmt.Fprintf(&b, "    %sip saddr @block4 drop\n", portMatch)
		fmt.Fprintf(&b, "    %sip6 saddr @block6 drop\n", portMatch)
	}
	b.WriteString("  }\n}\n")
	return b.String(), nil
}

// applyFirewall writes and loads the ruleset, optionally arming a rollback.
func (e *Executor) applyFirewall(ctx context.Context, payload []byte, progress func(int, string)) (any, error) {
	var p firewallPayload
	if err := decode(payload, &p); err != nil {
		return nil, err
	}
	doc, err := renderFirewall(p)
	if err != nil {
		return nil, err
	}
	counts := map[string]any{
		"block": len(p.Block), "allow": len(p.Allow),
		"allow_only": p.AllowOnly, "never_block": len(p.NeverBlock),
	}
	if p.DryRun {
		return map[string]any{"ruleset": doc, "applied": false, "counts": counts}, nil
	}
	look := e.LookPath
	if look == nil {
		look = execLookPath
	}
	if _, err := look("nft"); err != nil {
		return nil, fmt.Errorf("nftables is not installed on this server: %w", err)
	}
	if err := os.MkdirAll(e.fwDir(), 0o700); err != nil {
		return nil, err
	}
	path := filepath.Join(e.fwDir(), "ruleset.nft")

	// Check the document parses before it is loaded.
	tmp := path + ".new"
	if err := os.WriteFile(tmp, []byte(doc), 0o600); err != nil {
		return nil, err
	}
	if out, err := e.Run(ctx, "", nil, "nft", "--check", "-f", tmp); err != nil {
		return nil, fmt.Errorf("ruleset rejected by nft: %s", strings.TrimSpace(string(out)))
	}
	progress(40, "ruleset validated")

	if out, err := e.Run(ctx, "", nil, "nft", "-f", tmp); err != nil {
		return nil, fmt.Errorf("nft load failed: %s", strings.TrimSpace(string(out)))
	}
	_ = os.Rename(tmp, path)
	progress(80, "ruleset applied")

	// Arm the rollback. If the panel cannot reach this server afterwards - for
	// example because the rules just blocked it - the table is removed and the
	// server returns to its previous state without anyone logging in.
	if p.ConfirmWithin > 0 {
		e.armFirewallRollback(p.ConfirmWithin)
	}
	return map[string]any{
		"applied": true, "counts": counts,
		"rollback_in": p.ConfirmWithin,
	}, nil
}

// armFirewallRollback deletes the table unless confirmFirewall is called first.
func (e *Executor) armFirewallRollback(seconds int) {
	stamp := filepath.Join(e.fwDir(), "pending")
	_ = os.WriteFile(stamp, []byte(time.Now().UTC().Format(time.RFC3339)), 0o600)
	go func() {
		time.Sleep(time.Duration(seconds) * time.Second)
		if _, err := os.Stat(stamp); err != nil {
			return // confirmed
		}
		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		defer cancel()
		_, _ = e.Run(ctx, "", nil, "nft", "delete", "table", "inet", fwTable)
		_ = os.Remove(stamp)
		e.logf("firewall rolled back: no confirmation within %ds", seconds)
	}()
}

// confirmFirewall cancels a pending rollback.
func (e *Executor) confirmFirewall() (any, error) {
	stamp := filepath.Join(e.fwDir(), "pending")
	if err := os.Remove(stamp); err != nil && !os.IsNotExist(err) {
		return nil, err
	}
	return map[string]any{"confirmed": true}, nil
}
