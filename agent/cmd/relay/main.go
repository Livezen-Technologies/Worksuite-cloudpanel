// Command dockermgr-relay is the terminal WebSocket relay between browsers and
// agents (see internal/relay). Configuration is by environment:
//
//	RELAY_LISTEN            listen address (default :8090)
//	RELAY_API_URL           Laravel API base (default http://127.0.0.1:8000)
//	RELAY_SECRET            shared secret, same as the API's RELAY_SECRET (required)
//	RELAY_ALLOWED_ORIGINS   comma-separated browser origins (default: same origin as Host)
//	RELAY_TLS_CERT/KEY      serve TLS directly (normally a reverse proxy terminates TLS)
//	RELAY_FORWARD_HEADERS   proxy client-cert headers passed to the API for agent mTLS
//	RELAY_ATTACH_TIMEOUT    seconds to wait for the agent to dial back (default 45)
package main

import (
	"context"
	"errors"
	"fmt"
	"log"
	"net/http"
	"net/url"
	"os"
	"os/signal"
	"slices"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/livezen-technologies/cloudpanel/agent/internal/relay"
)

var version = "dev"

func env(k, def string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return def
}

func splitList(s string) []string {
	var out []string
	for _, p := range strings.Split(s, ",") {
		if p = strings.TrimSpace(p); p != "" {
			out = append(out, p)
		}
	}
	return out
}

func main() {
	if len(os.Args) > 1 && (os.Args[1] == "version" || os.Args[1] == "--version") {
		fmt.Println(version)
		return
	}
	logger := log.New(os.Stderr, "relay ", log.LstdFlags)
	secret := os.Getenv("RELAY_SECRET")
	if len(secret) < 16 {
		logger.Fatal("RELAY_SECRET (at least 16 characters, shared with the API) is required")
	}
	api := &relay.HTTPAPI{
		BaseURL:        env("RELAY_API_URL", "http://127.0.0.1:8000"),
		Secret:         secret,
		HTTP:           &http.Client{Timeout: 30 * time.Second},
		ForwardHeaders: splitList(env("RELAY_FORWARD_HEADERS", "X-SSL-Client-Verify,X-Client-Cert-Fingerprint,X-Client-Cert")),
	}
	srv := &relay.Server{API: api, Log: logger}
	if n, err := strconv.Atoi(os.Getenv("RELAY_ATTACH_TIMEOUT")); err == nil && n > 0 {
		srv.AttachTimeout = time.Duration(n) * time.Second
	}
	if origins := splitList(os.Getenv("RELAY_ALLOWED_ORIGINS")); len(origins) > 0 {
		srv.CheckOrigin = func(r *http.Request) bool {
			o := r.Header.Get("Origin")
			if o == "" {
				return false
			}
			u, err := url.Parse(o)
			return err == nil && slices.Contains(origins, u.Scheme+"://"+u.Host)
		}
	}
	hs := &http.Server{Addr: env("RELAY_LISTEN", ":8090"), Handler: srv.Handler(), ReadHeaderTimeout: 15 * time.Second}
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()
	go func() {
		<-ctx.Done()
		sctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		hs.Shutdown(sctx)
	}()
	logger.Printf("dockermgr-relay %s listening on %s (api %s)", version, hs.Addr, api.BaseURL)
	var err error
	if cert, key := os.Getenv("RELAY_TLS_CERT"), os.Getenv("RELAY_TLS_KEY"); cert != "" && key != "" {
		err = hs.ListenAndServeTLS(cert, key)
	} else {
		err = hs.ListenAndServe()
	}
	if err != nil && !errors.Is(err, http.ErrServerClosed) {
		logger.Fatal(err)
	}
}
