package main

import (
	"context"
	"log"
	"math/rand/v2"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/livezen-technologies/cloudpanel/agent/internal/config"
	"github.com/livezen-technologies/cloudpanel/agent/internal/docker"
	"github.com/livezen-technologies/cloudpanel/agent/internal/executor"
	"github.com/livezen-technologies/cloudpanel/agent/internal/policy"
	"github.com/livezen-technologies/cloudpanel/agent/internal/protocol"
	"github.com/livezen-technologies/cloudpanel/agent/internal/stats"
	"github.com/livezen-technologies/cloudpanel/agent/internal/update"
)

const maxBackoff = 60 * time.Second

type agent struct {
	cfg     *config.Config
	client  *protocol.Client
	docker  *docker.Client
	sampler *stats.Sampler
	exec    *executor.Executor
	log     *log.Logger
	queue   chan protocol.Command
	sleep   func(context.Context, time.Duration)
}

func newAgent(cfg *config.Config, binary string, logger *log.Logger) (*agent, error) {
	hc, err := protocol.NewHTTPClient(cfg.CAFile)
	if err != nil {
		return nil, err
	}
	dc := docker.New(cfg.DockerSocket)
	client := &protocol.Client{BaseURL: cfg.PanelURL, Secret: cfg.AgentSecret, HTTP: hc}
	up := &update.Updater{BinaryPath: binary}
	keySrc := updatePublicKey
	if cfg.UpdatePublicKey != "" {
		keySrc = cfg.UpdatePublicKey
	}
	if keySrc != "" {
		k, err := update.ParsePublicKey(keySrc)
		if err != nil {
			return nil, err
		}
		up.PublicKey = k
	}
	ex := &executor.Executor{
		Docker:           dc,
		Policy:           &policy.Store{Path: cfg.PolicyPath},
		HMACKey:          []byte(cfg.HMACKey),
		Journal:          &executor.Journal{Path: filepath.Join(cfg.StateDir, "journal.json")},
		Report:           client.ReportResult,
		StateDir:         cfg.StateDir,
		AllowedBindRoots: []string{filepath.Join(cfg.StateDir, "data"), "/srv/dockermgr"},
		AllowLocalGit:    os.Getenv("DOCKERMGR_ALLOW_LOCAL_GIT") == "1",
		Updater:          up,
		Log:              logger,
	}
	return &agent{cfg: cfg, client: client, docker: dc, sampler: &stats.Sampler{}, exec: ex, log: logger,
		queue: make(chan protocol.Command, 64), sleep: sleepCtx}, nil
}

func sleepCtx(ctx context.Context, d time.Duration) {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
	case <-t.C:
	}
}

// backoff returns an exponential delay with jitter in [d/2, d], d = 2^(n-1) s capped at 60 s.
func backoff(n int) time.Duration {
	if n < 1 {
		n = 1
	}
	d := maxBackoff
	if n <= 7 {
		d = min(time.Duration(1<<(n-1))*time.Second, maxBackoff)
	}
	half := d / 2
	return half + time.Duration(rand.Int64N(int64(half)+1))
}

// Run loops until ctx is cancelled. Panel outages only cause backoff: the
// agent never stops, restarts or otherwise touches containers because the
// panel is unreachable.
func (a *agent) Run(ctx context.Context) {
	done := make(chan struct{})
	go func() {
		defer close(done)
		for {
			select {
			case <-ctx.Done():
				return
			case c := <-a.queue:
				a.exec.Handle(ctx, c)
			}
		}
	}()
	failures := 0
	for ctx.Err() == nil {
		var wait time.Duration
		if err := a.beat(ctx); err != nil {
			failures++
			wait = backoff(failures)
			a.log.Printf("heartbeat failed (%d): %v; retrying in %s", failures, err, wait.Round(time.Millisecond))
		} else {
			if failures > 0 {
				a.log.Printf("panel reachable again")
			}
			failures = 0
			wait = time.Duration(a.cfg.HeartbeatInterval) * time.Second
		}
		a.sleep(ctx, wait)
	}
	<-done
}

func (a *agent) beat(ctx context.Context) error {
	req := protocol.HeartbeatRequest{AgentVersion: version, Stats: a.sampler.Sample()}
	ictx, cancel := context.WithTimeout(ctx, 20*time.Second)
	inv, err := stats.CollectInventory(ictx, a.docker)
	cancel()
	if err != nil {
		req.DockerError = err.Error()
	} else {
		req.Containers, req.Images, req.Networks, req.Volumes = inv.Containers, inv.Images, inv.Networks, inv.Volumes
	}
	hctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	resp, err := a.client.Heartbeat(hctx, req)
	if err != nil {
		return err
	}
	a.clearUpdateMarker()
	for _, c := range resp.Commands {
		select {
		case a.queue <- c:
		default:
			a.log.Printf("command queue full; %s will be redelivered", c.ID)
		}
	}
	return nil
}

// checkUpdateMarker counts start attempts after an update; after 3 starts
// without a successful heartbeat it rolls back. Returns true if a rollback
// happened and the caller should re-exec.
func (a *agent) checkUpdateMarker() bool {
	p := a.exec.UpdateMarkerPath()
	b, err := os.ReadFile(p)
	if err != nil {
		return false
	}
	n, _ := strconv.Atoi(strings.TrimSpace(string(b)))
	if n >= 3 {
		a.log.Printf("updated agent failed to reach the panel after %d starts; rolling back", n)
		os.Remove(p)
		if err := a.exec.Updater.Rollback(); err != nil {
			a.log.Printf("rollback failed: %v", err)
			return false
		}
		return true
	}
	os.WriteFile(p, []byte(strconv.Itoa(n+1)), 0o600)
	return false
}

func (a *agent) clearUpdateMarker() {
	if err := os.Remove(a.exec.UpdateMarkerPath()); err == nil {
		a.log.Printf("update confirmed healthy")
	}
}
