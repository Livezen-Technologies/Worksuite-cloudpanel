// Package policy implements the local command policy file. The policy can
// only narrow what the agent accepts; it can never add command types.
package policy

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"sync"
)

// Policy is the JSON document at /etc/dockermgr/policy.json, e.g.
//
//	{"disabled_types": ["backup.run", "agent.update"], "allow_privileged_ports": false}
type Policy struct {
	DisabledTypes []string `json:"disabled_types"`
	// MaintenanceMode refuses container.create and compose.up.
	MaintenanceMode bool `json:"maintenance_mode,omitempty"`
}

// Store reloads the policy file when its mtime changes.
type Store struct {
	Path string
	mu   sync.Mutex
	mod  int64
	cur  Policy
}

// Get returns the current policy. A missing file means the empty policy.
func (s *Store) Get() (Policy, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.Path == "" {
		return Policy{}, nil
	}
	st, err := os.Stat(s.Path)
	if errors.Is(err, os.ErrNotExist) {
		s.cur, s.mod = Policy{}, 0
		return s.cur, nil
	}
	if err != nil {
		return Policy{}, err
	}
	if st.ModTime().UnixNano() == s.mod {
		return s.cur, nil
	}
	b, err := os.ReadFile(s.Path)
	if err != nil {
		return Policy{}, err
	}
	var p Policy
	if err := json.Unmarshal(b, &p); err != nil {
		// Fail closed: an unreadable policy blocks everything.
		return Policy{}, fmt.Errorf("policy %s invalid: %w", s.Path, err)
	}
	s.cur, s.mod = p, st.ModTime().UnixNano()
	return p, nil
}

// Check returns an error if the command type is not allowed.
func (s *Store) Check(typ string) error {
	p, err := s.Get()
	if err != nil {
		return err
	}
	for _, d := range p.DisabledTypes {
		if d == typ || d == "*" {
			return fmt.Errorf("command type %s disabled by local policy", typ)
		}
	}
	if p.MaintenanceMode && (typ == "container.create" || typ == "compose.up") {
		return fmt.Errorf("server in maintenance mode: %s refused", typ)
	}
	return nil
}
