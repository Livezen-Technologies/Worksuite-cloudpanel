package executor

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

func TestParseCron(t *testing.T) {
	at := func(s string) time.Time { tm, _ := time.Parse("2006-01-02 15:04", s); return tm }
	for _, c := range []struct {
		expr, when string
		want       bool
	}{
		{"*/15 * * * *", "2026-11-02 10:30", true},
		{"*/15 * * * *", "2026-11-02 10:31", false},
		{"0 9-17 * * 1-5", "2026-11-02 09:00", true},  // Monday
		{"0 9-17 * * 1-5", "2026-11-01 09:00", false}, // Sunday
		{"0 0 * * 7", "2026-11-01 00:00", true},       // 7 is Sunday too
		{"30 2 1,15 * *", "2026-11-15 02:30", true},
		{"0 0 13 * 5", "2026-11-13 00:00", true}, // dom OR dow: the 13th
		{"0 0 13 * 5", "2026-11-06 00:00", true}, // ...or a Friday
		{"0 0 13 * 5", "2026-11-07 00:00", false},
		{"@daily", "2026-11-02 00:00", true},
		{"@hourly", "2026-11-02 10:05", false},
	} {
		s, err := parseCron(c.expr)
		if err != nil {
			t.Fatalf("%s: %v", c.expr, err)
		}
		if got := s.matches(at(c.when)); got != c.want {
			t.Errorf("%s at %s = %v, want %v", c.expr, c.when, got, c.want)
		}
	}
	for _, bad := range []string{"* * * *", "61 * * * *", "*/0 * * * *", "5-1 * * * *", "a * * * *"} {
		if _, err := parseCron(bad); err == nil {
			t.Errorf("%q accepted", bad)
		}
	}
}

func TestCronRunnerRunsOnlyWhileThePanelIsDown(t *testing.T) {
	dir := t.TempDir()
	var mu sync.Mutex
	var ran []string
	e := &Executor{StateDir: dir, Run: func(_ context.Context, _ string, _ []string, name string, args ...string) ([]byte, error) {
		mu.Lock()
		ran = append(ran, name+" "+strings.Join(args, " "))
		mu.Unlock()
		return []byte("backup done\n"), nil
	}}
	sched := `{"jobs":[{"id":"job-1","cron":"*/5 * * * *","timezone":"Asia/Colombo","exec":{"mode":"host","command":"echo hi","timeout":60}},
		{"id":"job-2","cron":"0 3 * * *","timezone":"UTC","exec":{"mode":"host","command":"echo nightly","timeout":60}}]}`
	if r, err := e.cronSchedule([]byte(sched)); err != nil || r.(map[string]any)["jobs"] != 2 {
		t.Fatal(r, err)
	}
	if st, _ := os.Stat(filepath.Join(dir, "cron", "schedule.json")); st.Mode().Perm() != 0o600 {
		t.Fatalf("schedule mode %v", st.Mode())
	}
	// 04:30 UTC is 10:00 in Colombo (+05:30): job-1 is due, job-2 is not.
	now := time.Date(2026, 11, 2, 4, 30, 10, 0, time.UTC)
	e.CronRunnerTick(context.Background(), now, false).Wait()
	if len(ran) != 0 {
		t.Fatal("the panel is reachable: the runner must not run anything")
	}
	e.CronRunnerTick(context.Background(), now, true).Wait()
	e.CronRunnerTick(context.Background(), now.Add(20*time.Second), true).Wait()
	if len(ran) != 1 || ran[0] != "sh -c echo hi" {
		t.Fatalf("want one run of job-1 this minute, got %v", ran)
	}
	out, delivered := e.CronOutbox()
	if len(out) != 1 || out[0].JobID != "job-1" || out[0].ExitCode != 0 || out[0].Output != "backup done\n" || out[0].StartedAt != "2026-11-02T04:30:10Z" {
		t.Fatalf("outbox %+v", out)
	}
	// A run finishing while the heartbeat is in flight stays for the next one.
	e.CronRunnerTick(context.Background(), now.Add(5*time.Minute), true).Wait()
	delivered()
	if out, _ := e.CronOutbox(); len(out) != 1 || out[0].StartedAt != "2026-11-02T04:35:10Z" {
		t.Fatalf("after delivery %+v", out)
	}

	for _, bad := range []string{`{"jobs":[{"id":"x","cron":"nope","exec":{}}]}`, `{"jobs":[{"id":"x","cron":"* * * * *","timezone":"Mars/Base","exec":{}}]}`, `{"jobs":[{"id":"a b","cron":"* * * * *","exec":{}}]}`} {
		if _, err := e.cronSchedule([]byte(bad)); err == nil {
			t.Errorf("%s accepted", bad)
		}
	}
}
