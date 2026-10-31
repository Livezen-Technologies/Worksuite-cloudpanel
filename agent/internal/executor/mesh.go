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
	Address    string     `json:"address"` // this server, CIDR (10.88.0.3/16)
	ListenPort int        `json:"listen_port"`
	Peers      []MeshPeer `json:"peers"`
	Down       bool       `json:"down"`
	DNS        *struct {
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
	if s.DNS != nil {
		for _, r := range s.DNS.Records {
			if !reDNSName.MatchString(r.Name) || net.ParseIP(r.IP).To4() == nil {
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
		b.WriteString("Address = " + s.Address + "\n")
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
	} else if o, err := e.run(ctx, "", env, "wg-quick", "up", MeshInterface); err != nil {
		return nil, fmt.Errorf("wg-quick up (is wireguard-tools installed?): %v: %s", err, strings.TrimSpace(string(o)))
	}
	out := map[string]any{"interface": MeshInterface, "up": true, "public_key": pub, "peers": len(s.Peers)}
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
