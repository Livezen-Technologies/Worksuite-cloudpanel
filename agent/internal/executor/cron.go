package executor

import (
	"context"
	"encoding/json"
	"errors"
	"os/exec"
	"regexp"
	"time"
)

// UCRON / CRON-8: a scheduled command inside an app's own container, as a
// non-root user, with a timeout. Output is kept to the last 64 KB.

var reExecUser = regexp.MustCompile(`^[1-9][0-9]{0,9}(:[1-9][0-9]{0,9})?$`)

type cronPayload struct {
	Container string `json:"container"`
	Command   string `json:"command"`
	User      string `json:"user"`
	Timeout   int    `json:"timeout"` // seconds
}

func (e *Executor) cronExec(ctx context.Context, raw json.RawMessage) (any, error) {
	var p cronPayload
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if !reRef.MatchString(p.Container) {
		return nil, errors.New("invalid container name")
	}
	if !reExecUser.MatchString(p.User) {
		return nil, errors.New("scheduled commands run as a numeric non-root user")
	}
	if p.Command == "" || len(p.Command) > 4096 {
		return nil, errors.New("the command must be 1–4096 characters")
	}
	timeout := time.Duration(p.Timeout) * time.Second
	if timeout <= 0 || timeout > time.Hour {
		timeout = 10 * time.Minute
	}
	cctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	started := time.Now()
	out, err := e.Run(cctx, "", nil, "docker", "exec", "-u", p.User, p.Container, "sh", "-c", p.Command)
	res := map[string]any{"exit_code": 0, "output": tailString(string(out), 64<<10), "duration_ms": time.Since(started).Milliseconds()}
	if err != nil {
		var exit *exec.ExitError
		switch {
		case errors.Is(cctx.Err(), context.DeadlineExceeded):
			res["exit_code"] = 124
			res["timed_out"] = true
		case errors.As(err, &exit):
			res["exit_code"] = exit.ExitCode()
		default:
			return nil, err
		}
	}
	// A failing command is a result, not an agent error: the run history records it.
	return res, nil
}
