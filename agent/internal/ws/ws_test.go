package ws

import (
	"bytes"
	"context"
	"errors"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func TestAcceptKeyRFCVector(t *testing.T) {
	// RFC 6455 section 1.3.
	if got := AcceptKey("dGhlIHNhbXBsZSBub25jZQ=="); got != "s3pPLMBiTxaQ9kYGzzhZRbK+xOo=" {
		t.Fatal(got)
	}
}

func TestMaskRFCVector(t *testing.T) {
	// RFC 6455 section 5.7: masked "Hello".
	b := []byte("Hello")
	Mask([4]byte{0x37, 0xfa, 0x21, 0x3d}, b)
	if !bytes.Equal(b, []byte{0x7f, 0x9f, 0x4d, 0x51, 0x58}) {
		t.Fatalf("%x", b)
	}
}

func echoServer(t *testing.T) *httptest.Server {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		c, err := Accept(w, r, nil)
		if err != nil {
			return
		}
		for {
			op, data, err := c.ReadMessage()
			if err != nil {
				return
			}
			if string(data) == "close-me" {
				c.Close(4001, "bye")
				return
			}
			c.WriteMessage(op, data)
		}
	}))
	t.Cleanup(srv.Close)
	return srv
}

func dial(t *testing.T, srv *httptest.Server) *Conn {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	c, err := Dial(ctx, "ws"+strings.TrimPrefix(srv.URL, "http")+"/x?y=1", nil)
	if err != nil {
		t.Fatal(err)
	}
	return c
}

func TestEchoTextBinaryAndLarge(t *testing.T) {
	c := dial(t, echoServer(t))
	defer c.Close(CloseNormal, "")
	for _, tc := range []struct {
		op   int
		data []byte
	}{
		{OpText, []byte("hello")},
		{OpBinary, []byte{0, 1, 2, 255}},
		{OpBinary, bytes.Repeat([]byte("a"), 300)},   // 16-bit length
		{OpBinary, bytes.Repeat([]byte("b"), 70000)}, // 64-bit length
		{OpText, []byte("")},
	} {
		if err := c.WriteMessage(tc.op, tc.data); err != nil {
			t.Fatal(err)
		}
		op, got, err := c.ReadMessage()
		if err != nil || op != tc.op || !bytes.Equal(got, tc.data) {
			t.Fatalf("op=%d len=%d err=%v", op, len(got), err)
		}
	}
}

func TestPingPongAndClose(t *testing.T) {
	c := dial(t, echoServer(t))
	pong := make(chan string, 1)
	c.OnPong = func(b []byte) { pong <- string(b) }
	if err := c.Ping([]byte("p1")); err != nil {
		t.Fatal(err)
	}
	c.WriteText("close-me")
	_, _, err := c.ReadMessage()
	var ce *CloseError
	if !errors.As(err, &ce) || ce.Code != 4001 || ce.Reason != "bye" {
		t.Fatalf("err=%v", err)
	}
	select {
	case p := <-pong:
		if p != "p1" {
			t.Fatal(p)
		}
	default:
		t.Fatal("no pong")
	}
	if err := c.WriteText("x"); err == nil {
		t.Fatal("write after close succeeded")
	}
}

// rawFrame builds a frame by hand (masked when key != nil).
func rawFrame(fin bool, op byte, payload []byte, key []byte) []byte {
	b0 := op
	if fin {
		b0 |= 0x80
	}
	out := []byte{b0}
	m := byte(0)
	if key != nil {
		m = 0x80
	}
	out = append(out, m|byte(len(payload)))
	p := append([]byte(nil), payload...)
	if key != nil {
		out = append(out, key...)
		Mask([4]byte{key[0], key[1], key[2], key[3]}, p)
	}
	return append(out, p...)
}

func TestServerReassemblesFragmentsAndRejectsUnmasked(t *testing.T) {
	a, b := net.Pipe()
	srv := NewConn(a, false)
	key := []byte{1, 2, 3, 4}
	go func() {
		b.Write(rawFrame(false, OpText, []byte("Hel"), key))
		b.Write(rawFrame(true, OpPing, []byte("hi"), key)) // control frame between fragments
		b.Write(rawFrame(true, OpContinuation, []byte("lo"), key))
	}()
	// The server answers the ping with an unmasked pong; read it on b.
	go func() {
		buf := make([]byte, 4)
		io.ReadFull(b, buf)
		if buf[0] != 0x8A || buf[1] != 2 || string(buf[2:]) != "hi" {
			t.Errorf("pong frame %x", buf)
		}
	}()
	op, data, err := srv.ReadMessage()
	if err != nil || op != OpText || string(data) != "Hello" {
		t.Fatalf("%d %q %v", op, data, err)
	}
	go b.Write(rawFrame(true, OpText, []byte("x"), nil))
	go io.Copy(io.Discard, b)
	if _, _, err := srv.ReadMessage(); err == nil || !strings.Contains(err.Error(), "masked") {
		t.Fatalf("unmasked frame accepted: %v", err)
	}
}

func TestInvalidUTF8AndOversize(t *testing.T) {
	a, b := net.Pipe()
	srv := NewConn(a, false)
	go io.Copy(io.Discard, b)
	go b.Write(rawFrame(true, OpText, []byte{0xff, 0xfe}, []byte{9, 9, 9, 9}))
	if _, _, err := srv.ReadMessage(); err == nil || !strings.Contains(err.Error(), "utf-8") {
		t.Fatal(err)
	}
	a2, b2 := net.Pipe()
	srv2 := NewConn(a2, false)
	srv2.MaxMessage = 3
	go io.Copy(io.Discard, b2)
	go b2.Write(rawFrame(true, OpBinary, []byte("toolong"), []byte{1, 1, 1, 1}))
	if _, _, err := srv2.ReadMessage(); err == nil || !strings.Contains(err.Error(), "too big") {
		t.Fatal(err)
	}
}

func TestHandshakeRejections(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		Accept(w, r, nil)
	}))
	defer srv.Close()
	resp, err := http.Get(srv.URL)
	if err != nil || resp.StatusCode != 400 {
		t.Fatal(resp.StatusCode, err)
	}
	ctx := context.Background()
	_, err = Dial(ctx, "ws"+strings.TrimPrefix(srv.URL, "http"), &DialOptions{Header: http.Header{"Origin": {"https://evil.example"}}})
	var he *HandshakeError
	if !errors.As(err, &he) || he.Status != 403 {
		t.Fatalf("cross-origin accepted: %v", err)
	}
	if _, err := Dial(ctx, "ftp://x", nil); err == nil {
		t.Fatal("bad scheme accepted")
	}
}
