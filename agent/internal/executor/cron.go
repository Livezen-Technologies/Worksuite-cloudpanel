package executor

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os/exec"
	"regexp"
	"sort"
	"sync"
	"sync/atomic"
	"time"
)

// UCRON / CRON-1, CRON-2, CRON-8: a scheduled command, run one of three ways:
//
//	container  docker exec into the app's running container (default)
//	oneoff     a fresh container from an image with the app's env and volumes, removed after
//	host       a shell on the server itself (the panel offers this to admins only)
//
// Container and one-off runs use a numeric non-root user. Each run has an id so
// cron.stop can end it (overlap policy "stop the old run"). Output is kept to the
// last 64 KB.

var (
	reExecUser = regexp.MustCompile(`^[1-9][0-9]{0,9}(:[1-9][0-9]{0,9})?$`)
	reRunID    = regexp.MustCompile(`^[a-zA-Z0-9-]{1,64}$`)
)

type cronMount struct {
	Source string `json:"source"`
	Target string `json:"target"`
}

type cronPayload struct {
	RunID     string            `json:"run_id"`
	Mode      string            `json:"mode"` // container|oneoff|host
	Container string            `json:"container"`
	Image     string            `json:"image"`
	Env       map[string]string `json:"env"`
	Mounts    []cronMount       `json:"mounts"`
	Network   string            `json:"network"`
	Command   string            `json:"command"`
	User      string            `json:"user"`
	Timeout   int               `json:"timeout"` // seconds
}

// cronRuns holds the cancel function of every run in progress, by run id.
type cronRuns struct {
	mu   sync.Mutex
	runs map[string]context.CancelFunc
}

func (c *cronRuns) add(id string, cancel context.CancelFunc) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.runs == nil {
		c.runs = map[string]context.CancelFunc{}
	}
	c.runs[id] = cancel
}

func (c *cronRuns) remove(id string) {
	c.mu.Lock()
	defer c.mu.Unlock()
	delete(c.runs, id)
}

func (c *cronRuns) cancel(id string) bool {
	c.mu.Lock()
	defer c.mu.Unlock()
	if f, ok := c.runs[id]; ok {
		f()
		return true
	}
	return false
}

func cronName(runID string) string { return "ws-cron-" + runID }

func (e *Executor) cronExec(ctx context.Context, raw json.RawMessage) (any, error) {
	var p cronPayload
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if p.Mode == "" {
		p.Mode = "container"
	}
	if p.RunID != "" && !reRunID.MatchString(p.RunID) {
		return nil, errors.New("invalid run id")
	}
	if p.Command == "" || len(p.Command) > 4096 {
		return nil, errors.New("the command must be 1–4096 characters")
	}
	if p.Mode != "host" && !reExecUser.MatchString(p.User) {
		return nil, errors.New("scheduled commands run as a numeric non-root user")
	}
	var args []string
	switch p.Mode {
	case "container":
		if !reRef.MatchString(p.Container) {
			return nil, errors.New("invalid container name")
		}
		script := "exec sh -c \"$1\""
		if p.RunID != "" {
			// The pid file lets cron.stop end the command inside the container.
			script = "echo $$ > /tmp/" + cronName(p.RunID) + ".pid; " + script
		}
		args = []string{"exec", "-u", p.User, p.Container, "sh", "-c", script, "sh", p.Command}
	case "oneoff":
		if !reImage.MatchString(p.Image) {
			return nil, errors.New("invalid image")
		}
		if p.RunID == "" {
			return nil, errors.New("a one-off run needs a run id")
		}
		args = []string{"run", "--rm", "--name", cronName(p.RunID), "--user", p.User, "--security-opt", "no-new-privileges"}
		if p.Network != "" {
			if !reRef.MatchString(p.Network) {
				return nil, errors.New("invalid network")
			}
			args = append(args, "--network", p.Network)
		}
		keys := make([]string, 0, len(p.Env))
		for k := range p.Env {
			if !reEnvKey.MatchString(k) {
				return nil, fmt.Errorf("invalid environment variable %q", k)
			}
			keys = append(keys, k)
		}
		sort.Strings(keys)
		for _, k := range keys {
			args = append(args, "-e", k+"="+p.Env[k])
		}
		for _, m := range p.Mounts {
			if !reRef.MatchString(m.Source) || len(m.Target) == 0 || m.Target[0] != '/' {
				return nil, errors.New("one-off runs mount named volumes only")
			}
			args = append(args, "-v", m.Source+":"+m.Target)
		}
		args = append(args, "--entrypoint", "sh", p.Image, "-c", p.Command)
	case "host":
	default:
		return nil, fmt.Errorf("unknown run mode %q", p.Mode)
	}

	timeout := time.Duration(p.Timeout) * time.Second
	if timeout <= 0 || timeout > 6*time.Hour {
		timeout = 10 * time.Minute
	}
	cctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	var stopped atomic.Bool
	if p.RunID != "" {
		e.cron.add(p.RunID, func() { stopped.Store(true); cancel() })
		defer e.cron.remove(p.RunID)
	}
	started := time.Now()
	var out []byte
	var err error
	if p.Mode == "host" {
		out, err = e.Run(cctx, "", nil, "sh", "-c", p.Command) // nosemgrep: go-shell-with-variable -- host mode is admin-only by design (docs/security-audit.md)
	} else {
		out, err = e.Run(cctx, "", nil, "docker", args...)
	}
	res := map[string]any{"exit_code": 0, "output": tailString(string(out), 64<<10), "duration_ms": time.Since(started).Milliseconds()}
	if err != nil {
		var exit *exec.ExitError
		switch {
		case stopped.Load():
			res["exit_code"] = 143
			res["stopped"] = true
		case errors.Is(cctx.Err(), context.DeadlineExceeded):
			res["exit_code"] = 124
			res["timed_out"] = true
		case errors.As(err, &exit):
			res["exit_code"] = exit.ExitCode()
		default:
			return nil, err
		}
		if stopped.Load() || res["timed_out"] == true {
			e.cronKill(context.WithoutCancel(ctx), p)
		}
	}
	// A failing command is a result, not an agent error: the run history records it.
	return res, nil
}

// cronKill ends what a cancelled docker client leaves behind.
func (e *Executor) cronKill(ctx context.Context, p cronPayload) {
	if p.RunID == "" {
		return
	}
	kctx, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()
	switch p.Mode {
	case "oneoff":
		e.Run(kctx, "", nil, "docker", "rm", "-f", cronName(p.RunID))
	case "container":
		pid := "/tmp/" + cronName(p.RunID) + ".pid"
		e.Run(kctx, "", nil, "docker", "exec", p.Container, "sh", "-c", "kill -TERM $(cat "+pid+") 2>/dev/null; rm -f "+pid)
	}
}

// cronStop ends a run in progress (overlap policy "stop").
func (e *Executor) cronStop(ctx context.Context, raw json.RawMessage) (any, error) {
	var p cronPayload
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if !reRunID.MatchString(p.RunID) {
		return nil, errors.New("invalid run id")
	}
	if p.Mode == "container" && !reRef.MatchString(p.Container) {
		return nil, errors.New("invalid container name")
	}
	if e.cron.cancel(p.RunID) {
		return map[string]any{"stopped": true}, nil
	}
	// Not running here (agent restarted): clean up whatever may be left.
	e.cronKill(ctx, p)
	return map[string]any{"stopped": false}, nil
}
