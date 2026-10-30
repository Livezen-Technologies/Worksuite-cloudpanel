// Package executor validates signed commands and executes them against Docker.
// There is deliberately no generic shell: every command type maps to a fixed
// Docker API call or a fixed CLI invocation with validated arguments.
package executor

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"net/http"
	"os/exec"
	"time"

	"github.com/livezen-technologies/cloudpanel/agent/internal/docker"
	"github.com/livezen-technologies/cloudpanel/agent/internal/policy"
	"github.com/livezen-technologies/cloudpanel/agent/internal/protocol"
	"github.com/livezen-technologies/cloudpanel/agent/internal/signing"
	"github.com/livezen-technologies/cloudpanel/agent/internal/update"
)

// MaxAge is the oldest command the agent accepts.
const MaxAge = 10 * time.Minute

// MaxClockSkew tolerates commands issued slightly in the future.
const MaxClockSkew = 2 * time.Minute

// Types is the closed set of supported command types.
var Types = map[string]bool{
	"container.start": true, "container.stop": true, "container.restart": true,
	"container.pause": true, "container.unpause": true, "container.remove": true,
	"container.create": true, "container.logs": true, "container.prune": true,
	"image.pull": true, "image.remove": true, "image.prune": true,
	"network.create": true, "network.remove": true,
	"volume.create": true, "volume.remove": true,
	"compose.up": true, "compose.down": true, "compose.restart": true,
	"backup.run": true, "restore.run": true, "backup.test": true, "backup.export": true,
	"proxy.config": true, "proxy.certs": true,
	"agent.update": true, "agent.restart": true,
	"terminal.open": true,
	"image.build":   true, "image.scan": true, "container.healthcheck": true,
	"firewall.apply": true, "firewall.confirm": true,
	"fs.list": true, "fs.read": true, "fs.write": true, "fs.mkdir": true,
	"fs.rename": true, "fs.delete": true, "fs.upload": true, "fs.download": true,
	"fs.copy": true, "fs.move": true, "fs.compress": true, "fs.extract": true,
	"fs.trash": true, "fs.trash_list": true, "fs.trash_restore": true, "fs.trash_purge": true,
	"fs.chmod": true, "fs.chown": true, "fs.search": true, "fs.scan": true,
	"fs.history_list": true, "fs.history_read": true, "fs.history_restore": true,
	"crowdsec.apply": true, "crowdsec.alerts": true, "fim.baseline": true, "fim.check": true,
	"av.scan": true, "av.quarantine": true, "av.restore": true, "av.delete": true,
}

// Reporter sends a command result to the panel.
type Reporter func(ctx context.Context, id string, r protocol.Result) error

// Runner runs a fixed external program (docker compose, restic). No shell.
type Runner func(ctx context.Context, dir string, env []string, name string, args ...string) ([]byte, error)

// ExecRunner is the real Runner.
func ExecRunner(ctx context.Context, dir string, env []string, name string, args ...string) ([]byte, error) {
	cmd := exec.CommandContext(ctx, name, args...)
	cmd.Dir = dir
	cmd.Env = env
	return cmd.CombinedOutput()
}

// Executor holds dependencies for command handling.
type Executor struct {
	Docker  *docker.Client
	Policy  *policy.Store
	HMACKey []byte
	Journal *Journal
	Report  Reporter
	Run     Runner
	// Stream runs dumps / restic dump with stdin/stdout attached (default ExecStreamer).
	Stream Streamer
	// HTTP uploads exported backups (default: 6 h timeout client).
	HTTP *http.Client
	// ProxyDir is the Traefik file-provider directory; AcmeJSON Traefik's ACME store.
	ProxyDir string
	// CrowdSecDir holds the panel's CrowdSec scenarios and profiles; CrowdSecContainer is the container to check and reload.
	CrowdSecDir       string
	CrowdSecContainer string
	// ClamAVContainer runs clamd with the Docker volumes mounted read-only (default "clamav").
	ClamAVContainer string
	AcmeJSON        string
	LookPath        func(string) (string, error)
	StateDir        string
	// AllowedBindRoots limits host bind mounts in container.create.
	AllowedBindRoots []string
	// AllowLocalGit permits file:// and absolute-path repositories in image.build.
	AllowLocalGit bool
	// HealthHTTP / HealthInterval override container.healthcheck defaults (tests).
	HealthHTTP     *http.Client
	HealthInterval time.Duration
	Updater        *update.Updater
	// VolumeRoot overrides the volume -> host directory lookup (tests).
	VolumeRoot func(ctx context.Context, name string) (string, error)
	// Terminal enables terminal.open (nil = disabled).
	Terminal *TerminalConfig
	// Restart re-execs the agent; called after the result is reported.
	Restart func()
	Now     func() time.Time
	Log     *log.Logger
}

// ErrDuplicate marks an already-seen operation id.
var ErrDuplicate = errors.New("duplicate operation id")

func (e *Executor) now() time.Time {
	if e.Now != nil {
		return e.Now()
	}
	return time.Now()
}

func (e *Executor) logf(f string, a ...any) {
	if e.Log != nil {
		e.Log.Printf(f, a...)
	}
}

// Validate checks type, version, policy, signature and age. It does not
// touch the journal.
func (e *Executor) Validate(c protocol.Command) error {
	if c.ID == "" {
		return errors.New("missing operation id")
	}
	if !Types[c.Type] {
		return fmt.Errorf("unknown command type %q", c.Type)
	}
	if c.Version != 1 {
		return fmt.Errorf("unsupported command version %d", c.Version)
	}
	if c.Signature == "" {
		return errors.New("unsigned command")
	}
	if err := signing.Verify(e.HMACKey, c.ID, c.Type, c.Payload, c.IssuedAt, c.Signature); err != nil {
		return err
	}
	ts, err := time.Parse(time.RFC3339Nano, c.IssuedAt)
	if err != nil {
		return fmt.Errorf("bad issued_at: %w", err)
	}
	now := e.now()
	if now.Sub(ts) > MaxAge {
		return fmt.Errorf("command expired (issued %s)", c.IssuedAt)
	}
	if ts.Sub(now) > MaxClockSkew {
		return fmt.Errorf("command issued in the future (%s)", c.IssuedAt)
	}
	if e.Policy != nil {
		if err := e.Policy.Check(c.Type); err != nil {
			return err
		}
	}
	return nil
}

func (e *Executor) report(ctx context.Context, id string, r protocol.Result) {
	if e.Report == nil {
		return
	}
	rctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 30*time.Second)
	defer cancel()
	for i := 0; i < 5; i++ {
		if err := e.Report(rctx, id, r); err == nil {
			return
		} else {
			e.logf("report %s (%s) failed: %v", id, r.Status, err)
		}
		select {
		case <-rctx.Done():
			return
		case <-time.After(time.Duration(1<<i) * 500 * time.Millisecond):
		}
	}
}

func pct(n int) *int { return &n }

// Handle validates, journals, executes and reports one command. It returns
// the final result (or ErrDuplicate) for logging and tests.
func (e *Executor) Handle(ctx context.Context, c protocol.Command) (protocol.Result, error) {
	if err := e.Validate(c); err != nil {
		e.logf("rejected command %s (%s): %v", c.ID, c.Type, err)
		r := protocol.Result{Status: "failed", Message: "rejected: " + err.Error()}
		if c.ID != "" {
			e.report(ctx, c.ID, r)
		}
		return r, err
	}
	fresh, err := e.Journal.MarkIfNew(c.ID, e.now())
	if err != nil {
		r := protocol.Result{Status: "failed", Message: "journal: " + err.Error()}
		e.report(ctx, c.ID, r)
		return r, err
	}
	if !fresh {
		// Never re-run, and do not overwrite the earlier result.
		e.logf("ignoring duplicate command %s", c.ID)
		return protocol.Result{}, ErrDuplicate
	}
	e.report(ctx, c.ID, protocol.Result{Status: "running", Progress: pct(0)})
	progress := func(p int, msg string) {
		e.report(ctx, c.ID, protocol.Result{Status: "running", Progress: pct(p), Message: msg})
	}
	out, err := e.dispatch(ctx, c, progress)
	var r protocol.Result
	if err != nil {
		r = protocol.Result{Status: "failed", Message: err.Error(), Output: out}
	} else {
		r = protocol.Result{Status: "succeeded", Progress: pct(100), Output: out}
	}
	e.report(ctx, c.ID, r)
	e.logf("command %s (%s): %s %s", c.ID, c.Type, r.Status, r.Message)
	if err == nil && (c.Type == "agent.restart" || c.Type == "agent.update") && e.Restart != nil {
		e.Restart()
	}
	return r, err
}

func decode(raw json.RawMessage, v any) error {
	if len(raw) == 0 || string(raw) == "null" {
		raw = []byte("{}")
	}
	if err := json.Unmarshal(raw, v); err != nil {
		return fmt.Errorf("invalid payload: %w", err)
	}
	return nil
}
