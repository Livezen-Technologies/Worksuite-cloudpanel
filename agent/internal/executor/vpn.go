package executor

import (
	"context"
	"crypto/ecdh"
	"crypto/rand"
	"encoding/base64"
	"errors"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// VPN phase 2: a client-access WireGuard gateway for one VPN network. Each network has its
// own interface (wsv*), private key (made here, never sent anywhere), and nftables table.
// Clients are denied by default: they may reach only the listed private ranges (split
// tunnel), or the internet through NAT as well (full tunnel).

// VPNSpec is the vpn.apply payload.
type VPNSpec struct {
	Interface  string    `json:"interface"`   // wsv + up to 12 [0-9a-z]
	Address    string    `json:"address"`     // gateway address in the network, CIDR (10.90.0.1/24)
	ListenPort int       `json:"listen_port"` // UDP
	Routing    string    `json:"routing"`     // split|full
	Reach      []string  `json:"reach"`       // private ranges clients may reach (split and full)
	Peers      []VPNPeer `json:"peers"`
	OpenPort   bool      `json:"open_port"`
	Down       bool      `json:"down"`
}

type VPNPeer struct {
	PublicKey string `json:"public_key"`
	Address   string `json:"address"` // the device's single address, x.x.x.x/32
}

var reVPNIface = regexp.MustCompile(`^wsv[0-9a-z]{1,12}$`)

var cgnat = &net.IPNet{IP: net.IPv4(100, 64, 0, 0), Mask: net.CIDRMask(10, 32)}

// vpnPrivate: RFC 1918 or the shared 100.64.0.0/10 range, as the panel allows.
func vpnPrivate(ip net.IP) bool { return ip.IsPrivate() || cgnat.Contains(ip) }

func (s *VPNSpec) validate() error {
	if !reVPNIface.MatchString(s.Interface) {
		return fmt.Errorf("invalid interface %q", s.Interface)
	}
	if s.Down {
		return nil
	}
	ip, network, err := net.ParseCIDR(s.Address)
	if err != nil || ip.To4() == nil || !vpnPrivate(ip) {
		return fmt.Errorf("invalid address %q (a private IPv4 CIDR)", s.Address)
	}
	if ip.Equal(network.IP) {
		return fmt.Errorf("address %q is the network address", s.Address)
	}
	if s.ListenPort < 1024 || s.ListenPort > 65535 {
		return fmt.Errorf("invalid listen_port %d", s.ListenPort)
	}
	if s.Routing != "split" && s.Routing != "full" {
		return fmt.Errorf("invalid routing %q", s.Routing)
	}
	for _, r := range s.Reach {
		rip, _, err := net.ParseCIDR(r)
		if err != nil || rip.To4() == nil || !vpnPrivate(rip) {
			return fmt.Errorf("invalid reach %q (private IPv4 CIDRs only)", r)
		}
	}
	seen := map[string]bool{}
	for _, p := range s.Peers {
		if !reWGKey.MatchString(p.PublicKey) {
			return fmt.Errorf("invalid peer public key %q", p.PublicKey)
		}
		pip, pnet, err := net.ParseCIDR(p.Address)
		if err != nil || pip.To4() == nil || !network.Contains(pip) {
			return fmt.Errorf("peer address %q is not inside %s", p.Address, network)
		}
		if ones, _ := pnet.Mask.Size(); ones != 32 {
			return fmt.Errorf("peer address %q must be a /32", p.Address)
		}
		if pip.Equal(ip) || seen[pip.String()] {
			return fmt.Errorf("peer address %q is already in use", p.Address)
		}
		seen[pip.String()] = true
	}
	return nil
}

func (e *Executor) vpnKey(iface string) (priv, pub string, err error) {
	path := filepath.Join(e.StateDir, "wireguard", iface+".key")
	raw, err := os.ReadFile(path)
	var key *ecdh.PrivateKey
	switch {
	case err == nil:
		b, derr := base64.StdEncoding.DecodeString(strings.TrimSpace(string(raw)))
		if derr != nil {
			return "", "", fmt.Errorf("corrupt %s: %v", path, derr)
		}
		if key, err = ecdh.X25519().NewPrivateKey(b); err != nil {
			return "", "", err
		}
	case errors.Is(err, os.ErrNotExist):
		if key, err = ecdh.X25519().GenerateKey(rand.Reader); err != nil {
			return "", "", err
		}
		if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
			return "", "", err
		}
		if err := os.WriteFile(path, []byte(base64.StdEncoding.EncodeToString(key.Bytes())+"\n"), 0o600); err != nil {
			return "", "", err
		}
	default:
		return "", "", err
	}
	return base64.StdEncoding.EncodeToString(key.Bytes()), base64.StdEncoding.EncodeToString(key.PublicKey().Bytes()), nil
}

func vpnConfig(s VPNSpec, priv string, withAddress bool) string {
	var b strings.Builder
	b.WriteString("# Managed by the dockermgr agent (VPN network); changes are overwritten.\n[Interface]\nPrivateKey = " + priv + "\n")
	if withAddress {
		b.WriteString("Address = " + s.Address + "\n")
	}
	b.WriteString("ListenPort = " + strconv.Itoa(s.ListenPort) + "\n")
	for _, p := range s.Peers {
		b.WriteString("\n[Peer]\nPublicKey = " + p.PublicKey + "\nAllowedIPs = " + p.Address + "\n")
	}
	return b.String()
}

func vpnTable(iface string) string { return "dockermgr_" + iface }

// stripAddress turns a saved interface file into the form wg setconf accepts.
func stripAddress(conf string) string {
	var out []string
	for _, l := range strings.Split(conf, "\n") {
		if !strings.HasPrefix(strings.TrimSpace(l), "Address") {
			out = append(out, l)
		}
	}
	return strings.Join(out, "\n")
}

// vpnRules is the nftables table for one VPN interface: default deny for client traffic,
// with the allowed destinations, and NAT out of the gateway for a full tunnel.
func vpnRules(s VPNSpec) string {
	_, network, _ := net.ParseCIDR(s.Address)
	gw, _, _ := net.ParseCIDR(s.Address)
	t := vpnTable(s.Interface)
	var b strings.Builder
	b.WriteString("table inet " + t + "\ndelete table inet " + t + "\ntable inet " + t + " {\n")
	// Clients may reach the gateway itself only for DNS; nothing else on the host.
	b.WriteString("  chain input {\n    type filter hook input priority -4; policy accept;\n")
	b.WriteString("    iifname \"" + s.Interface + "\" ct state established,related accept\n")
	b.WriteString("    iifname \"" + s.Interface + "\" ip daddr " + gw.String() + " udp dport 53 accept\n")
	b.WriteString("    iifname \"" + s.Interface + "\" ip daddr " + gw.String() + " tcp dport 53 accept\n")
	b.WriteString("    iifname \"" + s.Interface + "\" icmp type echo-request accept\n")
	b.WriteString("    iifname \"" + s.Interface + "\" drop\n  }\n")
	b.WriteString("  chain forward {\n    type filter hook forward priority -4; policy accept;\n")
	b.WriteString("    oifname \"" + s.Interface + "\" ct state established,related accept\n")
	b.WriteString("    iifname \"" + s.Interface + "\" oifname \"" + s.Interface + "\" drop\n") // no client-to-client
	if len(s.Reach) > 0 {
		b.WriteString("    iifname \"" + s.Interface + "\" ip daddr { " + strings.Join(s.Reach, ", ") + " } accept\n")
	}
	if s.Routing == "full" {
		// Anywhere public, but never other private ranges that were not listed.
		b.WriteString("    iifname \"" + s.Interface + "\" ip daddr { 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 100.64.0.0/10, 169.254.0.0/16 } drop\n")
		b.WriteString("    iifname \"" + s.Interface + "\" accept\n")
	}
	b.WriteString("    iifname \"" + s.Interface + "\" drop\n  }\n")
	// NAT every forwarded client packet to the gateway: servers on the mesh have no route back to
	// the VPN range, and a full tunnel needs it for the internet.
	b.WriteString("  chain postrouting {\n    type nat hook postrouting priority srcnat; policy accept;\n")
	b.WriteString("    ip saddr " + network.String() + " oifname != \"" + s.Interface + "\" masquerade\n  }\n")
	b.WriteString("}\n")
	return b.String()
}

// Interfaces are managed with ip and wg directly, not wg-quick: on Ubuntu 26.04 the wg-quick
// AppArmor profile cannot run ip or wg under the agent's NoNewPrivileges, and wg-quick still
// exits 0, so a failed bring-up would look like success.
func (e *Executor) vpnLinkUp(ctx context.Context, s VPNSpec, syncPath string) error {
	env := baseEnv()
	if o, err := e.run(ctx, "", env, "ip", "link", "add", "dev", s.Interface, "type", "wireguard"); err != nil {
		return fmt.Errorf("ip link add (is the wireguard kernel module available?): %v: %s", err, strings.TrimSpace(string(o)))
	}
	if o, err := e.run(ctx, "", env, "wg", "setconf", s.Interface, syncPath); err != nil {
		return fmt.Errorf("wg setconf: %v: %s", err, strings.TrimSpace(string(o)))
	}
	if o, err := e.run(ctx, "", env, "ip", "address", "replace", s.Address, "dev", s.Interface); err != nil {
		return fmt.Errorf("ip address: %v: %s", err, strings.TrimSpace(string(o)))
	}
	if o, err := e.run(ctx, "", env, "ip", "link", "set", "dev", s.Interface, "up"); err != nil {
		return fmt.Errorf("ip link set up: %v: %s", err, strings.TrimSpace(string(o)))
	}
	return nil
}

func (e *Executor) vpnLinkDel(ctx context.Context, iface string) {
	_, _ = e.run(ctx, "", baseEnv(), "ip", "link", "del", "dev", iface)
}

func (e *Executor) vpnDown(ctx context.Context, iface string) {
	env := baseEnv()
	_, _ = e.run(ctx, "", env, "nft", "delete", "table", "inet", vpnTable(iface))
	e.vpnLinkDel(ctx, iface)
	_ = os.Remove(filepath.Join(e.wgDir(), iface+".conf"))
	_ = os.Remove(filepath.Join(e.StateDir, "vpn", iface+".nft"))
}

// vpnApply brings a VPN network up or changes it. When any step fails, the previous config
// and rules are restored (or the interface removed when there were none), so a bad change
// never leaves the gateway half-configured.
func (e *Executor) vpnApply(ctx context.Context, raw []byte, progress func(int, string)) (any, error) {
	var s VPNSpec
	if err := decode(raw, &s); err != nil {
		return nil, err
	}
	if err := s.validate(); err != nil {
		return nil, err
	}
	if s.Down {
		e.vpnDown(ctx, s.Interface)
		return map[string]any{"interface": s.Interface, "up": false}, nil
	}
	if !e.meshLook("wg") || !e.meshLook("nft") || !e.meshLook("ip") {
		return nil, errors.New("wireguard-tools, iproute2 and nftables must be installed on the gateway")
	}
	env := baseEnv()
	priv, pub, err := e.vpnKey(s.Interface)
	if err != nil {
		return nil, err
	}
	confPath := filepath.Join(e.wgDir(), s.Interface+".conf")
	rulesDir := filepath.Join(e.StateDir, "vpn")
	rulesPath := filepath.Join(rulesDir, s.Interface+".nft")
	prevConf, prevConfErr := os.ReadFile(confPath)
	prevRules, prevRulesErr := os.ReadFile(rulesPath)
	_, liveErr := e.run(ctx, "", env, "wg", "show", s.Interface)
	wasLive := liveErr == nil

	rollback := func(cause error) error {
		if prevConfErr == nil {
			_ = os.WriteFile(confPath, prevConf, 0o600)
		} else {
			_ = os.Remove(confPath)
		}
		if prevRulesErr == nil {
			_ = os.WriteFile(rulesPath, prevRules, 0o600)
			_, _ = e.run(ctx, "", env, "nft", "-f", rulesPath)
		} else {
			_, _ = e.run(ctx, "", env, "nft", "delete", "table", "inet", vpnTable(s.Interface))
			_ = os.Remove(rulesPath)
		}
		if wasLive && prevConfErr == nil {
			// Put the previous peers and key back on the running interface.
			prev := filepath.Join(e.StateDir, "wireguard", s.Interface+".prev.conf")
			if os.WriteFile(prev, []byte(stripAddress(string(prevConf))), 0o600) == nil {
				_, _ = e.run(ctx, "", env, "wg", "setconf", s.Interface, prev)
				_ = os.Remove(prev)
			}
		} else if !wasLive {
			e.vpnLinkDel(ctx, s.Interface)
		}
		return fmt.Errorf("%v (rolled back)", cause)
	}

	progress(20, "checking firewall rules")
	if err := os.MkdirAll(rulesDir, 0o700); err != nil {
		return nil, err
	}
	rules := vpnRules(s)
	check := filepath.Join(rulesDir, s.Interface+".check.nft")
	if err := os.WriteFile(check, []byte(rules), 0o600); err != nil {
		return nil, err
	}
	defer os.Remove(check)
	if o, err := e.run(ctx, "", env, "nft", "--check", "-f", check); err != nil {
		return nil, fmt.Errorf("VPN rules rejected by nft: %s", strings.TrimSpace(string(o)))
	}

	progress(40, "writing "+s.Interface)
	if err := os.MkdirAll(e.wgDir(), 0o700); err != nil {
		return nil, err
	}
	if err := os.WriteFile(confPath, []byte(vpnConfig(s, priv, true)), 0o600); err != nil {
		return nil, err
	}
	sync := filepath.Join(e.StateDir, "wireguard", s.Interface+".sync.conf")
	if err := os.MkdirAll(filepath.Dir(sync), 0o700); err != nil {
		return nil, rollback(err)
	}
	if err := os.WriteFile(sync, []byte(vpnConfig(s, priv, false)), 0o600); err != nil {
		return nil, rollback(err)
	}
	if wasLive {
		if o, err := e.run(ctx, "", env, "wg", "syncconf", s.Interface, sync); err != nil {
			return nil, rollback(fmt.Errorf("wg syncconf: %v: %s", err, strings.TrimSpace(string(o))))
		}
		if o, err := e.run(ctx, "", env, "ip", "address", "replace", s.Address, "dev", s.Interface); err != nil {
			return nil, rollback(fmt.Errorf("ip address: %v: %s", err, strings.TrimSpace(string(o))))
		}
	} else {
		e.vpnLinkDel(ctx, s.Interface) // a stale link without config would make "ip link add" fail
		if err := e.vpnLinkUp(ctx, s, sync); err != nil {
			return nil, rollback(err)
		}
	}
	// Prove it is really up rather than trusting exit codes.
	if _, err := e.run(ctx, "", env, "wg", "show", s.Interface); err != nil {
		return nil, rollback(fmt.Errorf("%s is not up after applying", s.Interface))
	}

	progress(70, "loading firewall rules")
	sysctl := filepath.Join(firstNonEmpty(e.ProcSysDir, "/proc/sys"), "net", "ipv4", "ip_forward")
	if err := os.WriteFile(sysctl, []byte("1\n"), 0o644); err != nil {
		return nil, rollback(fmt.Errorf("enabling ip_forward: %v", err))
	}
	if err := os.WriteFile(rulesPath, []byte(rules), 0o600); err != nil {
		return nil, rollback(err)
	}
	if o, err := e.run(ctx, "", env, "nft", "-f", rulesPath); err != nil {
		return nil, rollback(fmt.Errorf("loading VPN rules: %s", strings.TrimSpace(string(o))))
	}

	out := map[string]any{"interface": s.Interface, "up": true, "public_key": pub, "peers": len(s.Peers), "applied_at": time.Now().UTC().Format(time.RFC3339)}
	if s.OpenPort {
		out["port_opened"] = e.meshOpenPort(ctx, s.ListenPort)
	}
	return out, nil
}

// vpnStatus reports a VPN interface's peers (last handshake, traffic) from `wg show dump`.
func (e *Executor) vpnStatus(ctx context.Context, raw []byte) (any, error) {
	var p struct {
		Interface string `json:"interface"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if !reVPNIface.MatchString(p.Interface) {
		return nil, fmt.Errorf("invalid interface %q", p.Interface)
	}
	o, err := e.run(ctx, "", baseEnv(), "wg", "show", p.Interface, "dump")
	if err != nil {
		return map[string]any{"interface": p.Interface, "up": false, "peers": []any{}}, nil
	}
	peers := []map[string]any{}
	for i, line := range strings.Split(strings.TrimSpace(string(o)), "\n") {
		f := strings.Split(line, "\t")
		if i == 0 || len(f) < 8 {
			continue
		}
		hs, _ := strconv.ParseInt(f[4], 10, 64)
		rx, _ := strconv.ParseInt(f[5], 10, 64)
		tx, _ := strconv.ParseInt(f[6], 10, 64)
		peer := map[string]any{"public_key": f[0], "endpoint": f[2], "rx_bytes": rx, "tx_bytes": tx}
		if hs > 0 {
			peer["last_handshake"] = time.Unix(hs, 0).UTC().Format(time.RFC3339)
		}
		peers = append(peers, peer)
	}
	return map[string]any{"interface": p.Interface, "up": true, "peers": peers}, nil
}
