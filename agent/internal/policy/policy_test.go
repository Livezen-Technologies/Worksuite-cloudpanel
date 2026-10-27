package policy

import (
	"os"
	"path/filepath"
	"testing"
)

func TestPolicy(t *testing.T) {
	p := filepath.Join(t.TempDir(), "policy.json")
	s := &Store{Path: p}
	if err := s.Check("container.start"); err != nil {
		t.Fatal("missing file should allow", err)
	}
	os.WriteFile(p, []byte(`{"disabled_types":["backup.run"],"maintenance_mode":true}`), 0o600)
	if s.Check("backup.run") == nil || s.Check("compose.up") == nil {
		t.Fatal("should deny")
	}
	if s.Check("container.stop") != nil {
		t.Fatal("should allow")
	}
	os.WriteFile(p, []byte(`{bad`), 0o600)
	os.Chtimes(p, timeNowPlus(), timeNowPlus())
	if s.Check("container.stop") == nil {
		t.Fatal("invalid policy should fail closed")
	}
}
