// Package protocol is the HTTP client for the panel's /agent/v1 endpoints.
package protocol

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"strings"
	"time"
)

// Facts describe the host at enrolment.
type Facts struct {
	OS            string `json:"os"`
	Kernel        string `json:"kernel"`
	DockerVersion string `json:"docker_version"`
	CPUCores      int    `json:"cpu_cores"`
	RAMMB         int64  `json:"ram_mb"`
	DiskGB        int64  `json:"disk_gb"`
	Arch          string `json:"arch"`
}

type EnrollRequest struct {
	Token    string `json:"token"`
	Hostname string `json:"hostname"`
	Facts    Facts  `json:"facts"`
	CSR      string `json:"csr,omitempty"`
}

type EnrollResponse struct {
	ServerID          string `json:"server_id"`
	AgentSecret       string `json:"agent_secret"`
	HMACKey           string `json:"hmac_key"`
	HeartbeatInterval int    `json:"heartbeat_interval"`
	Certificate       string `json:"certificate,omitempty"`
}

type Stats struct {
	CPUPct  float64 `json:"cpu_pct"`
	RAMPct  float64 `json:"ram_pct"`
	DiskPct float64 `json:"disk_pct"`
	NetRx   uint64  `json:"net_rx"`
	NetTx   uint64  `json:"net_tx"`
}

type Container struct {
	DockerID string   `json:"docker_id"`
	Name     string   `json:"name"`
	Image    string   `json:"image"`
	State    string   `json:"state"`
	Health   string   `json:"health"`
	CPUPct   float64  `json:"cpu_pct"`
	MemMB    float64  `json:"mem_mb"`
	Ports    []string `json:"ports"`
	Created  string   `json:"created"`
}

type Image struct {
	ID      string   `json:"id"`
	Tags    []string `json:"tags"`
	SizeMB  float64  `json:"size_mb"`
	Created string   `json:"created"`
}

type Network struct {
	ID       string `json:"id"`
	Name     string `json:"name"`
	Driver   string `json:"driver"`
	Scope    string `json:"scope"`
	Internal bool   `json:"internal"`
	Subnet   string `json:"subnet,omitempty"`
	Gateway  string `json:"gateway,omitempty"`
}

type Volume struct {
	Name       string `json:"name"`
	Driver     string `json:"driver"`
	Mountpoint string `json:"mountpoint"`
}

type HeartbeatRequest struct {
	AgentVersion string      `json:"agent_version"`
	Stats        Stats       `json:"stats"`
	Containers   []Container `json:"containers"`
	Images       []Image     `json:"images"`
	Networks     []Network   `json:"networks"`
	Volumes      []Volume    `json:"volumes"`
	// DockerError is set when the inventory could not be collected; the
	// inventory lists are then null (unknown), not empty.
	DockerError string `json:"docker_error,omitempty"`
}

// Command as delivered by the panel. Payload is kept raw so the signature is
// computed over exactly what was received.
type Command struct {
	ID        string          `json:"id"`
	Type      string          `json:"type"`
	Version   int             `json:"version"`
	Payload   json.RawMessage `json:"payload"`
	IssuedAt  string          `json:"issued_at"`
	Signature string          `json:"signature"`
}

type HeartbeatResponse struct {
	Commands []Command `json:"commands"`
	// RelayURL is the terminal relay base (wss://panel/relay); the agent keeps
	// an outbound control connection there for low-latency terminal.open.
	RelayURL string `json:"relay_url,omitempty"`
}

// Result reported to /agent/v1/commands/{id}/result.
type Result struct {
	Status   string `json:"status"` // running|succeeded|failed
	Progress *int   `json:"progress,omitempty"`
	Message  string `json:"message,omitempty"`
	Output   any    `json:"output,omitempty"`
}

// Client talks to the panel.
type Client struct {
	BaseURL string
	Secret  string
	HTTP    *http.Client
}

// NewHTTPClient builds an HTTPS client, optionally trusting an extra CA file.
func NewHTTPClient(caFile string) (*http.Client, error) {
	tr := http.DefaultTransport.(*http.Transport).Clone()
	tr.TLSClientConfig = &tls.Config{MinVersion: tls.VersionTLS12}
	if caFile != "" {
		pem, err := os.ReadFile(caFile)
		if err != nil {
			return nil, err
		}
		pool, err := x509.SystemCertPool()
		if err != nil || pool == nil {
			pool = x509.NewCertPool()
		}
		if !pool.AppendCertsFromPEM(pem) {
			return nil, fmt.Errorf("no certificates in %s", caFile)
		}
		tr.TLSClientConfig.RootCAs = pool
	}
	return &http.Client{Transport: tr, Timeout: 60 * time.Second}, nil
}

// NewHTTPClientTLS builds a client from a prepared TLS config (mTLS).
func NewHTTPClientTLS(cfg *tls.Config) *http.Client {
	tr := http.DefaultTransport.(*http.Transport).Clone()
	tr.TLSClientConfig = cfg
	return &http.Client{Transport: tr, Timeout: 60 * time.Second}
}

// StatusError is returned for non-2xx responses.
type StatusError struct {
	Code int
	Body string
}

func (e *StatusError) Error() string { return fmt.Sprintf("panel returned %d: %s", e.Code, e.Body) }

func (c *Client) post(ctx context.Context, path string, in, out any, auth bool) error {
	b, err := json.Marshal(in)
	if err != nil {
		return err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, strings.TrimRight(c.BaseURL, "/")+path, bytes.NewReader(b))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	if auth {
		req.Header.Set("Authorization", "Bearer "+c.Secret)
	}
	hc := c.HTTP
	if hc == nil {
		hc = http.DefaultClient
	}
	resp, err := hc.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(io.LimitReader(resp.Body, 16<<20))
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		s := string(body)
		if len(s) > 512 {
			s = s[:512]
		}
		return &StatusError{Code: resp.StatusCode, Body: s}
	}
	if out != nil && len(bytes.TrimSpace(body)) > 0 {
		// Accept both bare objects and {"data": {...}} envelopes.
		var env struct {
			Data json.RawMessage `json:"data"`
		}
		if json.Unmarshal(body, &env) == nil && len(env.Data) > 0 && env.Data[0] == '{' {
			body = env.Data
		}
		if err := json.Unmarshal(body, out); err != nil {
			return fmt.Errorf("decode %s response: %w", path, err)
		}
	}
	return nil
}

func (c *Client) Enroll(ctx context.Context, r EnrollRequest) (*EnrollResponse, error) {
	var out EnrollResponse
	if err := c.post(ctx, "/agent/v1/enroll", r, &out, false); err != nil {
		return nil, err
	}
	if out.AgentSecret == "" || out.HMACKey == "" || out.ServerID == "" {
		return nil, fmt.Errorf("enroll response missing server_id/agent_secret/hmac_key")
	}
	return &out, nil
}

func (c *Client) Heartbeat(ctx context.Context, r HeartbeatRequest) (*HeartbeatResponse, error) {
	var out HeartbeatResponse
	if err := c.post(ctx, "/agent/v1/heartbeat", r, &out, true); err != nil {
		return nil, err
	}
	return &out, nil
}

func (c *Client) ReportResult(ctx context.Context, id string, r Result) error {
	return c.post(ctx, "/agent/v1/commands/"+url.PathEscape(id)+"/result", r, nil, true)
}

// RenewResponse from /agent/v1/certificate/renew.
type RenewResponse struct {
	Certificate string `json:"certificate"`
	ExpiresAt   string `json:"expires_at"`
}

// RenewCertificate sends a fresh CSR and returns the signed certificate.
func (c *Client) RenewCertificate(ctx context.Context, csr string) (*RenewResponse, error) {
	var out RenewResponse
	if err := c.post(ctx, "/agent/v1/certificate/renew", map[string]string{"csr": csr}, &out, true); err != nil {
		return nil, err
	}
	if out.Certificate == "" {
		return nil, fmt.Errorf("renew response has no certificate")
	}
	return &out, nil
}
