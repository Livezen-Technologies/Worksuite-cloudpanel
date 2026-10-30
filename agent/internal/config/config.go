// Package config loads and stores the agent's enrolment configuration.
package config

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
)

// DefaultPath is where enrolment data is stored unless overridden by
// --config or DOCKERMGR_AGENT_CONFIG.
const DefaultPath = "/etc/dockermgr/agent.json"

// Config is persisted at mode 0600.
type Config struct {
	PanelURL          string `json:"panel_url"`
	ServerID          string `json:"server_id"`
	AgentSecret       string `json:"agent_secret"`
	HMACKey           string `json:"hmac_key"`
	HeartbeatInterval int    `json:"heartbeat_interval"`
	Certificate       string `json:"certificate,omitempty"`
	CAFile            string `json:"ca_file,omitempty"`
	StateDir          string `json:"state_dir,omitempty"`
	PolicyPath        string `json:"policy_path,omitempty"`
	DockerSocket      string `json:"docker_socket,omitempty"`
	// UpdatePublicKey (base64 ed25519) overrides the compiled-in release key.
	UpdatePublicKey string `json:"update_public_key,omitempty"`
	// CertFile/KeyFile hold the mTLS client certificate (SEC-003), written at
	// enrolment and on renewal; presented on every panel and relay connection.
	CertFile string `json:"cert_file,omitempty"`
	KeyFile  string `json:"key_file,omitempty"`
	// RelayURL overrides the terminal relay base the panel advertises.
	RelayURL string `json:"relay_url,omitempty"`
	// TerminalShells is the fixed list of shells terminal.open may start.
	TerminalShells []string `json:"terminal_shells,omitempty"`
	// ProxyConfigDir is Traefik's file-provider directory (proxy.config writes here);
	// AcmeJSON is Traefik's ACME store read by proxy.certs (names/expiry only).
	ProxyConfigDir string `json:"proxy_config_dir,omitempty"`
	AcmeJSON       string `json:"acme_json,omitempty"`
	// CrowdSecDir is where crowdsec.apply writes scenarios and profiles.yaml
	// (mounted into the CrowdSec container); CrowdSecContainer is its name.
	CrowdSecDir       string `json:"crowdsec_dir,omitempty"`
	CrowdSecContainer string `json:"crowdsec_container,omitempty"`
	// ClamAVContainer runs clamd for av.scan (docs/deploy/clamav).
	ClamAVContainer string `json:"clamav_container,omitempty"`
}

// ResolvePath picks flag value, then env, then default.
func ResolvePath(flagVal string) string {
	if flagVal != "" {
		return flagVal
	}
	if v := os.Getenv("DOCKERMGR_AGENT_CONFIG"); v != "" {
		return v
	}
	return DefaultPath
}

// ApplyDefaults fills unset fields.
func (c *Config) ApplyDefaults() {
	if c.HeartbeatInterval < 5 {
		c.HeartbeatInterval = 15
	}
	if c.StateDir == "" {
		c.StateDir = "/var/lib/dockermgr"
	}
	if c.PolicyPath == "" {
		c.PolicyPath = "/etc/dockermgr/policy.json"
	}
	if c.DockerSocket == "" {
		c.DockerSocket = "/var/run/docker.sock"
	}
}

// Load reads the config file.
func Load(path string) (*Config, error) {
	b, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	var c Config
	if err := json.Unmarshal(b, &c); err != nil {
		return nil, fmt.Errorf("parse %s: %w", path, err)
	}
	if c.PanelURL == "" || c.AgentSecret == "" || c.HMACKey == "" {
		return nil, errors.New("config incomplete: run 'agent enroll' first")
	}
	c.ApplyDefaults()
	return &c, nil
}

// Save writes atomically with mode 0600 (directory 0700 if created).
func Save(path string, c *Config) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return err
	}
	b, err := json.MarshalIndent(c, "", "  ")
	if err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(path), ".agent-*.json")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if err := tmp.Chmod(0o600); err != nil {
		tmp.Close()
		return err
	}
	if _, err := tmp.Write(b); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Sync(); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	return os.Rename(tmp.Name(), path)
}
