// Package relay is the terminal WebSocket relay (dockermgr-relay). Browsers
// connect to /relay/terminal/{session}?token=…; agents keep an outbound
// control connection on /relay/agent and dial back on
// /relay/agent/session/{session} when they receive a signed terminal.open
// command. The relay never talks to Docker and never opens connections to
// agents: it only pairs two inbound WebSockets the API has authorised, enforces
// expiry and "end session", records output (asciicast v2) and reports the
// close to the API.
package relay

import (
	"context"
	"encoding/json"
	"errors"
	"log"
	"net"
	"net/http"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/livezen-technologies/cloudpanel/agent/internal/ws"
)

// Server pairs browser and agent connections.
type Server struct {
	API           API
	Log           *log.Logger
	CheckOrigin   func(*http.Request) bool
	PollInterval  time.Duration // session status poll ("end session"), default 2 s
	AttachTimeout time.Duration // wait for the agent to dial back, default 45 s
	MaxRecording  int           // bytes, default 8 MiB
	PingInterval  time.Duration // default 25 s

	mu      sync.Mutex
	agents  map[string]*agentLink // server_id -> control connection
	waiting map[string]*waiter    // session_id -> pending browser
}

type agentLink struct {
	conn *ws.Conn
}

type waiter struct {
	serverID string
	ch       chan *ws.Conn
}

func (s *Server) logf(f string, a ...any) {
	if s.Log != nil {
		s.Log.Printf(f, a...)
	}
}

func (s *Server) init() {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.agents == nil {
		s.agents = map[string]*agentLink{}
		s.waiting = map[string]*waiter{}
	}
}

// Handler serves the relay routes.
func (s *Server) Handler() http.Handler {
	s.init()
	mux := http.NewServeMux()
	mux.HandleFunc("GET /relay/terminal/{id}", s.browser)
	mux.HandleFunc("GET /relay/agent", s.agentControl)
	mux.HandleFunc("GET /relay/agent/session/{id}", s.agentSession)
	mux.HandleFunc("GET /relay/healthz", func(w http.ResponseWriter, r *http.Request) {
		s.mu.Lock()
		n, p := len(s.agents), len(s.waiting)
		s.mu.Unlock()
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(map[string]any{"ok": true, "agents": n, "pending": p})
	})
	return mux
}

// ConnectedAgents reports whether a server has a control connection (tests).
func (s *Server) ConnectedAgents(serverID string) bool {
	s.mu.Lock()
	defer s.mu.Unlock()
	_, ok := s.agents[serverID]
	return ok
}

func bearer(r *http.Request) string {
	h := r.Header.Get("Authorization")
	if len(h) > 7 && strings.EqualFold(h[:7], "bearer ") {
		return strings.TrimSpace(h[7:])
	}
	return ""
}

func clientIP(r *http.Request) string {
	if f := r.Header.Get("X-Forwarded-For"); f != "" {
		return strings.TrimSpace(strings.Split(f, ",")[0])
	}
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		return r.RemoteAddr
	}
	return host
}

func (s *Server) verifyAgent(w http.ResponseWriter, r *http.Request) (string, bool) {
	secret := bearer(r)
	if secret == "" {
		http.Error(w, "agent credentials required", http.StatusUnauthorized)
		return "", false
	}
	ctx, cancel := context.WithTimeout(r.Context(), 15*time.Second)
	defer cancel()
	id, err := s.API.VerifyAgent(ctx, secret, r.Header)
	if err != nil {
		code := http.StatusBadGateway
		var ae *APIError
		if errors.As(err, &ae) && ae.Code >= 400 && ae.Code < 500 {
			code = http.StatusUnauthorized
		}
		http.Error(w, "agent not authorised", code)
		return "", false
	}
	return id, true
}

// agentControl keeps the agent's outbound control connection.
func (s *Server) agentControl(w http.ResponseWriter, r *http.Request) {
	serverID, ok := s.verifyAgent(w, r)
	if !ok {
		return
	}
	conn, err := ws.Accept(w, r, &ws.AcceptOptions{CheckOrigin: func(*http.Request) bool { return true }})
	if err != nil {
		return
	}
	link := &agentLink{conn: conn}
	s.mu.Lock()
	if old := s.agents[serverID]; old != nil {
		old.conn.Close(ws.ClosePolicyViolation, "replaced by a newer connection")
	}
	s.agents[serverID] = link
	s.mu.Unlock()
	s.logf("agent %s connected (%s)", serverID, clientIP(r))
	stop := s.keepalive(conn)
	defer func() {
		close(stop)
		s.mu.Lock()
		if s.agents[serverID] == link {
			delete(s.agents, serverID)
		}
		s.mu.Unlock()
		conn.Close(ws.CloseNormal, "")
		s.logf("agent %s disconnected", serverID)
	}()
	for {
		conn.SetReadDeadline(time.Now().Add(3 * s.pingInterval()))
		if _, _, err := conn.ReadMessage(); err != nil {
			return
		}
	}
}

func (s *Server) pingInterval() time.Duration {
	if s.PingInterval > 0 {
		return s.PingInterval
	}
	return 25 * time.Second
}

func (s *Server) keepalive(conn *ws.Conn) chan struct{} {
	stop := make(chan struct{})
	go func() {
		t := time.NewTicker(s.pingInterval())
		defer t.Stop()
		for {
			select {
			case <-stop:
				return
			case <-t.C:
				if conn.Ping(nil) != nil {
					return
				}
			}
		}
	}()
	return stop
}

// pushCommand forwards a signed command over the control connection.
func (s *Server) pushCommand(serverID string, cmd json.RawMessage) bool {
	if len(cmd) == 0 {
		return false
	}
	s.mu.Lock()
	link := s.agents[serverID]
	s.mu.Unlock()
	if link == nil {
		return false
	}
	b, _ := json.Marshal(map[string]any{"type": "command", "command": cmd})
	return link.conn.WriteText(string(b)) == nil
}

// agentSession accepts the agent's per-session dial-back.
func (s *Server) agentSession(w http.ResponseWriter, r *http.Request) {
	serverID, ok := s.verifyAgent(w, r)
	if !ok {
		return
	}
	id := r.PathValue("id")
	s.mu.Lock()
	wt := s.waiting[id]
	s.mu.Unlock()
	if wt == nil || wt.serverID != serverID {
		http.Error(w, "no pending session for this agent", http.StatusNotFound)
		return
	}
	conn, err := ws.Accept(w, r, &ws.AcceptOptions{CheckOrigin: func(*http.Request) bool { return true }})
	if err != nil {
		return
	}
	conn.MaxMessage = 1 << 20
	select {
	case wt.ch <- conn:
	default:
		conn.Close(ws.ClosePolicyViolation, "session already attached")
	}
}

type control struct {
	Type    string `json:"type"`
	Cols    int    `json:"cols,omitempty"`
	Rows    int    `json:"rows,omitempty"`
	Code    *int   `json:"code,omitempty"`
	Message string `json:"message,omitempty"`
	Reason  string `json:"reason,omitempty"`
}

func send(conn *ws.Conn, v control) error {
	b, _ := json.Marshal(v)
	return conn.WriteText(string(b))
}

type frame struct {
	op   int
	data []byte
}

func reader(conn *ws.Conn) (<-chan frame, <-chan error) {
	out := make(chan frame, 64)
	done := make(chan error, 1)
	go func() {
		defer close(out)
		for {
			op, data, err := conn.ReadMessage()
			if err != nil {
				done <- err
				return
			}
			out <- frame{op, data}
		}
	}()
	return out, done
}

func atoi(s string, def int) int {
	n, err := strconv.Atoi(s)
	if err != nil || n <= 0 || n > 1000 {
		return def
	}
	return n
}

// browser handles one terminal session end to end.
func (s *Server) browser(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	token := r.URL.Query().Get("token")
	cols, rows := atoi(r.URL.Query().Get("cols"), 80), atoi(r.URL.Query().Get("rows"), 24)
	check := s.CheckOrigin
	if check == nil {
		check = ws.SameOrigin
	}
	conn, err := ws.Accept(w, r, &ws.AcceptOptions{CheckOrigin: check})
	if err != nil {
		return
	}
	conn.MaxMessage = 64 << 10
	ip := clientIP(r)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	cctx, ccancel := context.WithTimeout(ctx, 15*time.Second)
	sess, err := s.API.Connect(cctx, id, token, cols, rows, ip)
	ccancel()
	if err != nil {
		msg := "The terminal session could not be opened."
		code := 4500
		var ae *APIError
		if errors.As(err, &ae) {
			msg, code = ae.Message, 4000+ae.Code
		}
		send(conn, control{Type: "error", Message: msg})
		conn.Close(code, "refused")
		return
	}
	s.logf("session %s: browser %s connected (server %s, container %s)", id, ip, sess.ServerID, sess.ContainerID)

	wt := &waiter{serverID: sess.ServerID, ch: make(chan *ws.Conn, 1)}
	s.mu.Lock()
	if s.waiting[id] != nil {
		s.mu.Unlock()
		send(conn, control{Type: "error", Message: "This session is already connected."})
		conn.Close(4409, "busy")
		return
	}
	s.waiting[id] = wt
	s.mu.Unlock()
	defer func() {
		s.mu.Lock()
		if s.waiting[id] == wt {
			delete(s.waiting, id)
		}
		s.mu.Unlock()
	}()

	max := s.MaxRecording
	if max <= 0 {
		max = 8 << 20
	}
	var rec *Recorder
	if sess.Recording {
		rec = NewRecorder(cols, rows, sess.ContainerName, time.Now(), max)
	}
	reason := s.run(ctx, conn, wt, sess, rec, &cols, &rows)

	send(conn, control{Type: "closed", Reason: reason})
	conn.Close(ws.CloseNormal, reason)
	s.logf("session %s closed: %s", id, reason)
	fctx, fcancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer fcancel()
	if rec != nil {
		if err := s.API.UploadRecording(fctx, id, rec.Bytes()); err != nil {
			s.logf("session %s: recording upload failed: %v", id, err)
		}
	}
	if err := s.API.Closed(fctx, id, reason); err != nil {
		s.logf("session %s: close report failed: %v", id, err)
	}
}

// run waits for the agent and bridges until a close reason occurs.
func (s *Server) run(ctx context.Context, conn *ws.Conn, wt *waiter, sess *Session, rec *Recorder, cols, rows *int) string {
	bmsgs, bdone := reader(conn)
	expiry := time.NewTimer(time.Until(sess.ExpiresAt))
	defer expiry.Stop()
	poll := s.PollInterval
	if poll <= 0 {
		poll = 2 * time.Second
	}
	ticker := time.NewTicker(poll)
	defer ticker.Stop()
	attach := s.AttachTimeout
	if attach <= 0 {
		attach = 45 * time.Second
	}
	attachTimer := time.NewTimer(attach)
	defer attachTimer.Stop()

	statusCheck := func() string {
		sctx, cancel := context.WithTimeout(ctx, 10*time.Second)
		defer cancel()
		st, err := s.API.Status(sctx, sess.SessionID)
		if err != nil {
			var ae *APIError
			if errors.As(err, &ae) && (ae.Code == 404 || ae.Code == 410) {
				return "ended"
			}
			return ""
		}
		// The API may shorten a session (policy change, admin action): honour the current expiry.
		if !st.ExpiresAt.IsZero() && !time.Now().Before(st.ExpiresAt) {
			return "expired"
		}
		if st.Ended {
			return "ended"
		}
		return ""
	}

	if s.pushCommand(sess.ServerID, sess.Command) {
		send(conn, control{Type: "status", Message: "Waiting for the agent to attach…"})
	} else {
		send(conn, control{Type: "status", Message: "Agent not connected to the relay; waiting for its next heartbeat…"})
	}

	// Phase 1: wait for the agent's dial-back.
	var agent *ws.Conn
	for agent == nil {
		select {
		case a := <-wt.ch:
			agent = a
		case f, ok := <-bmsgs:
			if !ok {
				bmsgs = nil
				continue
			}
			if f.op == ws.OpText {
				var c control
				if json.Unmarshal(f.data, &c) == nil {
					if c.Type == "resize" && c.Cols > 0 && c.Rows > 0 && c.Cols <= 1000 && c.Rows <= 1000 {
						*cols, *rows = c.Cols, c.Rows
					} else if c.Type == "close" {
						return "closed by user"
					}
				}
			}
		case <-bdone:
			return "browser disconnected"
		case <-expiry.C:
			return "expired"
		case <-attachTimer.C:
			send(conn, control{Type: "error", Message: "The agent did not attach in time. Check that it is online."})
			return "agent did not attach"
		case <-ticker.C:
			if r := statusCheck(); r != "" {
				return r
			}
		}
	}
	defer func() {
		send(agent, control{Type: "close"})
		agent.Close(ws.CloseNormal, "")
	}()
	stop := s.keepalive(agent)
	defer close(stop)
	send(agent, control{Type: "resize", Cols: *cols, Rows: *rows})
	amsgs, adone := reader(agent)

	for {
		select {
		case f, ok := <-amsgs:
			if !ok {
				amsgs = nil
				continue
			}
			if f.op == ws.OpBinary {
				if rec != nil {
					rec.Output(f.data)
				}
				if conn.WriteMessage(ws.OpBinary, f.data) != nil {
					return "browser disconnected"
				}
				continue
			}
			var c control
			if json.Unmarshal(f.data, &c) != nil {
				continue
			}
			switch c.Type {
			case "ready":
				send(conn, control{Type: "ready", Message: sess.ShellUser + "@" + sess.ContainerName})
			case "error":
				send(conn, control{Type: "error", Message: c.Message})
				return "agent error: " + c.Message
			case "exit":
				code := 0
				if c.Code != nil {
					code = *c.Code
				}
				send(conn, control{Type: "exit", Code: &code})
				return "shell exited (" + strconv.Itoa(code) + ")"
			}
		case <-adone:
			return "agent disconnected"
		case f, ok := <-bmsgs:
			if !ok {
				bmsgs = nil
				continue
			}
			if f.op == ws.OpBinary {
				if agent.WriteMessage(ws.OpBinary, f.data) != nil {
					return "agent disconnected"
				}
				continue
			}
			var c control
			if json.Unmarshal(f.data, &c) != nil {
				continue
			}
			switch c.Type {
			case "input": // text input fallback
				agent.WriteMessage(ws.OpBinary, []byte(c.Message))
			case "resize":
				if c.Cols > 0 && c.Rows > 0 && c.Cols <= 1000 && c.Rows <= 1000 {
					*cols, *rows = c.Cols, c.Rows
					if rec != nil {
						rec.Resize(c.Cols, c.Rows)
					}
					send(agent, control{Type: "resize", Cols: c.Cols, Rows: c.Rows})
				}
			case "close":
				return "closed by user"
			}
		case <-bdone:
			return "browser disconnected"
		case <-expiry.C:
			send(conn, control{Type: "status", Message: "Session expired."})
			return "expired"
		case <-ticker.C:
			if r := statusCheck(); r != "" {
				return r
			}
		}
	}
}
