package executor

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"slices"
	"strings"
	"sync/atomic"
	"syscall"
	"time"

	"github.com/livezen-technologies/cloudpanel/agent/internal/docker"
	"github.com/livezen-technologies/cloudpanel/agent/internal/ws"
)

// DefaultShells is the fixed list of shells a terminal may run.
var DefaultShells = []string{"/bin/sh", "/bin/bash", "/bin/ash"}

// TerminalConfig enables terminal.open. The agent dials back to the relay
// (outbound only) and attaches a Docker exec of a fixed shell, as a non-root
// user, in the container named by the signed command. Nothing else can run.
type TerminalConfig struct {
	RelayURL    string // default relay base (wss://panel/relay) when the payload has none
	Secret      string // agent secret, sent as Bearer to the relay
	TLS         func() *tls.Config
	Shells      []string
	MaxSessions int32
	active      atomic.Int32
}

type terminalPayload struct {
	SessionID   string `json:"session_id"`
	ContainerID string `json:"container_id"`
	ShellUser   string `json:"shell_user"`
	Shell       string `json:"shell"`
	ExpiresAt   string `json:"expires_at"`
	RelayURL    string `json:"relay_url"`
	Cols        int    `json:"cols"`
	Rows        int    `json:"rows"`
}

// IsRootUser reports user specs that would run as root.
func IsRootUser(u string) bool {
	u = strings.TrimSpace(u)
	name, _, _ := strings.Cut(u, ":")
	return name == "" || name == "root" || name == "0"
}

func (p *terminalPayload) validate(shells []string, now time.Time) (time.Time, error) {
	if err := checkRef("session_id", p.SessionID); err != nil {
		return time.Time{}, err
	}
	if err := checkRef("container_id", p.ContainerID); err != nil {
		return time.Time{}, err
	}
	if IsRootUser(p.ShellUser) {
		return time.Time{}, errors.New("terminal sessions never run as root")
	}
	if strings.ContainsAny(p.ShellUser, " \t\r\n/") || len(p.ShellUser) > 64 {
		return time.Time{}, errors.New("invalid shell_user")
	}
	if len(shells) == 0 {
		shells = DefaultShells
	}
	if !slices.Contains(shells, p.Shell) {
		return time.Time{}, fmt.Errorf("shell %q is not in the allowed list", p.Shell)
	}
	exp, err := time.Parse(time.RFC3339, p.ExpiresAt)
	if err != nil {
		return time.Time{}, fmt.Errorf("bad expires_at: %w", err)
	}
	if !exp.After(now) {
		return time.Time{}, errors.New("terminal session already expired")
	}
	if exp.Sub(now) > 4*time.Hour {
		return time.Time{}, errors.New("terminal session lifetime too long")
	}
	return exp, nil
}

func relaySessionURL(base, session string) (string, error) {
	u, err := url.Parse(strings.TrimRight(base, "/"))
	if err != nil || u.Host == "" {
		return "", errors.New("invalid relay_url")
	}
	switch u.Scheme {
	case "ws", "wss":
	case "https":
		u.Scheme = "wss"
	case "http":
		u.Scheme = "ws"
	default:
		return "", errors.New("relay_url must be ws(s) or http(s)")
	}
	u.Path += "/agent/session/" + url.PathEscape(session)
	return u.String(), nil
}

func (e *Executor) openTerminal(ctx context.Context, raw json.RawMessage) (any, error) {
	t := e.Terminal
	if t == nil {
		return nil, errors.New("terminal sessions are disabled on this agent")
	}
	var p terminalPayload
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	exp, err := p.validate(t.Shells, e.now())
	if err != nil {
		return nil, err
	}
	base := p.RelayURL
	if base == "" {
		base = t.RelayURL
	}
	target, err := relaySessionURL(base, p.SessionID)
	if err != nil {
		return nil, err
	}
	max := t.MaxSessions
	if max <= 0 {
		max = 8
	}
	if t.active.Add(1) > max {
		t.active.Add(-1)
		return nil, errors.New("too many open terminal sessions on this agent")
	}
	go func() {
		defer t.active.Add(-1)
		if err := e.runTerminal(target, exp, p); err != nil {
			e.logf("terminal %s: %v", p.SessionID, err)
		}
	}()
	return map[string]any{"session_id": p.SessionID, "status": "attaching"}, nil
}

type termControl struct {
	Type string `json:"type"`
	Cols int    `json:"cols"`
	Rows int    `json:"rows"`
}

// runTerminal bridges relay <-> docker exec until exit, close or expiry.
func (e *Executor) runTerminal(target string, exp time.Time, p terminalPayload) error {
	ctx, cancel := context.WithDeadline(context.Background(), exp)
	defer cancel()
	dctx, dcancel := context.WithTimeout(ctx, 30*time.Second)
	var tlsCfg *tls.Config
	if e.Terminal.TLS != nil {
		tlsCfg = e.Terminal.TLS()
	}
	conn, err := ws.Dial(dctx, target, &ws.DialOptions{Header: http.Header{"Authorization": {"Bearer " + e.Terminal.Secret}}, TLSConfig: tlsCfg})
	dcancel()
	if err != nil {
		return fmt.Errorf("dial relay: %w", err)
	}
	defer conn.Close(ws.CloseNormal, "")
	go func() { <-ctx.Done(); conn.Close(ws.CloseNormal, "expired") }()

	sendJSON := func(v any) { b, _ := json.Marshal(v); conn.WriteText(string(b)) }
	user, changed := e.terminalUser(ctx, p.ContainerID, p.ShellUser, p.Shell)
	if changed {
		sendJSON(map[string]any{"type": "status", "message": fmt.Sprintf("This container has no user %q; using %q (never root)", p.ShellUser, user)})
	}
	cfg := docker.ExecConfig{
		Cmd: []string{p.Shell}, User: user, Env: []string{"TERM=xterm-256color"},
		Tty: true, AttachStdin: true, AttachStdout: true, AttachStderr: true,
	}
	if p.Rows > 0 && p.Cols > 0 {
		cfg.ConsoleSize = []int{p.Rows, p.Cols}
	}
	id, err := e.Docker.ExecCreate(ctx, p.ContainerID, cfg)
	if err != nil {
		sendJSON(map[string]any{"type": "error", "message": err.Error()})
		return err
	}
	stream, err := e.Docker.ExecAttach(ctx, id, true)
	if err != nil {
		sendJSON(map[string]any{"type": "error", "message": err.Error()})
		return err
	}
	defer stream.Close()
	sendJSON(map[string]any{"type": "ready", "exec_id": id, "message": user})
	e.logf("terminal %s attached to %s as %s (%s)", p.SessionID, p.ContainerID, user, p.Shell)

	outDone := make(chan struct{})
	go func() {
		defer close(outDone)
		buf := make([]byte, 32<<10)
		for {
			n, err := stream.Read(buf)
			if n > 0 {
				if conn.WriteMessage(ws.OpBinary, buf[:n]) != nil {
					return
				}
			}
			if err != nil {
				return
			}
		}
	}()
	inDone := make(chan struct{})
	go func() {
		defer close(inDone)
		for {
			op, data, err := conn.ReadMessage()
			if err != nil {
				return
			}
			if op == ws.OpBinary {
				if _, err := stream.Write(data); err != nil {
					return
				}
				continue
			}
			var c termControl
			if json.Unmarshal(data, &c) != nil {
				continue
			}
			switch c.Type {
			case "resize":
				if c.Cols > 0 && c.Rows > 0 && c.Cols <= 1000 && c.Rows <= 1000 {
					e.Docker.ExecResize(ctx, id, c.Rows, c.Cols)
				}
			case "close":
				return
			}
		}
	}()
	select {
	case <-outDone:
	case <-inDone:
	case <-ctx.Done():
	}
	// Ctrl-C then Ctrl-D: interrupt a foreground program and end the shell at its prompt.
	stream.Write([]byte{0x03, 0x04})
	stream.Close()
	if st := e.reapExec(id); !st.Running {
		sendJSON(map[string]any{"type": "exit", "code": st.ExitCode})
	}
	return nil
}

// reapExec makes sure the exec'd shell does not outlive the session: Docker keeps an exec
// running after its attach stream closes, so the agent signals the exec's own host PID
// (SIGHUP, then SIGKILL after a grace period).
func (e *Executor) reapExec(id string) docker.ExecState {
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	inspect := func() (docker.ExecState, bool) {
		st, err := e.Docker.ExecInspect(ctx, id)
		return st, err == nil
	}
	st, ok := inspect()
	for i := 0; ok && st.Running && i < 10; i++ { // up to 1 s for a normal exit
		time.Sleep(100 * time.Millisecond)
		st, ok = inspect()
	}
	for _, sig := range []syscall.Signal{syscall.SIGHUP, syscall.SIGKILL} {
		if !ok || !st.Running || st.Pid <= 1 {
			return st
		}
		e.logf("terminal exec %s still running after the session ended; sending %s to pid %d", id, sig, st.Pid)
		syscall.Kill(st.Pid, sig)
		for i := 0; i < 20; i++ {
			time.Sleep(100 * time.Millisecond)
			if st, ok = inspect(); !ok || !st.Running {
				break
			}
		}
	}
	return st
}

// terminalUser picks who the shell runs as. Many images (WordPress, databases) have no
// user named like the configured one, so the exec would fail; it falls back to the
// container's own non-root user, then common service users. Root is never chosen.
func (e *Executor) terminalUser(ctx context.Context, container, wanted, shell string) (string, bool) {
	cands := []string{wanted}
	if ci, err := e.Docker.InspectContainer(ctx, container); err == nil && ci.Config.User != "" {
		cands = append(cands, strings.SplitN(ci.Config.User, ":", 2)[0])
	}
	cands = append(cands, "www-data", "node", "nobody")
	seen := map[string]bool{}
	for _, u := range cands {
		if u == "" || seen[u] || IsRootUser(u) || strings.ContainsAny(u, " \t\r\n/") {
			continue
		}
		seen[u] = true
		if e.userWorks(ctx, container, u, shell) {
			return u, u != wanted
		}
	}
	return wanted, false // let the real exec report Docker's own error
}

// userWorks runs `shell -c "exit 0"` as u; a missing user makes Docker fail it.
func (e *Executor) userWorks(ctx context.Context, container, u, shell string) bool {
	pctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()
	id, err := e.Docker.ExecCreate(pctx, container, docker.ExecConfig{Cmd: []string{shell, "-c", "exit 0"}, User: u})
	if err != nil || e.Docker.ExecStartDetached(pctx, id) != nil {
		return false
	}
	for {
		st, err := e.Docker.ExecInspect(pctx, id)
		if err != nil {
			return false
		}
		if !st.Running {
			return st.ExitCode == 0
		}
		select {
		case <-pctx.Done():
			return false
		case <-time.After(100 * time.Millisecond):
		}
	}
}
