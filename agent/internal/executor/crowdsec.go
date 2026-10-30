package executor

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

// CrowdSec (FW-001 enforcement). The panel renders its firewall rules into
// CrowdSec scenarios and decision profiles; the agent writes them next to the
// CrowdSec container's config, checks the result with `crowdsec -t`, and
// reloads CrowdSec. The Traefik bouncer plugin then turns CrowdSec's decisions
// into a block page or a captcha. Alerts are read back with cscli so the panel
// can fill the firewall event log.

const (
	DefaultCrowdSecDir       = "/etc/dockermgr/crowdsec"
	DefaultCrowdSecContainer = "crowdsec"
)

var (
	reScenarioFile = regexp.MustCompile(`^dmp-[a-z0-9-]{1,80}\.yaml$`)
	reSince        = regexp.MustCompile(`^[0-9]{1,4}[smh]$`)
)

func (e *Executor) crowdsecDir() string { return firstNonEmpty(e.CrowdSecDir, DefaultCrowdSecDir) }
func (e *Executor) crowdsecContainer() string {
	return firstNonEmpty(e.CrowdSecContainer, DefaultCrowdSecContainer)
}

func (e *Executor) cscli(ctx context.Context, args ...string) ([]byte, error) {
	if !reRef.MatchString(e.crowdsecContainer()) {
		return nil, fmt.Errorf("invalid CrowdSec container name")
	}
	return e.Run(ctx, "", nil, "docker", append([]string{"exec", e.crowdsecContainer()}, args...)...)
}

type crowdsecApply struct {
	Scenarios map[string]string `json:"scenarios"`
	Profiles  string            `json:"profiles"`
}

// crowdsecApply replaces the panel's scenarios (dmp-*.yaml) and profiles.yaml,
// keeping the previous files until CrowdSec accepts the new ones.
func (e *Executor) crowdsecApply(ctx context.Context, raw json.RawMessage) (any, error) {
	var p crowdsecApply
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if strings.TrimSpace(p.Profiles) == "" {
		return nil, errors.New("profiles are required: an empty profiles.yaml would stop every decision")
	}
	for name := range p.Scenarios {
		if !reScenarioFile.MatchString(name) {
			return nil, fmt.Errorf("invalid scenario file name %q", name)
		}
	}
	dir := e.crowdsecDir()
	scenDir := filepath.Join(dir, "scenarios")
	if err := os.MkdirAll(scenDir, 0o755); err != nil {
		return nil, err
	}

	// Snapshot what is there, so a rejected config can be put back exactly.
	backup := map[string][]byte{}
	olds, _ := filepath.Glob(filepath.Join(scenDir, "dmp-*.yaml"))
	for _, f := range olds {
		if b, err := os.ReadFile(f); err == nil {
			backup[f] = b
		}
	}
	profilesPath := filepath.Join(dir, "profiles.yaml")
	prevProfiles, prevErr := os.ReadFile(profilesPath)

	restore := func() {
		cur, _ := filepath.Glob(filepath.Join(scenDir, "dmp-*.yaml"))
		for _, f := range cur {
			if _, keep := backup[f]; !keep {
				os.Remove(f)
			}
		}
		for f, b := range backup {
			os.WriteFile(f, b, 0o644)
		}
		if prevErr == nil {
			os.WriteFile(profilesPath, prevProfiles, 0o644)
		} else {
			os.Remove(profilesPath)
		}
	}

	for _, f := range olds {
		if _, keep := p.Scenarios[filepath.Base(f)]; !keep {
			os.Remove(f)
		}
	}
	for name, body := range p.Scenarios {
		if err := writeAtomic(filepath.Join(scenDir, name), []byte(body), 0o644); err != nil {
			restore()
			return nil, err
		}
	}
	if err := writeAtomic(profilesPath, []byte(p.Profiles), 0o644); err != nil {
		restore()
		return nil, err
	}
	if out, err := e.cscli(ctx, "crowdsec", "-t"); err != nil {
		restore()
		return nil, fmt.Errorf("CrowdSec rejected the configuration, the previous one is kept: %s", tailString(strings.TrimSpace(string(out)), 600))
	}
	if out, err := e.Run(ctx, "", nil, "docker", "kill", "--signal", "SIGHUP", e.crowdsecContainer()); err != nil {
		return nil, fmt.Errorf("reload CrowdSec: %s", tailString(strings.TrimSpace(string(out)), 300))
	}
	return map[string]any{"scenarios": len(p.Scenarios), "reloaded": true}, nil
}

// CrowdSecAlert is the part of a cscli alert the panel records as a firewall event.
type CrowdSecAlert struct {
	ID       int64  `json:"id"`
	Scenario string `json:"scenario"`
	IP       string `json:"ip"`
	Country  string `json:"country,omitempty"`
	At       string `json:"at"`
	Decision string `json:"decision,omitempty"` // ban|captcha
	Host     string `json:"host,omitempty"`
	Method   string `json:"method,omitempty"`
	Path     string `json:"path,omitempty"`
}

type cscliAlert struct {
	ID        int64  `json:"id"`
	Scenario  string `json:"scenario"`
	CreatedAt string `json:"created_at"`
	Source    struct {
		IP    string `json:"ip"`
		Value string `json:"value"`
		CN    string `json:"cn"`
	} `json:"source"`
	Decisions []struct {
		Type string `json:"type"`
	} `json:"decisions"`
	Events []struct {
		Meta []struct {
			Key   string `json:"key"`
			Value string `json:"value"`
		} `json:"meta"`
	} `json:"events"`
}

func (e *Executor) crowdsecAlerts(ctx context.Context, raw json.RawMessage) (any, error) {
	var p struct {
		Since string `json:"since"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	since := firstNonEmpty(p.Since, "15m")
	if !reSince.MatchString(since) {
		return nil, fmt.Errorf("invalid since %q", since)
	}
	out, err := e.cscli(ctx, "cscli", "alerts", "list", "-o", "json", "--since", since, "--limit", "1000")
	if err != nil {
		return nil, fmt.Errorf("cscli alerts: %s", tailString(strings.TrimSpace(string(out)), 300))
	}
	var alerts []cscliAlert
	if s := strings.TrimSpace(string(out)); s != "" && s != "null" {
		if err := json.Unmarshal([]byte(s), &alerts); err != nil {
			return nil, fmt.Errorf("cscli alerts: unexpected output: %w", err)
		}
	}
	res := make([]CrowdSecAlert, 0, len(alerts))
	for _, a := range alerts {
		al := CrowdSecAlert{ID: a.ID, Scenario: a.Scenario, IP: firstNonEmpty(a.Source.IP, a.Source.Value), Country: a.Source.CN, At: a.CreatedAt}
		if len(a.Decisions) > 0 {
			al.Decision = a.Decisions[0].Type
		}
		if len(a.Events) > 0 {
			for _, m := range a.Events[len(a.Events)-1].Meta {
				switch m.Key {
				case "target_fqdn":
					al.Host = m.Value
				case "http_verb":
					al.Method = m.Value
				case "http_path":
					al.Path = m.Value
				}
			}
		}
		res = append(res, al)
	}
	return map[string]any{"alerts": res}, nil
}
