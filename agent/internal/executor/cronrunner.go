package executor

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"
)

// The server's own cron runner (CRON). The panel schedules every run while it is
// reachable; it also sends each agent the schedule of that server's command jobs
// (cron.schedule). When the agent cannot reach the panel for RunnerGrace, it runs
// the due jobs itself through cronExec and keeps the results in an outbox that the
// next successful heartbeat delivers. So jobs keep running while the panel is down,
// and never run twice while it is up.

// RunnerGrace is how long the panel may be unreachable before the agent takes over.
const RunnerGrace = 90 * time.Second

type runnerJob struct {
	ID       string          `json:"id"`
	Cron     string          `json:"cron"`
	Timezone string          `json:"timezone"`
	Exec     json.RawMessage `json:"exec"`
}

type runnerState struct {
	Jobs []runnerJob `json:"jobs"`
	// Last is the minute (unix seconds) each job last ran on its own, so a job runs once a minute at most.
	Last map[string]int64 `json:"last"`
}

// RunnerResult is one run made while the panel was unreachable, sent with a heartbeat.
type RunnerResult struct {
	JobID      string `json:"job_id"`
	ExitCode   int    `json:"exit_code"`
	Output     string `json:"output"`
	StartedAt  string `json:"started_at"`
	FinishedAt string `json:"finished_at"`
	DurationMS int64  `json:"duration_ms"`
	TimedOut   bool   `json:"timed_out,omitempty"`
}

var runnerMu sync.Mutex

func (e *Executor) runnerDir() string { return filepath.Join(e.StateDir, "cron") }

func (e *Executor) cronSchedule(raw []byte) (any, error) {
	var p struct {
		Jobs []runnerJob `json:"jobs"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	for _, j := range p.Jobs {
		if !reRunID.MatchString(j.ID) {
			return nil, fmt.Errorf("invalid job id %q", j.ID)
		}
		if _, err := parseCron(j.Cron); err != nil {
			return nil, fmt.Errorf("job %s: %v", j.ID, err)
		}
		if _, err := time.LoadLocation(firstNonEmpty(j.Timezone, "UTC")); err != nil {
			return nil, fmt.Errorf("job %s: unknown timezone %q", j.ID, j.Timezone)
		}
	}
	runnerMu.Lock()
	defer runnerMu.Unlock()
	st := e.loadRunner()
	st.Jobs = p.Jobs
	if err := e.saveRunner(st); err != nil {
		return nil, err
	}
	return map[string]any{"jobs": len(p.Jobs)}, nil
}

func (e *Executor) loadRunner() runnerState {
	var st runnerState
	if b, err := os.ReadFile(filepath.Join(e.runnerDir(), "schedule.json")); err == nil {
		_ = json.Unmarshal(b, &st)
	}
	if st.Last == nil {
		st.Last = map[string]int64{}
	}
	return st
}

// saveRunner writes 0600: one-off runs carry the app's environment.
func (e *Executor) saveRunner(st runnerState) error {
	if err := os.MkdirAll(e.runnerDir(), 0o700); err != nil {
		return err
	}
	b, _ := json.Marshal(st)
	tmp := filepath.Join(e.runnerDir(), "schedule.json.tmp")
	if err := os.WriteFile(tmp, b, 0o600); err != nil {
		return err
	}
	return os.Rename(tmp, filepath.Join(e.runnerDir(), "schedule.json"))
}

// CronRunnerTick runs the jobs due this minute when the panel has been unreachable
// for RunnerGrace. Call it about every 20 seconds; each job runs once per minute at most.
// Runs start in the background; wait on the returned group in tests.
func (e *Executor) CronRunnerTick(ctx context.Context, now time.Time, panelDown bool) *sync.WaitGroup {
	wg := &sync.WaitGroup{}
	if !panelDown {
		return wg
	}
	runnerMu.Lock()
	defer runnerMu.Unlock()
	st := e.loadRunner()
	changed := false
	for _, j := range st.Jobs {
		spec, err := parseCron(j.Cron)
		if err != nil {
			continue
		}
		loc, err := time.LoadLocation(firstNonEmpty(j.Timezone, "UTC"))
		if err != nil {
			continue
		}
		minute := now.In(loc).Truncate(time.Minute)
		if !spec.matches(minute) || st.Last[j.ID] == minute.Unix() {
			continue
		}
		st.Last[j.ID] = minute.Unix()
		changed = true
		var payload map[string]any
		if json.Unmarshal(j.Exec, &payload) != nil {
			continue
		}
		payload["run_id"] = "runner-" + safeName(j.ID)[:min(8, len(safeName(j.ID)))] + "-" + strconv.FormatInt(minute.Unix(), 10)
		raw, _ := json.Marshal(payload)
		wg.Add(1)
		go func(id string, raw []byte, started time.Time) {
			defer wg.Done()
			res := RunnerResult{JobID: id, StartedAt: started.UTC().Format(time.RFC3339)}
			out, err := e.cronExec(ctx, raw)
			if m, ok := out.(map[string]any); ok && err == nil {
				res.ExitCode, _ = m["exit_code"].(int)
				res.Output, _ = m["output"].(string)
				res.DurationMS, _ = m["duration_ms"].(int64)
				res.TimedOut, _ = m["timed_out"].(bool)
			} else {
				res.ExitCode, res.Output = -1, fmt.Sprint(err)
			}
			res.FinishedAt = time.Now().UTC().Format(time.RFC3339)
			e.appendOutbox(res)
		}(j.ID, raw, now)
	}
	if changed {
		_ = e.saveRunner(st)
	}
	return wg
}

var outboxMu sync.Mutex

func (e *Executor) appendOutbox(r RunnerResult) {
	outboxMu.Lock()
	defer outboxMu.Unlock()
	_ = os.MkdirAll(e.runnerDir(), 0o700)
	f, err := os.OpenFile(filepath.Join(e.runnerDir(), "outbox.jsonl"), os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o600)
	if err != nil {
		return
	}
	defer f.Close()
	b, _ := json.Marshal(r)
	_, _ = f.Write(append(b, '\n'))
}

// CronOutbox returns the runs not yet delivered (at most 500) and a function that
// drops exactly those once the panel has accepted them.
func (e *Executor) CronOutbox() ([]RunnerResult, func()) {
	outboxMu.Lock()
	defer outboxMu.Unlock()
	b, err := os.ReadFile(filepath.Join(e.runnerDir(), "outbox.jsonl"))
	if err != nil || len(b) == 0 {
		return nil, func() {}
	}
	lines := strings.Split(strings.TrimSpace(string(b)), "\n")
	var out []RunnerResult
	for _, l := range lines[:min(500, len(lines))] {
		var r RunnerResult
		if json.Unmarshal([]byte(l), &r) == nil {
			out = append(out, r)
		}
	}
	sent := min(500, len(lines))
	return out, func() {
		outboxMu.Lock()
		defer outboxMu.Unlock()
		cur, err := os.ReadFile(filepath.Join(e.runnerDir(), "outbox.jsonl"))
		if err != nil {
			return
		}
		rest := strings.Split(strings.TrimSpace(string(cur)), "\n")
		if len(rest) <= sent {
			_ = os.Remove(filepath.Join(e.runnerDir(), "outbox.jsonl"))
			return
		}
		_ = os.WriteFile(filepath.Join(e.runnerDir(), "outbox.jsonl"), []byte(strings.Join(rest[sent:], "\n")+"\n"), 0o600)
	}
}

// cronSpec is a parsed five-field cron expression (minute hour day-of-month month day-of-week).
type cronSpec struct {
	f       [5]map[int]bool
	domStar bool
	dowStar bool
}

var cronMacros = map[string]string{"@hourly": "0 * * * *", "@daily": "0 0 * * *", "@midnight": "0 0 * * *", "@weekly": "0 0 * * 0", "@monthly": "0 0 1 * *", "@yearly": "0 0 1 1 *", "@annually": "0 0 1 1 *"}

func parseCron(expr string) (*cronSpec, error) {
	expr = strings.TrimSpace(expr)
	if m, ok := cronMacros[expr]; ok {
		expr = m
	}
	parts := strings.Fields(expr)
	if len(parts) != 5 {
		return nil, errors.New("a cron expression has five fields")
	}
	bounds := [5][2]int{{0, 59}, {0, 23}, {1, 31}, {1, 12}, {0, 7}}
	s := &cronSpec{domStar: parts[2] == "*", dowStar: parts[4] == "*"}
	for i, p := range parts {
		set := map[int]bool{}
		for _, item := range strings.Split(p, ",") {
			rng, step := item, 1
			if a, b, ok := strings.Cut(item, "/"); ok {
				n, err := strconv.Atoi(b)
				if err != nil || n < 1 {
					return nil, fmt.Errorf("bad step in %q", item)
				}
				rng, step = a, n
			}
			lo, hi := bounds[i][0], bounds[i][1]
			if rng != "*" {
				a, b, isRange := strings.Cut(rng, "-")
				x, err := strconv.Atoi(a)
				if err != nil {
					return nil, fmt.Errorf("bad value %q", item)
				}
				lo, hi = x, x
				if isRange {
					if hi, err = strconv.Atoi(b); err != nil {
						return nil, fmt.Errorf("bad range %q", item)
					}
				} else if step > 1 {
					hi = bounds[i][1]
				}
			}
			if lo < bounds[i][0] || hi > bounds[i][1] || lo > hi {
				return nil, fmt.Errorf("%q is out of range", item)
			}
			for v := lo; v <= hi; v += step {
				set[v] = true
			}
		}
		if i == 4 && set[7] {
			set[0] = true // Sunday is 0 or 7
		}
		s.f[i] = set
	}
	return s, nil
}

// matches follows cron: when both day-of-month and day-of-week are restricted, either may match.
func (s *cronSpec) matches(t time.Time) bool {
	if !s.f[0][t.Minute()] || !s.f[1][t.Hour()] || !s.f[3][int(t.Month())] {
		return false
	}
	dom, dow := s.f[2][t.Day()], s.f[4][int(t.Weekday())]
	switch {
	case s.domStar && s.dowStar:
		return true
	case s.domStar:
		return dow
	case s.dowStar:
		return dom
	default:
		return dom || dow
	}
}
