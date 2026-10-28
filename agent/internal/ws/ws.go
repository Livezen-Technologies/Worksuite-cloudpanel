// Package ws is a small RFC 6455 WebSocket implementation (server accept and
// client dial) on the standard library only. It supports text and binary
// messages, fragmentation on read, ping/pong, close handshakes and client
// masking. Extensions (permessage-deflate) and subprotocols are not offered.
package ws

import (
	"bufio"
	"context"
	"crypto/rand"
	"crypto/sha1"
	"crypto/tls"
	"encoding/base64"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"
	"unicode/utf8"
)

// Opcodes (RFC 6455 section 5.2).
const (
	OpContinuation = 0x0
	OpText         = 0x1
	OpBinary       = 0x2
	OpClose        = 0x8
	OpPing         = 0x9
	OpPong         = 0xA
)

// Close codes used by this module.
const (
	CloseNormal          = 1000
	CloseGoingAway       = 1001
	CloseProtocolError   = 1002
	CloseUnsupported     = 1003
	CloseNoStatus        = 1005
	CloseInvalidPayload  = 1007
	ClosePolicyViolation = 1008
	CloseTooBig          = 1009
	CloseInternalError   = 1011
)

const acceptGUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"

// DefaultMaxMessage bounds a reassembled message.
const DefaultMaxMessage = 1 << 20

// AcceptKey computes Sec-WebSocket-Accept for a Sec-WebSocket-Key.
func AcceptKey(key string) string {
	h := sha1.Sum([]byte(key + acceptGUID))
	return base64.StdEncoding.EncodeToString(h[:])
}

// CloseError is returned by ReadMessage when the peer closed the connection.
type CloseError struct {
	Code   int
	Reason string
}

func (e *CloseError) Error() string { return fmt.Sprintf("websocket closed: %d %s", e.Code, e.Reason) }

// ErrClosed is returned when writing to a connection that was closed locally.
var ErrClosed = errors.New("websocket: connection closed")

// Conn is a WebSocket connection. One goroutine may read while others write.
type Conn struct {
	nc         net.Conn
	br         *bufio.Reader
	client     bool // client side: mask outgoing frames, expect unmasked incoming
	MaxMessage int64

	wmu        sync.Mutex
	closeSent  bool
	closed     bool
	OnPong     func([]byte)
	remoteAddr string
}

func newConn(nc net.Conn, br *bufio.Reader, client bool) *Conn {
	if br == nil {
		br = bufio.NewReader(nc)
	}
	return &Conn{nc: nc, br: br, client: client, MaxMessage: DefaultMaxMessage, remoteAddr: nc.RemoteAddr().String()}
}

// NewConn wraps an established connection (after the handshake). Exposed for tests.
func NewConn(nc net.Conn, client bool) *Conn { return newConn(nc, nil, client) }

// RemoteAddr of the underlying connection.
func (c *Conn) RemoteAddr() string { return c.remoteAddr }

// SetReadDeadline sets the underlying read deadline.
func (c *Conn) SetReadDeadline(t time.Time) error { return c.nc.SetReadDeadline(t) }

func headerHasToken(h http.Header, name, token string) bool {
	for _, v := range h.Values(name) {
		for _, part := range strings.Split(v, ",") {
			if strings.EqualFold(strings.TrimSpace(part), token) {
				return true
			}
		}
	}
	return false
}

// AcceptOptions control the server handshake.
type AcceptOptions struct {
	// CheckOrigin returns true when the Origin header is acceptable. Nil
	// accepts requests without Origin and those whose Origin host equals Host.
	CheckOrigin func(r *http.Request) bool
}

// SameOrigin is the default origin policy.
func SameOrigin(r *http.Request) bool {
	o := r.Header.Get("Origin")
	if o == "" {
		return true
	}
	u, err := url.Parse(o)
	return err == nil && strings.EqualFold(u.Host, r.Host)
}

// Accept performs the server handshake. On failure an HTTP error has been written.
func Accept(w http.ResponseWriter, r *http.Request, opts *AcceptOptions) (*Conn, error) {
	fail := func(code int, msg string) (*Conn, error) {
		http.Error(w, msg, code)
		return nil, errors.New("websocket handshake: " + msg)
	}
	if r.Method != http.MethodGet {
		return fail(http.StatusMethodNotAllowed, "method must be GET")
	}
	if !headerHasToken(r.Header, "Connection", "upgrade") || !headerHasToken(r.Header, "Upgrade", "websocket") {
		return fail(http.StatusBadRequest, "not a websocket upgrade")
	}
	if r.Header.Get("Sec-WebSocket-Version") != "13" {
		w.Header().Set("Sec-WebSocket-Version", "13")
		return fail(http.StatusUpgradeRequired, "unsupported websocket version")
	}
	key := r.Header.Get("Sec-WebSocket-Key")
	if b, err := base64.StdEncoding.DecodeString(key); err != nil || len(b) != 16 {
		return fail(http.StatusBadRequest, "bad Sec-WebSocket-Key")
	}
	check := SameOrigin
	if opts != nil && opts.CheckOrigin != nil {
		check = opts.CheckOrigin
	}
	if !check(r) {
		return fail(http.StatusForbidden, "origin not allowed")
	}
	hj, ok := w.(http.Hijacker)
	if !ok {
		return fail(http.StatusInternalServerError, "connection cannot be hijacked")
	}
	nc, brw, err := hj.Hijack()
	if err != nil {
		return nil, err
	}
	resp := "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " + AcceptKey(key) + "\r\n\r\n"
	nc.SetWriteDeadline(time.Now().Add(10 * time.Second))
	if _, err := nc.Write([]byte(resp)); err != nil {
		nc.Close()
		return nil, err
	}
	nc.SetWriteDeadline(time.Time{})
	return newConn(nc, brw.Reader, false), nil
}

// DialOptions control the client handshake.
type DialOptions struct {
	Header    http.Header
	TLSConfig *tls.Config
	// NetDial overrides how the TCP connection is made (tests, proxies).
	NetDial func(ctx context.Context, network, addr string) (net.Conn, error)
}

// HandshakeError carries the HTTP status of a refused upgrade.
type HandshakeError struct {
	Status int
	Body   string
}

func (e *HandshakeError) Error() string {
	return fmt.Sprintf("websocket handshake refused: %d %s", e.Status, strings.TrimSpace(e.Body))
}

// Dial connects to a ws:// or wss:// (http/https accepted) URL.
func Dial(ctx context.Context, rawURL string, opts *DialOptions) (*Conn, error) {
	if opts == nil {
		opts = &DialOptions{}
	}
	u, err := url.Parse(rawURL)
	if err != nil {
		return nil, err
	}
	secure := false
	switch u.Scheme {
	case "ws", "http":
	case "wss", "https":
		secure = true
	default:
		return nil, fmt.Errorf("unsupported websocket scheme %q", u.Scheme)
	}
	host := u.Host
	if u.Port() == "" {
		if secure {
			host = net.JoinHostPort(u.Hostname(), "443")
		} else {
			host = net.JoinHostPort(u.Hostname(), "80")
		}
	}
	dial := opts.NetDial
	if dial == nil {
		d := &net.Dialer{Timeout: 30 * time.Second}
		dial = d.DialContext
	}
	nc, err := dial(ctx, "tcp", host)
	if err != nil {
		return nil, err
	}
	if dl, ok := ctx.Deadline(); ok {
		nc.SetDeadline(dl)
	} else {
		nc.SetDeadline(time.Now().Add(30 * time.Second))
	}
	if secure {
		cfg := &tls.Config{MinVersion: tls.VersionTLS12}
		if opts.TLSConfig != nil {
			cfg = opts.TLSConfig.Clone()
		}
		if cfg.ServerName == "" {
			cfg.ServerName = u.Hostname()
		}
		cfg.NextProtos = []string{"http/1.1"}
		tc := tls.Client(nc, cfg)
		if err := tc.HandshakeContext(ctx); err != nil {
			nc.Close()
			return nil, err
		}
		nc = tc
	}
	keyb := make([]byte, 16)
	rand.Read(keyb)
	key := base64.StdEncoding.EncodeToString(keyb)
	path := u.RequestURI()
	var sb strings.Builder
	sb.WriteString("GET " + path + " HTTP/1.1\r\nHost: " + u.Host + "\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n")
	sb.WriteString("Sec-WebSocket-Key: " + key + "\r\nSec-WebSocket-Version: 13\r\n")
	for k, vs := range opts.Header {
		for _, v := range vs {
			if strings.ContainsAny(k+v, "\r\n") {
				nc.Close()
				return nil, errors.New("invalid header")
			}
			sb.WriteString(k + ": " + v + "\r\n")
		}
	}
	sb.WriteString("\r\n")
	if _, err := io.WriteString(nc, sb.String()); err != nil {
		nc.Close()
		return nil, err
	}
	br := bufio.NewReader(nc)
	req := &http.Request{Method: http.MethodGet}
	resp, err := http.ReadResponse(br, req)
	if err != nil {
		nc.Close()
		return nil, err
	}
	if resp.StatusCode != http.StatusSwitchingProtocols {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 1024))
		nc.Close()
		return nil, &HandshakeError{Status: resp.StatusCode, Body: string(b)}
	}
	if !headerHasToken(resp.Header, "Upgrade", "websocket") || resp.Header.Get("Sec-WebSocket-Accept") != AcceptKey(key) {
		nc.Close()
		return nil, errors.New("websocket handshake: bad accept header")
	}
	nc.SetDeadline(time.Time{})
	return newConn(nc, br, true), nil
}

// writeFrame writes one frame (caller holds wmu).
func (c *Conn) writeFrame(fin bool, op int, payload []byte) error {
	if c.closed {
		return ErrClosed
	}
	hdr := make([]byte, 0, 14)
	b0 := byte(op)
	if fin {
		b0 |= 0x80
	}
	hdr = append(hdr, b0)
	var mbit byte
	if c.client {
		mbit = 0x80
	}
	n := len(payload)
	switch {
	case n <= 125:
		hdr = append(hdr, mbit|byte(n))
	case n <= 0xFFFF:
		hdr = append(hdr, mbit|126, byte(n>>8), byte(n))
	default:
		hdr = append(hdr, mbit|127)
		hdr = binary.BigEndian.AppendUint64(hdr, uint64(n))
	}
	data := payload
	if c.client {
		var key [4]byte
		rand.Read(key[:])
		hdr = append(hdr, key[:]...)
		data = make([]byte, n)
		copy(data, payload)
		Mask(key, data)
	}
	c.nc.SetWriteDeadline(time.Now().Add(30 * time.Second))
	defer c.nc.SetWriteDeadline(time.Time{})
	if _, err := c.nc.Write(append(hdr, data...)); err != nil {
		return err
	}
	return nil
}

// Mask applies (or removes) the RFC 6455 masking in place.
func Mask(key [4]byte, b []byte) {
	for i := range b {
		b[i] ^= key[i&3]
	}
}

// WriteMessage sends a complete text or binary message.
func (c *Conn) WriteMessage(op int, data []byte) error {
	if op != OpText && op != OpBinary {
		return errors.New("websocket: WriteMessage takes OpText or OpBinary")
	}
	c.wmu.Lock()
	defer c.wmu.Unlock()
	if c.closeSent {
		return ErrClosed
	}
	return c.writeFrame(true, op, data)
}

// WriteText is WriteMessage(OpText, ...).
func (c *Conn) WriteText(s string) error { return c.WriteMessage(OpText, []byte(s)) }

// Ping sends a ping control frame.
func (c *Conn) Ping(data []byte) error {
	if len(data) > 125 {
		return errors.New("websocket: ping payload too long")
	}
	c.wmu.Lock()
	defer c.wmu.Unlock()
	if c.closeSent {
		return ErrClosed
	}
	return c.writeFrame(true, OpPing, data)
}

func closePayload(code int, reason string) []byte {
	if code == CloseNoStatus || code == 0 {
		return nil
	}
	if len(reason) > 123 {
		reason = reason[:123]
	}
	b := []byte{byte(code >> 8), byte(code)}
	return append(b, reason...)
}

// sendClose sends the close frame once.
func (c *Conn) sendClose(code int, reason string) error {
	c.wmu.Lock()
	defer c.wmu.Unlock()
	if c.closeSent || c.closed {
		return nil
	}
	c.closeSent = true
	return c.writeFrame(true, OpClose, closePayload(code, reason))
}

// Close sends a close frame (if not yet sent), waits briefly for the peer's
// close, and closes the connection. Safe to call more than once.
func (c *Conn) Close(code int, reason string) error {
	c.sendClose(code, reason)
	c.wmu.Lock()
	already := c.closed
	c.closed = true
	c.wmu.Unlock()
	if already {
		return nil
	}
	return c.nc.Close()
}

// CloseNow closes the transport without a close handshake.
func (c *Conn) CloseNow() error {
	c.wmu.Lock()
	already := c.closed
	c.closed = true
	c.wmu.Unlock()
	if already {
		return nil
	}
	return c.nc.Close()
}

type protoError struct {
	code int
	msg  string
}

func (e *protoError) Error() string { return "websocket protocol error: " + e.msg }

// ReadMessage returns the next text or binary message. Pings are answered,
// pongs are passed to OnPong, a close frame is answered and returned as
// *CloseError. Protocol violations close the connection with 1002/1007/1009.
func (c *Conn) ReadMessage() (int, []byte, error) {
	var msgOp int
	var buf []byte
	inMessage := false
	for {
		fin, op, payload, err := c.readFrame()
		if err != nil {
			var pe *protoError
			if errors.As(err, &pe) {
				c.sendClose(pe.code, pe.msg)
				c.CloseNow()
			}
			return 0, nil, err
		}
		switch op {
		case OpPing:
			c.wmu.Lock()
			if !c.closeSent {
				c.writeFrame(true, OpPong, payload)
			}
			c.wmu.Unlock()
			continue
		case OpPong:
			if c.OnPong != nil {
				c.OnPong(payload)
			}
			continue
		case OpClose:
			ce := &CloseError{Code: CloseNoStatus}
			if len(payload) == 1 {
				c.sendClose(CloseProtocolError, "bad close payload")
				c.CloseNow()
				return 0, nil, &protoError{CloseProtocolError, "bad close payload"}
			}
			if len(payload) >= 2 {
				ce.Code = int(binary.BigEndian.Uint16(payload))
				ce.Reason = string(payload[2:])
			}
			reply := ce.Code
			if reply == CloseNoStatus {
				reply = CloseNormal
			}
			c.sendClose(reply, "")
			c.CloseNow()
			return 0, nil, ce
		case OpText, OpBinary:
			if inMessage {
				return 0, nil, c.fail(CloseProtocolError, "new message before previous finished")
			}
			msgOp, buf, inMessage = op, append([]byte(nil), payload...), true
		case OpContinuation:
			if !inMessage {
				return 0, nil, c.fail(CloseProtocolError, "unexpected continuation frame")
			}
			buf = append(buf, payload...)
		default:
			return 0, nil, c.fail(CloseProtocolError, fmt.Sprintf("unknown opcode %d", op))
		}
		if c.MaxMessage > 0 && int64(len(buf)) > c.MaxMessage {
			return 0, nil, c.fail(CloseTooBig, "message too big")
		}
		if fin {
			if msgOp == OpText && !utf8.Valid(buf) {
				return 0, nil, c.fail(CloseInvalidPayload, "invalid utf-8 in text message")
			}
			return msgOp, buf, nil
		}
	}
}

func (c *Conn) fail(code int, msg string) error {
	c.sendClose(code, msg)
	c.CloseNow()
	return &protoError{code, msg}
}

func (c *Conn) readFrame() (fin bool, op int, payload []byte, err error) {
	var h [2]byte
	if _, err = io.ReadFull(c.br, h[:]); err != nil {
		return
	}
	fin = h[0]&0x80 != 0
	if h[0]&0x70 != 0 {
		err = &protoError{CloseProtocolError, "reserved bits set"}
		return
	}
	op = int(h[0] & 0x0F)
	masked := h[1]&0x80 != 0
	n := uint64(h[1] & 0x7F)
	if op >= 0x8 {
		if !fin || n > 125 {
			err = &protoError{CloseProtocolError, "invalid control frame"}
			return
		}
	}
	switch n {
	case 126:
		var b [2]byte
		if _, err = io.ReadFull(c.br, b[:]); err != nil {
			return
		}
		n = uint64(binary.BigEndian.Uint16(b[:]))
	case 127:
		var b [8]byte
		if _, err = io.ReadFull(c.br, b[:]); err != nil {
			return
		}
		n = binary.BigEndian.Uint64(b[:])
		if n>>63 != 0 {
			err = &protoError{CloseProtocolError, "invalid length"}
			return
		}
	}
	if c.client && masked {
		err = &protoError{CloseProtocolError, "server frames must not be masked"}
		return
	}
	if !c.client && !masked {
		err = &protoError{CloseProtocolError, "client frames must be masked"}
		return
	}
	if c.MaxMessage > 0 && n > uint64(c.MaxMessage) {
		err = &protoError{CloseTooBig, "frame too big"}
		return
	}
	var key [4]byte
	if masked {
		if _, err = io.ReadFull(c.br, key[:]); err != nil {
			return
		}
	}
	payload = make([]byte, n)
	if _, err = io.ReadFull(c.br, payload); err != nil {
		return
	}
	if masked {
		Mask(key, payload)
	}
	return
}

// IsClose reports whether err is a normal peer close (1000/1001/1005) or EOF.
func IsClose(err error) bool {
	var ce *CloseError
	if errors.As(err, &ce) {
		return ce.Code == CloseNormal || ce.Code == CloseGoingAway || ce.Code == CloseNoStatus
	}
	return errors.Is(err, io.EOF) || errors.Is(err, net.ErrClosed)
}
