package main

import (
	"context"
	"encoding/json"
	"net/http"
	"strings"
	"time"

	"github.com/livezen-technologies/cloudpanel/agent/internal/mtls"
	"github.com/livezen-technologies/cloudpanel/agent/internal/protocol"
	"github.com/livezen-technologies/cloudpanel/agent/internal/ws"
)

// relayBase returns the configured relay URL, else the one the panel advertised.
func (a *agent) relayBase() string {
	if a.cfg.RelayURL != "" {
		return a.cfg.RelayURL
	}
	if v, ok := a.advertisedRelay.Load().(string); ok {
		return v
	}
	return ""
}

func relayControlURL(base string) string {
	base = strings.TrimRight(base, "/")
	switch {
	case strings.HasPrefix(base, "https://"):
		base = "wss://" + strings.TrimPrefix(base, "https://")
	case strings.HasPrefix(base, "http://"):
		base = "ws://" + strings.TrimPrefix(base, "http://")
	}
	return base + "/agent"
}

// relayLoop keeps one outbound control connection to the relay. The relay
// forwards signed terminal.open commands over it so a terminal opens without
// waiting for the next heartbeat; every command is still verified (HMAC,
// age, policy, journal) by the executor, and only terminal.open is accepted
// on this path. Heartbeat delivery remains the fallback.
func (a *agent) relayLoop(ctx context.Context) {
	failures := 0
	for ctx.Err() == nil {
		base := a.relayBase()
		if base == "" {
			sleepCtx(ctx, 5*time.Second)
			continue
		}
		err := a.relaySession(ctx, relayControlURL(base))
		if ctx.Err() != nil {
			return
		}
		failures++
		wait := backoff(failures)
		if err != nil {
			a.log.Printf("relay control connection: %v; retrying in %s", err, wait.Round(time.Millisecond))
		}
		a.sleep(ctx, wait)
	}
}

func (a *agent) relaySession(ctx context.Context, url string) error {
	dctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	conn, err := ws.Dial(dctx, url, &ws.DialOptions{Header: http.Header{"Authorization": {"Bearer " + a.cfg.AgentSecret}}, TLSConfig: a.tls})
	cancel()
	if err != nil {
		return err
	}
	defer conn.Close(ws.CloseNormal, "")
	a.log.Printf("relay control connection established (%s)", url)
	stop := make(chan struct{})
	defer close(stop)
	go func() {
		t := time.NewTicker(25 * time.Second)
		defer t.Stop()
		for {
			select {
			case <-ctx.Done():
				conn.Close(ws.CloseGoingAway, "agent stopping")
				return
			case <-stop:
				return
			case <-t.C:
				if conn.Ping(nil) != nil {
					return
				}
			}
		}
	}()
	for {
		conn.SetReadDeadline(time.Now().Add(90 * time.Second))
		_, data, err := conn.ReadMessage()
		if err != nil {
			return err
		}
		var msg struct {
			Type    string           `json:"type"`
			Command protocol.Command `json:"command"`
		}
		if json.Unmarshal(data, &msg) != nil || msg.Type != "command" {
			continue
		}
		if msg.Command.Type != "terminal.open" {
			a.log.Printf("relay sent %s; only terminal.open is accepted on the relay path", msg.Command.Type)
			continue
		}
		go a.exec.Handle(ctx, msg.Command)
	}
}

// maybeRenewCertificate renews the mTLS client certificate at 2/3 lifetime.
func (a *agent) maybeRenewCertificate(ctx context.Context) {
	if a.certs == nil || !a.certs.NeedsRenewal(time.Now()) {
		return
	}
	if !a.lastRenewTry.IsZero() && time.Since(a.lastRenewTry) < 10*time.Minute {
		return
	}
	a.lastRenewTry = time.Now()
	keyPEM, csrPEM, err := mtls.NewKeyAndCSR(a.cfg.ServerID)
	if err != nil {
		a.log.Printf("certificate renewal: %v", err)
		return
	}
	rctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	resp, err := a.client.RenewCertificate(rctx, string(csrPEM))
	if err != nil {
		a.log.Printf("certificate renewal failed: %v", err)
		return
	}
	if err := a.certs.Save([]byte(resp.Certificate), keyPEM); err != nil {
		a.log.Printf("certificate renewal: %v", err)
		return
	}
	a.log.Printf("client certificate renewed (expires %s)", resp.ExpiresAt)
}
