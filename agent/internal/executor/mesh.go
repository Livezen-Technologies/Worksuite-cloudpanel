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
	"sort"
	"strconv"
	"strings"
	"time"
)

// MeshInterface is the WireGuard interface of the private network (PNET-1).
const MeshInterface = "dmp0"

// MeshSpec is the mesh.apply payload.
type MeshSpec struct {
	Address string `json:"address"` // this server, CIDR (10.88.0.3/16)
	// Address6 is this server's IPv6 ULA on the mesh (fd88::3/64), optional.
	Address6   string     `json:"address6"`
	ListenPort int        `json:"listen_port"`
	Peers      []MeshPeer `json:"peers"`
	Down       bool       `json:"down"`
	// ContainerSubnet (PNET-4) is this server's slice of ContainerSupernet for the
	// dmp-mesh Docker network; peers route it over WireGuard.
	ContainerSubnet   string `json:"container_subnet"`
	ContainerSupernet string `json:"container_supernet"`
	// OpenPort opens the WireGuard port in UFW or firewalld when either is active.
	OpenPort bool `json:"open_port"`
	// SSHLock (PNET-7) drops SSH from everywhere except the mesh and Allow.
	// It rolls itself back after ConfirmWithin seconds unless mesh.confirm arrives.
	SSHLock *struct {
		Port          int      `json:"port"`
		Allow         []string `json:"allow"`
		ConfirmWithin int      `json:"confirm_within_seconds"`
	} `json:"ssh_lock"`
	DNS *struct {
		Image   string `json:"image"`
		Zone    string `json:"zone"`
		Records []struct {
			Name string `json:"name"`
			IP   string `json:"ip"`
		} `json:"records"`
	} `json:"dns"`
}

type MeshPeer struct {
	PublicKey  string   `json:"public_key"`
	Endpoint   string   `json:"endpoint"`
	AllowedIPs []string `json:"allowed_ips"`
	Keepalive  int      `json:"keepalive"`
}

var (
	reWGKey   = regexp.MustCompile(`^[A-Za-z0-9+/]{42}[AEIMQUYcgkosw480]=$`)
	reDNSName = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$`)
)

// meshKey loads this server's WireGuard private key, creating it on first
// use. The private key never leaves the server; only the public key is reported.
func (e *Executor) meshKey() (priv, pub string, err error) {
	path := filepath.Join(e.StateDir, "wireguard", "private.key")
	raw, err := os.ReadFile(path)
	var key *ecdh.PrivateKey
	if err == nil {
		b, derr := base64.StdEncoding.DecodeString(strings.TrimSpace(string(raw)))
		if derr != nil {
			return "", "", fmt.Errorf("corrupt %s: %v", path, derr)
		}
		if key, err = ecdh.X25519().NewPrivateKey(b); err != nil {
			return "", "", err
		}
	} else if errors.Is(err, os.ErrNotExist) {
		if key, err = ecdh.X25519().GenerateKey(rand.Reader); err != nil {
			return "", "", err
		}
		if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
			return "", "", err
		}
		if err := os.WriteFile(path, []byte(base64.StdEncoding.EncodeToString(key.Bytes())+"\n"), 0o600); err != nil {
			return "", "", err
		}
	} else {
		return "", "", err
	}

	return base64.StdEncoding.EncodeToString(key.Bytes()), base64.StdEncoding.EncodeToString(key.PublicKey().Bytes()), nil
}

func (s *MeshSpec) validate() error {
	if s.Down {
		return nil
	}
	ip, _, err := net.ParseCIDR(s.Address)
	if err != nil || ip.To4() == nil {
		return fmt.Errorf("invalid address %q", s.Address)
	}
	if s.Address6 != "" {
		ip6, _, err := net.ParseCIDR(s.Address6)
		if err != nil || ip6.To4() != nil || !ip6.IsPrivate() {
			return fmt.Errorf("invalid address6 %q (an IPv6 ULA, fd00::/8)", s.Address6)
		}
	}
	if s.ListenPort == 0 {
		s.ListenPort = 51820
	}
	if s.ListenPort < 1024 || s.ListenPort > 65535 {
		return fmt.Errorf("invalid listen_port %d", s.ListenPort)
	}
	for _, p := range s.Peers {
		if !reWGKey.MatchString(p.PublicKey) {
			return fmt.Errorf("invalid peer public key %q", p.PublicKey)
		}
		if p.Endpoint != "" {
			if _, _, err := net.SplitHostPort(p.Endpoint); err != nil || strings.ContainsAny(p.Endpoint, " \n\r\t") {
				return fmt.Errorf("invalid peer endpoint %q", p.Endpoint)
			}
		}
		if len(p.AllowedIPs) == 0 {
			return errors.New("peer without allowed_ips")
		}
		for _, a := range p.AllowedIPs {
			if _, _, err := net.ParseCIDR(a); err != nil {
				return fmt.Errorf("invalid allowed ip %q", a)
			}
		}
		if p.Keepalive < 0 || p.Keepalive > 600 {
			return fmt.Errorf("invalid keepalive %d", p.Keepalive)
		}
	}
	if s.ContainerSubnet != "" {
		_, sub, err1 := net.ParseCIDR(s.ContainerSubnet)
		_, super, err2 := net.ParseCIDR(s.ContainerSupernet)
		if err1 != nil || err2 != nil || sub.IP.To4() == nil || !super.Contains(sub.IP) {
			return errors.New("invalid container_subnet / container_supernet")
		}
	}
	if s.SSHLock != nil {
		if s.SSHLock.Port == 0 {
			s.SSHLock.Port = 22
		}
		if s.SSHLock.Port < 1 || s.SSHLock.Port > 65535 || s.SSHLock.ConfirmWithin < 0 || s.SSHLock.ConfirmWithin > 3600 {
			return errors.New("invalid ssh_lock")
		}
		if _, _, err := parseCIDRs("ssh_lock.allow", s.SSHLock.Allow); err != nil {
			return err
		}
	}
	if s.DNS != nil {
		for _, r := range s.DNS.Records {
			if !reDNSName.MatchString(r.Name) || net.ParseIP(r.IP) == nil {
				return fmt.Errorf("invalid dns record %q %q", r.Name, r.IP)
			}
		}
		if !reDNSName.MatchString(firstNonEmpty(s.DNS.Zone, "internal")) || checkImage(firstNonEmpty(s.DNS.Image, "coredns/coredns:1.11.3")) != nil {
			return errors.New("invalid dns zone or image")
		}
	}
	return nil
}

// wgConfig renders the interface file; withAddress=false gives the
// `wg syncconf` form (no wg-quick keys).
func wgConfig(s MeshSpec, priv string, withAddress bool) string {
	var b strings.Builder
	b.WriteString("# Managed by the dockermgr agent; changes are overwritten.\n[Interface]\n")
	b.WriteString("PrivateKey = " + priv + "\n")
	if withAddress {
		b.WriteString("Address = " + strings.Join(nonEmpty(s.Address, s.Address6), ", ") + "\n")
	}
	b.WriteString("ListenPort = " + strconv.Itoa(s.ListenPort) + "\n")
	for _, p := range s.Peers {
		b.WriteString("\n[Peer]\nPublicKey = " + p.PublicKey + "\nAllowedIPs = " + strings.Join(p.AllowedIPs, ", ") + "\n")
		if p.Endpoint != "" {
			b.WriteString("Endpoint = " + p.Endpoint + "\n")
		}
		if p.Keepalive > 0 {
			b.WriteString("PersistentKeepalive = " + strconv.Itoa(p.Keepalive) + "\n")
		}
	}
	return b.String()
}

func (e *Executor) wgDir() string {
	return firstNonEmpty(e.WireGuardDir, "/etc/wireguard")
}

func (e *Executor) meshApply(ctx context.Context, raw []byte, progress func(int, string)) (any, error) {
	var s MeshSpec
	if err := decode(raw, &s); err != nil {
		return nil, err
	}
	if err := s.validate(); err != nil {
		return nil, err
	}
	env := baseEnv()
	confPath := filepath.Join(e.wgDir(), MeshInterface+".conf")
	if s.Down {
		if _, err := os.Stat(filepath.Join(e.StateDir, "mesh", "ssh-lock.nft")); err == nil {
			_, _ = e.run(ctx, "", env, "nft", "delete", "table", "inet", meshTable)
			_ = os.Remove(filepath.Join(e.StateDir, "mesh", "ssh-lock.nft"))
		}
		_, _ = e.run(ctx, "", env, "wg-quick", "down", MeshInterface)
		_ = os.Remove(confPath)
		if e.Docker != nil {
			_ = e.Docker.RemoveContainer(ctx, "dmp-coredns", true, false)
		}
		return map[string]any{"interface": MeshInterface, "up": false}, nil
	}
	priv, pub, err := e.meshKey()
	if err != nil {
		return nil, err
	}
	if err := os.MkdirAll(e.wgDir(), 0o700); err != nil {
		return nil, err
	}
	if err := os.WriteFile(confPath, []byte(wgConfig(s, priv, true)), 0o600); err != nil {
		return nil, err
	}
	progress(40, "applying "+MeshInterface)
	if _, err := e.run(ctx, "", env, "wg", "show", MeshInterface); err == nil {
		// Live: swap peers without dropping the interface.
		sync := filepath.Join(e.StateDir, "wireguard", MeshInterface+".sync.conf")
		if err := os.WriteFile(sync, []byte(wgConfig(s, priv, false)), 0o600); err != nil {
			return nil, err
		}
		if o, err := e.run(ctx, "", env, "wg", "syncconf", MeshInterface, sync); err != nil {
			return nil, fmt.Errorf("wg syncconf: %v: %s", err, strings.TrimSpace(string(o)))
		}
		// syncconf leaves addresses alone: add the IPv6 one to a live interface.
		if s.Address6 != "" {
			if o, err := e.run(ctx, "", env, "ip", "-6", "address", "replace", s.Address6, "dev", MeshInterface); err != nil {
				return nil, fmt.Errorf("ip -6 address: %v: %s", err, strings.TrimSpace(string(o)))
			}
		}
	} else if o, err := e.run(ctx, "", env, "wg-quick", "up", MeshInterface); err != nil {
		return nil, fmt.Errorf("wg-quick up (is wireguard-tools installed?): %v: %s", err, strings.TrimSpace(string(o)))
	}
	out := map[string]any{"interface": MeshInterface, "up": true, "public_key": pub, "peers": len(s.Peers), "ipv6": s.Address6 != ""}
	if s.OpenPort {
		out["port_opened"] = e.meshOpenPort(ctx, s.ListenPort)
	}
	if s.ContainerSubnet != "" {
		if err := e.meshContainerNetwork(ctx, s); err != nil {
			out["container_network_error"] = err.Error()
		} else {
			out["container_network"] = MeshNetwork
		}
	}
	if lock, err := e.meshSSHLock(ctx, s); err != nil {
		return out, err
	} else if lock != nil {
		out["ssh_lock"] = lock
	}
	if s.DNS != nil {
		progress(70, "updating internal DNS")
		if err := e.meshDNS(ctx, s); err != nil {
			out["dns_error"] = err.Error()
		} else {
			out["dns_records"] = len(s.DNS.Records)
		}
	}
	return out, nil
}

// meshDNS (PNET-2) writes the CoreDNS Corefile and hosts file and runs
// dmp-coredns bound to the mesh address only, so *.internal resolves inside
// the private network and nowhere else. CoreDNS reloads the hosts file itself.
func (e *Executor) meshDNS(ctx context.Context, s MeshSpec) error {
	ip, _, _ := net.ParseCIDR(s.Address)
	zone := firstNonEmpty(s.DNS.Zone, "internal")
	dir := filepath.Join(e.StateDir, "coredns")
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return err
	}
	recs := s.DNS.Records
	sort.Slice(recs, func(i, j int) bool { return recs[i].Name < recs[j].Name })
	var hosts strings.Builder
	for _, r := range recs {
		hosts.WriteString(r.IP + " " + r.Name + "\n")
	}
	corefile := zone + ":53 {\n    hosts /etc/coredns/hosts {\n        reload 10s\n    }\n    errors\n}\n.:53 {\n    forward . /etc/resolv.conf\n    cache 30\n    errors\n}\n"
	if err := os.WriteFile(filepath.Join(dir, "hosts"), []byte(hosts.String()), 0o644); err != nil {
		return err
	}
	if err := os.WriteFile(filepath.Join(dir, "Corefile"), []byte(corefile), 0o644); err != nil {
		return err
	}
	if e.Docker == nil {
		return nil
	}
	if c, err := e.Docker.InspectContainer(ctx, "dmp-coredns"); err == nil && c != nil {
		return nil // running already; the hosts plugin picks up the new file
	}
	image := firstNonEmpty(s.DNS.Image, "coredns/coredns:1.11.3")
	if ok, _ := e.Docker.ImageExists(ctx, image); !ok {
		name, tag := splitTag(image, "")
		if err := e.Docker.PullImage(ctx, name, tag, nil, nil); err != nil {
			return err
		}
	}
	port := map[string]any{"53/udp": []map[string]string{{"HostIp": ip.String(), "HostPort": "53"}}, "53/tcp": []map[string]string{{"HostIp": ip.String(), "HostPort": "53"}}}
	body := map[string]any{
		"Image": image, "Cmd": []string{"-conf", "/etc/coredns/Corefile"},
		"Labels":       map[string]string{"io.dockermgr.managed": "true", "io.dockermgr.role": "coredns"},
		"ExposedPorts": map[string]any{"53/udp": struct{}{}, "53/tcp": struct{}{}},
		"HostConfig": map[string]any{
			"Mounts":         []map[string]any{{"Type": "bind", "Source": dir, "Target": "/etc/coredns", "ReadOnly": true}},
			"PortBindings":   port,
			"RestartPolicy":  map[string]string{"Name": "unless-stopped"},
			"ReadonlyRootfs": true, "CapDrop": []string{"ALL"}, "CapAdd": []string{"NET_BIND_SERVICE"},
			"SecurityOpt": []string{"no-new-privileges"},
		},
	}
	id, err := e.Docker.CreateContainer(ctx, "dmp-coredns", body)
	if err != nil {
		return err
	}
	return e.Docker.StartContainer(ctx, id)
}

// meshStatus (PNET-5) reports each peer's last handshake and traffic from `wg show dump`.
func (e *Executor) meshStatus(ctx context.Context) (any, error) {
	o, err := e.run(ctx, "", baseEnv(), "wg", "show", MeshInterface, "dump")
	if err != nil {
		return map[string]any{"up": false, "peers": []any{}}, nil
	}
	peers := []map[string]any{}
	for i, line := range strings.Split(strings.TrimSpace(string(o)), "\n") {
		f := strings.Split(line, "\t")
		if i == 0 || len(f) < 8 {
			continue // the first line is the interface
		}
		hs, _ := strconv.ParseInt(f[4], 10, 64)
		rx, _ := strconv.ParseInt(f[5], 10, 64)
		tx, _ := strconv.ParseInt(f[6], 10, 64)
		p := map[string]any{"public_key": f[0], "endpoint": f[2], "rx_bytes": rx, "tx_bytes": tx}
		if hs > 0 {
			p["last_handshake"] = time.Unix(hs, 0).UTC().Format(time.RFC3339)
		}
		peers = append(peers, p)
	}
	return map[string]any{"up": true, "peers": peers}, nil
}

// meshTable holds the SSH lock; it is separate from the panel firewall's table.
const meshTable = "dockermgr_mesh"

func (e *Executor) meshLook(name string) bool {
	look := e.LookPath
	if look == nil {
		look = execLookPath
	}
	_, err := look(name)
	return err == nil
}

// meshOpenPort allows the WireGuard UDP port in the host firewall that is active.
func (e *Executor) meshOpenPort(ctx context.Context, port int) string {
	p := strconv.Itoa(port) + "/udp"
	if e.meshLook("ufw") {
		if o, err := e.run(ctx, "", baseEnv(), "ufw", "status"); err == nil && strings.Contains(string(o), "Status: active") {
			if _, err := e.run(ctx, "", baseEnv(), "ufw", "allow", p, "comment", "dmp-mesh"); err == nil {
				return "ufw"
			}
			return "ufw-failed"
		}
	}
	if e.meshLook("firewall-cmd") {
		if o, err := e.run(ctx, "", baseEnv(), "firewall-cmd", "--state"); err == nil && strings.TrimSpace(string(o)) == "running" {
			if _, err := e.run(ctx, "", baseEnv(), "firewall-cmd", "--permanent", "--add-port="+p); err == nil {
				_, _ = e.run(ctx, "", baseEnv(), "firewall-cmd", "--reload")
				return "firewalld"
			}
			return "firewalld-failed"
		}
	}
	return "none"
}

// meshSSHLock loads or removes the SSH lock. With a lock it returns its summary.
func (e *Executor) meshSSHLock(ctx context.Context, s MeshSpec) (map[string]any, error) {
	lockFile := filepath.Join(e.StateDir, "mesh", "ssh-lock.nft")
	if s.SSHLock == nil {
		if _, err := os.Stat(lockFile); err == nil {
			_, _ = e.run(ctx, "", baseEnv(), "nft", "delete", "table", "inet", meshTable)
			_ = os.Remove(lockFile)
		}
		return nil, nil
	}
	if !e.meshLook("nft") {
		return nil, errors.New("nftables is not installed; SSH cannot be limited to the private network")
	}
	_, mesh, _ := net.ParseCIDR(s.Address)
	v4, v6, _ := parseCIDRs("ssh_lock.allow", s.SSHLock.Allow)
	v4 = append([]string{mesh.String()}, v4...)
	var b strings.Builder
	b.WriteString("table inet " + meshTable + "\ndelete table inet " + meshTable + "\ntable inet " + meshTable + " {\n")
	b.WriteString("  chain input {\n    type filter hook input priority -5; policy accept;\n    iif lo accept\n")
	b.WriteString("    ct state established,related accept\n")
	b.WriteString(fmt.Sprintf("    tcp dport %d ip saddr { %s } accept\n", s.SSHLock.Port, strings.Join(v4, ", ")))
	if s.Address6 != "" {
		_, mesh6, _ := net.ParseCIDR(s.Address6)
		v6 = append([]string{mesh6.String()}, v6...)
	}
	if len(v6) > 0 {
		b.WriteString(fmt.Sprintf("    tcp dport %d ip6 saddr { %s } accept\n", s.SSHLock.Port, strings.Join(v6, ", ")))
	}
	b.WriteString(fmt.Sprintf("    tcp dport %d drop\n  }\n}\n", s.SSHLock.Port))
	dir := filepath.Join(e.StateDir, "mesh")
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, err
	}
	path := lockFile
	if err := os.WriteFile(path, []byte(b.String()), 0o600); err != nil {
		return nil, err
	}
	if o, err := e.run(ctx, "", baseEnv(), "nft", "--check", "-f", path); err != nil {
		return nil, fmt.Errorf("SSH lock rejected by nft: %s", strings.TrimSpace(string(o)))
	}
	if o, err := e.run(ctx, "", baseEnv(), "nft", "-f", path); err != nil {
		return nil, fmt.Errorf("SSH lock failed to load: %s", strings.TrimSpace(string(o)))
	}
	if s.SSHLock.ConfirmWithin > 0 {
		stamp := filepath.Join(dir, "ssh-pending")
		_ = os.WriteFile(stamp, []byte(time.Now().UTC().Format(time.RFC3339)), 0o600)
		seconds := s.SSHLock.ConfirmWithin
		go func() {
			time.Sleep(time.Duration(seconds) * time.Second)
			if _, err := os.Stat(stamp); err != nil {
				return // confirmed
			}
			ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
			defer cancel()
			_, _ = e.run(ctx, "", baseEnv(), "nft", "delete", "table", "inet", meshTable)
			_ = os.Remove(stamp)
		}()
	}
	return map[string]any{"port": s.SSHLock.Port, "allowed": append(v4, v6...), "rollback_in": s.SSHLock.ConfirmWithin}, nil
}

// meshConfirm keeps the SSH lock: it cancels the pending rollback.
func (e *Executor) meshConfirm() (any, error) {
	err := os.Remove(filepath.Join(e.StateDir, "mesh", "ssh-pending"))
	if err != nil && !os.IsNotExist(err) {
		return nil, err
	}
	return map[string]any{"confirmed": true}, nil
}

// MeshNetwork is the Docker network whose subnet is routed over the mesh (PNET-4).
const MeshNetwork = "dmp-mesh"

// meshContainerNetwork creates dmp-mesh with this server's subnet, turns on IP
// forwarding and lets traffic from the mesh reach it past Docker's own rules
// (the DOCKER-USER chain is the place Docker leaves for this).
func (e *Executor) meshContainerNetwork(ctx context.Context, s MeshSpec) error {
	if e.Docker != nil {
		nets, err := e.Docker.ListNetworks(ctx)
		if err != nil {
			return err
		}
		found := false
		for _, n := range nets {
			found = found || n.Name == MeshNetwork
		}
		if !found {
			_, sub, _ := net.ParseCIDR(s.ContainerSubnet)
			gw := make(net.IP, 4)
			copy(gw, sub.IP.To4())
			gw[3] = 1
			if _, err := e.Docker.CreateNetwork(ctx, map[string]any{
				"Name": MeshNetwork, "Driver": "bridge", "CheckDuplicate": true,
				"IPAM":    map[string]any{"Config": []map[string]string{{"Subnet": sub.String(), "Gateway": gw.String()}}},
				"Options": map[string]string{"com.docker.network.bridge.name": "dmp-mesh0"},
				"Labels":  map[string]string{"io.dockermgr.managed": "true", "io.dockermgr.role": "mesh"},
			}); err != nil {
				return err
			}
		}
	}
	sysctl := filepath.Join(firstNonEmpty(e.ProcSysDir, "/proc/sys"), "net", "ipv4", "ip_forward")
	if err := os.WriteFile(sysctl, []byte("1\n"), 0o644); err != nil {
		return fmt.Errorf("enabling ip_forward: %v", err)
	}
	_, mesh, _ := net.ParseCIDR(s.Address)
	rule := []string{"DOCKER-USER", "-s", mesh.String(), "-d", s.ContainerSupernet, "-j", "ACCEPT"}
	if _, err := e.run(ctx, "", baseEnv(), "iptables", append([]string{"-C"}, rule...)...); err != nil {
		if o, err := e.run(ctx, "", baseEnv(), "iptables", append([]string{"-I"}, rule...)...); err != nil {
			return fmt.Errorf("iptables: %v: %s", err, strings.TrimSpace(string(o)))
		}
	}
	return nil
}

// nonEmpty drops empty strings.
func nonEmpty(v ...string) []string {
	out := v[:0:0]
	for _, s := range v {
		if s != "" {
			out = append(out, s)
		}
	}
	return out
}
