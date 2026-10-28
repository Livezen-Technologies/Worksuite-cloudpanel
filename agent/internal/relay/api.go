package relay

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// Session is what the API returns for a valid terminal token.
type Session struct {
	SessionID     string          `json:"session_id"`
	ServerID      string          `json:"server_id"`
	ContainerID   string          `json:"container_id"`
	ContainerName string          `json:"container_name"`
	UserID        string          `json:"user_id"`
	UserEmail     string          `json:"user_email"`
	ShellUser     string          `json:"shell_user"`
	Shell         string          `json:"shell"`
	ExpiresAt     time.Time       `json:"expires_at"`
	Recording     bool            `json:"recording"`
	Command       json.RawMessage `json:"command"` // signed terminal.open for the agent
}

// Status is the live state of a session (polled for "end session").
type Status struct {
	Ended     bool      `json:"ended"`
	ExpiresAt time.Time `json:"expires_at"`
}

// API is the relay's view of the control plane.
type API interface {
	VerifyAgent(ctx context.Context, secret string, fwd http.Header) (serverID string, err error)
	Connect(ctx context.Context, sessionID, token string, cols, rows int, ip string) (*Session, error)
	Status(ctx context.Context, sessionID string) (*Status, error)
	Closed(ctx context.Context, sessionID, reason string) error
	UploadRecording(ctx context.Context, sessionID string, cast []byte) error
}

// APIError is a refused call; Code is the HTTP status.
type APIError struct {
	Code    int
	Message string
}

func (e *APIError) Error() string { return fmt.Sprintf("api %d: %s", e.Code, e.Message) }

// HTTPAPI calls the Laravel internal relay endpoints (/internal/relay/*)
// authenticated with the shared X-Relay-Secret.
type HTTPAPI struct {
	BaseURL string
	Secret  string
	HTTP    *http.Client
	// ForwardHeaders are copied from the agent's request to VerifyAgent so the
	// API can apply AGENT_MTLS to relay connections too.
	ForwardHeaders []string
}

func (a *HTTPAPI) do(ctx context.Context, method, path string, body io.Reader, ctype string, hdr http.Header, out any) error {
	req, err := http.NewRequestWithContext(ctx, method, strings.TrimRight(a.BaseURL, "/")+path, body)
	if err != nil {
		return err
	}
	for k, vs := range hdr {
		for _, v := range vs {
			req.Header.Add(k, v)
		}
	}
	req.Header.Set("X-Relay-Secret", a.Secret)
	req.Header.Set("Accept", "application/json")
	if ctype != "" {
		req.Header.Set("Content-Type", ctype)
	}
	hc := a.HTTP
	if hc == nil {
		hc = &http.Client{Timeout: 30 * time.Second}
	}
	resp, err := hc.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	b, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if resp.StatusCode > 299 {
		var e struct {
			Error struct {
				Message string `json:"message"`
			} `json:"error"`
		}
		msg := strings.TrimSpace(string(b))
		if json.Unmarshal(b, &e) == nil && e.Error.Message != "" {
			msg = e.Error.Message
		}
		return &APIError{Code: resp.StatusCode, Message: msg}
	}
	if out == nil {
		return nil
	}
	var env struct {
		Data json.RawMessage `json:"data"`
	}
	if json.Unmarshal(b, &env) == nil && len(env.Data) > 0 {
		b = env.Data
	}
	return json.Unmarshal(b, out)
}

func jsonBody(v any) io.Reader { b, _ := json.Marshal(v); return bytes.NewReader(b) }

func (a *HTTPAPI) VerifyAgent(ctx context.Context, secret string, fwd http.Header) (string, error) {
	hdr := http.Header{}
	for _, h := range a.ForwardHeaders {
		if v := fwd.Get(h); v != "" {
			hdr.Set(h, v)
		}
	}
	var out struct {
		ServerID string `json:"server_id"`
	}
	if err := a.do(ctx, "POST", "/internal/relay/agents/verify", jsonBody(map[string]string{"agent_secret": secret}), "application/json", hdr, &out); err != nil {
		return "", err
	}
	if out.ServerID == "" {
		return "", errors.New("verify returned no server_id")
	}
	return out.ServerID, nil
}

func (a *HTTPAPI) Connect(ctx context.Context, id, token string, cols, rows int, ip string) (*Session, error) {
	var s Session
	err := a.do(ctx, "POST", "/internal/relay/terminal-sessions/"+url.PathEscape(id)+"/connect",
		jsonBody(map[string]any{"token": token, "cols": cols, "rows": rows, "ip": ip}), "application/json", nil, &s)
	if err != nil {
		return nil, err
	}
	return &s, nil
}

func (a *HTTPAPI) Status(ctx context.Context, id string) (*Status, error) {
	var s Status
	if err := a.do(ctx, "GET", "/internal/relay/terminal-sessions/"+url.PathEscape(id), nil, "", nil, &s); err != nil {
		return nil, err
	}
	return &s, nil
}

func (a *HTTPAPI) Closed(ctx context.Context, id, reason string) error {
	return a.do(ctx, "POST", "/internal/relay/terminal-sessions/"+url.PathEscape(id)+"/closed", jsonBody(map[string]string{"reason": reason}), "application/json", nil, nil)
}

func (a *HTTPAPI) UploadRecording(ctx context.Context, id string, cast []byte) error {
	return a.do(ctx, "POST", "/internal/relay/terminal-sessions/"+url.PathEscape(id)+"/recording", bytes.NewReader(cast), "application/x-asciicast", nil, nil)
}
