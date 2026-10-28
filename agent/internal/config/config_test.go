package config

import (
	"os"
	"path/filepath"
	"testing"
)

func TestSaveLoad(t *testing.T) {
	p := filepath.Join(t.TempDir(), "sub", "agent.json")
	in := &Config{PanelURL: "https://p", ServerID: "s", AgentSecret: "a", HMACKey: "h"}
	if err := Save(p, in); err != nil {
		t.Fatal(err)
	}
	st, _ := os.Stat(p)
	if st.Mode().Perm() != 0o600 {
		t.Fatalf("mode %v", st.Mode())
	}
	out, err := Load(p)
	if err != nil {
		t.Fatal(err)
	}
	if out.ServerID != "s" || out.HeartbeatInterval != 15 {
		t.Fatalf("%+v", out)
	}
	t.Setenv("DOCKERMGR_AGENT_CONFIG", "/x")
	if ResolvePath("") != "/x" || ResolvePath("/y") != "/y" {
		t.Fatal("resolve")
	}
}
