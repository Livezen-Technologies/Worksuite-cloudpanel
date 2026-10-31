package executor

import (
	"context"
	"encoding/base64"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/livezen-technologies/cloudpanel/agent/internal/protocol"
)

type fakeWG struct {
	calls [][]string
	live  bool
	dump  string
}

func (f *fakeWG) run(_ context.Context, _ string, _ []string, name string, args ...string) ([]byte, error) {
	f.calls = append(f.calls, append([]string{name}, args...))
	if name == "wg" && len(args) == 2 && args[0] == "show" {
		if f.live {
			return []byte("interface: dmp0\n"), nil
		}
		return nil, errors.New("Unable to access interface")
	}
	if name == "wg" && len(args) == 3 && args[2] == "dump" {
		return []byte(f.dump), nil
	}
	if name == "wg-quick" && args[0] == "up" {
		f.live = true
	}
	return nil, nil
}

func TestMeshApplyKeysConfigAndDNS(t *testing.T) {
	dir := t.TempDir()
	g := &fakeWG{}
	e := &Executor{HMACKey: key, Journal: &Journal{Path: filepath.Join(dir, "j.json")}, Report: (&fakePanel{results: map[string][]protocol.Result{}}).report, StateDir: filepath.Join(dir, "state"), WireGuardDir: filepath.Join(dir, "wg"), Run: g.run, Now: func() time.Time { return now }}
	peer := "xTIBA5rboUvnH4htodjb6e697QjLERt1NAB4mZqp8Dg="
	payload := `{"address":"10.88.0.2/16","listen_port":51820,"peers":[{"public_key":"` + peer + `","endpoint":"203.0.113.5:51820","allowed_ips":["10.88.0.1/32"],"keepalive":25}],
		"dns":{"zone":"internal","records":[{"name":"web-1.internal","ip":"10.88.0.1"},{"name":"shop.internal","ip":"10.88.0.2"}]}}`
	r, err := e.Handle(context.Background(), cmd("m1", "mesh.apply", payload, now))
	if err != nil {
		t.Fatal(err, r.Message)
	}
	out := r.Output.(map[string]any)
	pub := out["public_key"].(string)
	if b, err := base64.StdEncoding.DecodeString(pub); err != nil || len(b) != 32 || !reWGKey.MatchString(pub) {
		t.Fatalf("public key %q", pub)
	}
	conf, _ := os.ReadFile(filepath.Join(dir, "wg", "dmp0.conf"))
	for _, want := range []string{"Address = 10.88.0.2/16", "ListenPort = 51820", "PublicKey = " + peer, "Endpoint = 203.0.113.5:51820", "AllowedIPs = 10.88.0.1/32", "PersistentKeepalive = 25"} {
		if !strings.Contains(string(conf), want) {
			t.Errorf("config lacks %q:\n%s", want, conf)
		}
	}
	if strings.Contains(string(conf), pub) {
		t.Error("own public key in config")
	}
	if st, _ := os.Stat(filepath.Join(dir, "wg", "dmp0.conf")); st.Mode().Perm() != 0o600 {
		t.Errorf("mode %v", st.Mode())
	}
	if strings.Join(g.calls[len(g.calls)-1], " ") != "wg-quick up dmp0" {
		t.Fatalf("calls %v", g.calls)
	}
	hosts, _ := os.ReadFile(filepath.Join(dir, "state", "coredns", "hosts"))
	if string(hosts) != "10.88.0.2 shop.internal\n10.88.0.1 web-1.internal\n" {
		t.Fatalf("hosts %q", hosts)
	}
	corefile, _ := os.ReadFile(filepath.Join(dir, "state", "coredns", "Corefile"))
	if !strings.HasPrefix(string(corefile), "internal:53 {") {
		t.Fatalf("Corefile %s", corefile)
	}

	// Second apply: same key, peers swapped live with syncconf.
	r, _ = e.Handle(context.Background(), cmd("m2", "mesh.apply", `{"address":"10.88.0.2/16","peers":[]}`, now))
	if r.Output.(map[string]any)["public_key"] != pub {
		t.Fatal("key changed between applies")
	}
	last := strings.Join(g.calls[len(g.calls)-1], " ")
	if !strings.HasPrefix(last, "wg syncconf dmp0 ") {
		t.Fatalf("expected syncconf, got %s", last)
	}
	sync, _ := os.ReadFile(strings.Fields(last)[3])
	if strings.Contains(string(sync), "Address") {
		t.Fatal("syncconf file must not carry wg-quick keys")
	}

	for name, p := range map[string]string{
		"bad cidr":   `{"address":"10.88.0.2","peers":[]}`,
		"bad key":    `{"address":"10.88.0.2/16","peers":[{"public_key":"nope","allowed_ips":["10.88.0.1/32"]}]}`,
		"injection":  `{"address":"10.88.0.2/16","peers":[{"public_key":"` + peer + `","endpoint":"1.2.3.4:5\nPostUp = rm -rf /","allowed_ips":["10.88.0.1/32"]}]}`,
		"dns record": `{"address":"10.88.0.2/16","peers":[],"dns":{"records":[{"name":"x y","ip":"10.88.0.1"}]}}`,
	} {
		if _, err := e.Handle(context.Background(), cmd("bad-"+name, "mesh.apply", p, now)); err == nil {
			t.Errorf("%s accepted", name)
		}
	}

	g.dump = "priv\tpub\t51820\toff\n" + peer + "\t(none)\t203.0.113.5:51820\t10.88.0.1/32\t1793361600\t1200\t3400\t25\n"
	r, _ = e.Handle(context.Background(), cmd("s1", "mesh.status", `{}`, now))
	ps := r.Output.(map[string]any)["peers"].([]map[string]any)
	if len(ps) != 1 || ps[0]["public_key"] != peer || ps[0]["rx_bytes"] != int64(1200) || ps[0]["last_handshake"] != "2026-10-30T12:00:00Z" {
		t.Fatalf("status %+v", ps)
	}

	r, _ = e.Handle(context.Background(), cmd("d1", "mesh.apply", `{"down":true}`, now))
	if _, err := os.Stat(filepath.Join(dir, "wg", "dmp0.conf")); !os.IsNotExist(err) {
		t.Fatal("config left after down")
	}
}
