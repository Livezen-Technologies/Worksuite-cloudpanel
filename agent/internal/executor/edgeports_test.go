package executor

import (
	"context"
	"encoding/json"
	"os"
	"strings"
	"testing"
)

func TestRenderEdgePortsAllowsOnlyListedSourcesPerPort(t *testing.T) {
	doc, err := renderEdgePorts(edgePortsPayload{Rules: []edgePortRule{
		{Port: 51200, Allow: []string{"203.0.113.2", "198.51.100.7", "2001:db8::1"}},
		{Port: 51100, Allow: nil},
	}})
	if err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{
		"table inet dockermgr_edge",
		"hook prerouting priority -150",
		"ct state established,related accept",
		"tcp dport 51200 ip saddr { 198.51.100.7/32, 203.0.113.2/32 } accept",
		"tcp dport 51200 ip6 saddr { 2001:db8::1/128 } accept",
		"tcp dport 51200 counter drop",
		"tcp dport 51100 counter drop",
	} {
		if !strings.Contains(doc, want) {
			t.Errorf("missing %q\n%s", want, doc)
		}
	}
	if strings.Contains(doc, "tcp dport 51100 ip saddr") {
		t.Error("a port with no allowed source must only drop")
	}
	if strings.Index(doc, "51200 ip saddr") > strings.Index(doc, "51200 counter drop") {
		t.Error("accepts must come before the drop")
	}
	if strings.Index(doc, "51100") > strings.Index(doc, "51200") {
		t.Error("rules are sorted by port for a stable document")
	}
	for _, foreign := range []string{"DOCKER-USER", "ufw", "table inet dockermgr {"} {
		if strings.Contains(doc, foreign) {
			t.Errorf("must not touch %q", foreign)
		}
	}
}

func TestRenderEdgePortsRejectsBadInput(t *testing.T) {
	for _, p := range []edgePortsPayload{
		{Rules: []edgePortRule{{Port: 0}}},
		{Rules: []edgePortRule{{Port: 70000}}},
		{Rules: []edgePortRule{{Port: 51000}, {Port: 51000}}},
		{Rules: []edgePortRule{{Port: 51000, Allow: []string{"not-an-ip"}}}},
	} {
		if _, err := renderEdgePorts(p); err == nil {
			t.Errorf("expected an error for %+v", p)
		}
	}
}

func TestApplyEdgePortsChecksThenLoadsAndEmptyRemovesTable(t *testing.T) {
	var calls []string
	e := &Executor{StateDir: t.TempDir(), LookPath: func(string) (string, error) { return "/usr/sbin/nft", nil },
		Run: func(_ context.Context, _ string, _ []string, name string, args ...string) ([]byte, error) {
			calls = append(calls, name+" "+strings.Join(args, " "))
			return nil, nil
		}}
	if _, err := e.applyEdgePorts(context.Background(), json.RawMessage(`{"rules":[{"port":51000,"allow":["203.0.113.2"]}]}`)); err != nil {
		t.Fatal(err)
	}
	if len(calls) != 2 || !strings.HasPrefix(calls[0], "nft --check -f ") || !strings.HasPrefix(calls[1], "nft -f ") {
		t.Fatalf("calls: %v", calls)
	}
	calls = nil
	if _, err := e.applyEdgePorts(context.Background(), json.RawMessage(`{"rules":[]}`)); err != nil {
		t.Fatal(err)
	}
	if len(calls) != 1 || calls[0] != "nft delete table inet dockermgr_edge" {
		t.Fatalf("an empty list must delete the table: %v", calls)
	}
}

func TestApplyEdgePortsRefusesWithoutNft(t *testing.T) {
	e := &Executor{StateDir: t.TempDir(), LookPath: func(string) (string, error) { return "", os.ErrNotExist }}
	if _, err := e.applyEdgePorts(context.Background(), json.RawMessage(`{"rules":[{"port":51000,"allow":["203.0.113.2"]}]}`)); err == nil {
		t.Fatal("must refuse rather than leave a published port unfiltered")
	}
}
