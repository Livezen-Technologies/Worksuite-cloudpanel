package executor

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/livezen-technologies/cloudpanel/agent/internal/protocol"
)

type fakeVPN struct {
	calls   [][]string
	live    map[string]bool
	failNft bool // `nft -f <rules>` (not --check) fails
}

func (f *fakeVPN) run(_ context.Context, _ string, _ []string, name string, args ...string) ([]byte, error) {
	f.calls = append(f.calls, append([]string{name}, args...))
	if f.live == nil {
		f.live = map[string]bool{}
	}
	switch {
	case name == "wg" && len(args) == 2 && args[0] == "show":
		if f.live[args[1]] {
			return []byte("interface: " + args[1] + "\n"), nil
		}
		return nil, errors.New("Unable to access interface")
	case name == "wg-quick":
		return nil, errors.New("wg-quick must not be used")
	case name == "ip" && len(args) >= 6 && args[0] == "link" && args[1] == "add":
		f.live[args[3]] = true
	case name == "ip" && len(args) == 4 && args[0] == "link" && args[1] == "del":
		f.live[args[3]] = false
	case name == "nft" && len(args) == 2 && args[0] == "-f" && f.failNft && strings.HasSuffix(args[1], "wsv1.nft"):
		return []byte("Error: syntax"), errors.New("exit 1")
	}
	return nil, nil
}

func (f *fakeVPN) ran(name string, args ...string) bool {
	want := strings.Join(append([]string{name}, args...), " ")
	for _, c := range f.calls {
		if strings.Join(c, " ") == want {
			return true
		}
	}
	return false
}

func vpnExec(t *testing.T, f *fakeVPN) (*Executor, string) {
	dir := t.TempDir()
	proc := filepath.Join(dir, "proc")
	_ = os.MkdirAll(filepath.Join(proc, "net", "ipv4"), 0o755)
	return &Executor{HMACKey: key, Journal: &Journal{Path: filepath.Join(dir, "j.json")}, Report: (&fakePanel{results: map[string][]protocol.Result{}}).report,
		StateDir: filepath.Join(dir, "state"), WireGuardDir: filepath.Join(dir, "wg"), ProcSysDir: proc, Run: f.run, Now: func() time.Time { return now },
		LookPath: func(string) (string, error) { return "/usr/bin/x", nil }}, dir
}

const vpnPeerKey = "xTIBA5rboUvnH4htodjb6e697QjLERt1NAB4mZqp8Dg="

func TestVPNApplyWritesConfigRulesAndKey(t *testing.T) {
	f := &fakeVPN{}
	e, dir := vpnExec(t, f)
	payload := `{"interface":"wsv1","address":"10.90.0.1/24","listen_port":51821,"routing":"split","reach":["10.88.0.0/16","10.89.0.0/16"],
		"peers":[{"public_key":"` + vpnPeerKey + `","address":"10.90.0.10/32"}]}`
	r, err := e.Handle(context.Background(), cmd("v1", "vpn.apply", payload, now))
	if err != nil {
		t.Fatal(err, r.Message)
	}
	out := r.Output.(map[string]any)
	if out["up"] != true || !reWGKey.MatchString(out["public_key"].(string)) {
		t.Fatalf("output %v", out)
	}
	conf, _ := os.ReadFile(filepath.Join(dir, "wg", "wsv1.conf"))
	for _, want := range []string{"Address = 10.90.0.1/24", "ListenPort = 51821", "PublicKey = " + vpnPeerKey, "AllowedIPs = 10.90.0.10/32"} {
		if !strings.Contains(string(conf), want) {
			t.Errorf("config lacks %q:\n%s", want, conf)
		}
	}
	if st, _ := os.Stat(filepath.Join(dir, "wg", "wsv1.conf")); st.Mode().Perm() != 0o600 {
		t.Error("config readable by others")
	}
	if key, _ := os.ReadFile(filepath.Join(dir, "state", "wireguard", "wsv1.key")); len(key) == 0 || strings.Contains(string(conf), out["public_key"].(string)) {
		t.Error("key not stored, or own public key in config")
	}
	rules, _ := os.ReadFile(filepath.Join(dir, "state", "vpn", "wsv1.nft"))
	for _, want := range []string{`ip daddr { 10.88.0.0/16, 10.89.0.0/16 } accept`, `iifname "wsv1" oifname "wsv1" drop`, `iifname "wsv1" drop`, "masquerade"} {
		if !strings.Contains(string(rules), want) {
			t.Errorf("rules lack %q:\n%s", want, rules)
		}
	}
	if strings.Contains(string(rules), `iifname "wsv1" accept`) {
		t.Error("split tunnel must not forward everything")
	}
	if fwd, _ := os.ReadFile(filepath.Join(dir, "proc", "net", "ipv4", "ip_forward")); strings.TrimSpace(string(fwd)) != "1" {
		t.Error("ip_forward not enabled")
	}
	for _, want := range [][]string{{"ip", "link", "add", "dev", "wsv1", "type", "wireguard"}, {"wg", "setconf", "wsv1", filepath.Join(dir, "state", "wireguard", "wsv1.sync.conf")}, {"ip", "address", "replace", "10.90.0.1/24", "dev", "wsv1"}, {"ip", "link", "set", "dev", "wsv1", "up"}} {
		if !f.ran(want[0], want[1:]...) {
			t.Errorf("did not run %v: %v", want, f.calls)
		}
	}

	// A second apply on the live interface swaps peers without taking it down.
	f.calls = nil
	if _, err := e.Handle(context.Background(), cmd("v2", "vpn.apply", strings.Replace(payload, `"peers":[{"public_key":"`+vpnPeerKey+`","address":"10.90.0.10/32"}]`, `"peers":[]`, 1), now)); err != nil {
		t.Fatal(err)
	}
	if f.ran("ip", "link", "add", "dev", "wsv1", "type", "wireguard") || !f.ran("wg", "syncconf", "wsv1", filepath.Join(dir, "state", "wireguard", "wsv1.sync.conf")) {
		t.Errorf("live change should syncconf: %v", f.calls)
	}
}

func TestVPNFullTunnelNatsAndBlocksOtherPrivateRanges(t *testing.T) {
	rules := vpnRules(VPNSpec{Interface: "wsv2", Address: "10.90.1.1/24", ListenPort: 51822, Routing: "full", Reach: []string{"10.88.0.0/16"}})
	for _, want := range []string{"ip saddr 10.90.1.0/24 oifname != \"wsv2\" masquerade", "169.254.0.0/16 } drop", `iifname "wsv2" accept`} {
		if !strings.Contains(rules, want) {
			t.Errorf("rules lack %q:\n%s", want, rules)
		}
	}
	if strings.Index(rules, "ip daddr { 10.88.0.0/16 } accept") > strings.Index(rules, "169.254.0.0/16 } drop") {
		t.Error("listed private ranges must be allowed before other private ranges are dropped")
	}
}

func TestVPNApplyRollsBackWhenRulesFailToLoad(t *testing.T) {
	f := &fakeVPN{failNft: true}
	e, dir := vpnExec(t, f)
	payload := `{"interface":"wsv1","address":"10.90.0.1/24","listen_port":51821,"routing":"split","reach":["10.88.0.0/16"]}`
	if r, err := e.Handle(context.Background(), cmd("v1", "vpn.apply", payload, now)); err == nil && r.Status == "succeeded" {
		t.Fatal("expected failure")
	}
	if _, err := os.Stat(filepath.Join(dir, "wg", "wsv1.conf")); !os.IsNotExist(err) {
		t.Error("config left behind after a failed first apply")
	}
	if f.live["wsv1"] || !f.ran("ip", "link", "del", "dev", "wsv1") {
		t.Error("interface left up after a failed first apply")
	}
}

func TestVPNSpecValidation(t *testing.T) {
	ok := VPNSpec{Interface: "wsv1", Address: "10.90.0.1/24", ListenPort: 51821, Routing: "split"}
	if err := ok.validate(); err != nil {
		t.Fatal(err)
	}
	bad := map[string]VPNSpec{
		"iface":        {Interface: "eth0", Address: "10.90.0.1/24", ListenPort: 51821, Routing: "split"},
		"public range": {Interface: "wsv1", Address: "8.8.8.1/24", ListenPort: 51821, Routing: "split"},
		"network addr": {Interface: "wsv1", Address: "10.90.0.0/24", ListenPort: 51821, Routing: "split"},
		"port":         {Interface: "wsv1", Address: "10.90.0.1/24", ListenPort: 80, Routing: "split"},
		"routing":      {Interface: "wsv1", Address: "10.90.0.1/24", ListenPort: 51821, Routing: "all"},
		"reach public": {Interface: "wsv1", Address: "10.90.0.1/24", ListenPort: 51821, Routing: "split", Reach: []string{"0.0.0.0/0"}},
		"peer outside": {Interface: "wsv1", Address: "10.90.0.1/24", ListenPort: 51821, Routing: "split", Peers: []VPNPeer{{PublicKey: vpnPeerKey, Address: "10.91.0.5/32"}}},
		"peer not /32": {Interface: "wsv1", Address: "10.90.0.1/24", ListenPort: 51821, Routing: "split", Peers: []VPNPeer{{PublicKey: vpnPeerKey, Address: "10.90.0.5/24"}}},
		"peer is gw":   {Interface: "wsv1", Address: "10.90.0.1/24", ListenPort: 51821, Routing: "split", Peers: []VPNPeer{{PublicKey: vpnPeerKey, Address: "10.90.0.1/32"}}},
		"peer key":     {Interface: "wsv1", Address: "10.90.0.1/24", ListenPort: 51821, Routing: "split", Peers: []VPNPeer{{PublicKey: "nope", Address: "10.90.0.5/32"}}},
	}
	for name, s := range bad {
		if err := s.validate(); err == nil {
			t.Errorf("%s: accepted", name)
		}
	}
	cg := VPNSpec{Interface: "wsv1", Address: "100.64.0.1/24", ListenPort: 51821, Routing: "split"}
	if err := cg.validate(); err != nil {
		t.Errorf("100.64/10 rejected: %v", err)
	}
}

func TestVPNStatusParsesPeers(t *testing.T) {
	f := &fakeVPN{}
	e, _ := vpnExec(t, f)
	e.Run = func(_ context.Context, _ string, _ []string, name string, args ...string) ([]byte, error) {
		return []byte("priv\tpub\t51821\toff\n" + vpnPeerKey + "\t(none)\t198.51.100.7:40000\t10.90.0.10/32\t1795000000\t1200\t3400\toff\n"), nil
	}
	r, err := e.Handle(context.Background(), cmd("s1", "vpn.status", `{"interface":"wsv1"}`, now))
	if err != nil {
		t.Fatal(err)
	}
	peers := r.Output.(map[string]any)["peers"].([]map[string]any)
	if len(peers) != 1 || peers[0]["rx_bytes"].(int64) != 1200 || peers[0]["last_handshake"] == nil {
		t.Fatalf("peers %v", peers)
	}
	if _, err := e.Handle(context.Background(), cmd("s2", "vpn.status", `{"interface":"../x"}`, now)); err == nil {
		r, _ := e.Handle(context.Background(), cmd("s3", "vpn.status", `{"interface":"../x"}`, now))
		if r.Status == "succeeded" {
			t.Error("bad interface accepted")
		}
	}
}
