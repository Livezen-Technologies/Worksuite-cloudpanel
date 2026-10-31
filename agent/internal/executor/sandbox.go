package executor

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

// SandboxSpec (BKM-009) starts what a restore test restored, in a throwaway
// container with no network: "db" loads the dump into a fresh database of the
// same engine and counts rows per table; "app" runs the app's image on the
// restored files and checks it is still running after Seconds.
type SandboxSpec struct {
	Kind    string `json:"kind"`
	Image   string `json:"image"`
	Source  string `json:"source"` // app: the backed-up path, as stored in the snapshot
	Mount   string `json:"mount"`  // app: where the app expects it
	Seconds int    `json:"seconds"`
}

// sandboxPoll is how often a starting sandbox is checked (tests shorten it).
var sandboxPoll = 2 * time.Second

func (s *SandboxSpec) validate(engine string) error {
	if checkImage(s.Image) != nil {
		return fmt.Errorf("invalid sandbox image %q", s.Image)
	}
	switch s.Kind {
	case "db":
		if engine != "postgres" && engine != "mysql" && engine != "mariadb" {
			return fmt.Errorf("a database sandbox needs a postgres, mysql or mariadb dump, not %q", engine)
		}
	case "app":
		if !filepath.IsAbs(s.Source) || !filepath.IsAbs(s.Mount) || strings.Contains(s.Source+s.Mount, "..") {
			return errors.New("an app sandbox needs absolute source and mount paths")
		}
	default:
		return fmt.Errorf("invalid sandbox kind %q", s.Kind)
	}
	if s.Seconds <= 0 {
		s.Seconds = 20
	}
	if s.Seconds > 600 {
		return errors.New("sandbox seconds must be at most 600")
	}
	return nil
}

func (e *Executor) sandbox(ctx context.Context, id string, s *SandboxSpec, engine, restored string, progress func(int, string)) (map[string]any, error) {
	bin, err := e.docker()
	if err != nil {
		return nil, err
	}
	name := "ws-sandbox-" + safeName(id)
	_, _ = e.run(ctx, "/", baseEnv(), bin, "rm", "-f", name)
	defer e.run(context.Background(), "/", baseEnv(), bin, "rm", "-f", "-v", name)
	args := []string{"run", "-d", "--name", name, "--network", "none", "--memory", "1g", "--label", "io.dockermgr.sandbox=" + safeName(id)}
	if s.Kind == "app" {
		src := filepath.Join(restored, s.Source)
		if !within(restored, src) {
			return nil, errors.New("sandbox source escapes the restore")
		}
		if _, err := os.Stat(src); err != nil {
			return nil, fmt.Errorf("the backup has no %s to start the app from", s.Source)
		}
		args = append(args, "-v", src+":"+s.Mount, s.Image)
		progress(80, "starting the app from the restored files")
		if o, err := e.run(ctx, "/", baseEnv(), bin, args...); err != nil {
			return nil, fmt.Errorf("sandbox did not start: %v: %s", err, tailString(string(o), 512))
		}
		if err := sleepCtx(ctx, time.Duration(s.Seconds)*time.Second); err != nil {
			return nil, err
		}
		o, _ := e.run(ctx, "/", baseEnv(), bin, "inspect", "-f", "{{.State.Running}} {{.State.ExitCode}} {{.RestartCount}}", name)
		f := strings.Fields(string(o))
		out := map[string]any{"kind": "app", "image": s.Image, "seconds": s.Seconds}
		if len(f) < 3 || f[0] != "true" || f[2] != "0" {
			logs, _ := e.run(ctx, "/", baseEnv(), bin, "logs", "--tail", "20", name)
			out["logs"] = tailString(string(logs), 4096)
			return out, fmt.Errorf("the app did not stay up on the restored data (%s)", strings.TrimSpace(string(o)))
		}
		out["running"] = true
		return out, nil
	}

	// Database: a fresh server of the same engine, the dump loaded, rows counted.
	dump := filepath.Join(restored, "x")
	_ = filepath.Walk(restored, func(p string, info os.FileInfo, err error) error {
		if err == nil && info.Mode().IsRegular() && filepath.Base(p) == (&DumpSpec{Engine: engine}).fileName() {
			dump = p
		}
		return nil
	})
	const pw = "sandbox"
	ready, load, count := []string{"pg_isready", "-U", "postgres"}, []string{"pg_restore", "-U", "postgres", "--no-owner", "-d", "postgres"},
		[]string{"psql", "-U", "postgres", "-d", "postgres", "-At", "-c", `SELECT format('%s.%s=%s', schemaname, tablename, (xpath('/row/c/text()', query_to_xml(format('select count(*) as c from %I.%I', schemaname, tablename), false, true, '')))[1]::text) FROM pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema') ORDER BY 1`}
	args = append(args, "-e", "POSTGRES_PASSWORD="+pw)
	if engine != "postgres" {
		args[len(args)-1] = "MYSQL_ROOT_PASSWORD=" + pw
		ready = []string{"mysqladmin", "ping", "-uroot", "-p" + pw, "--silent"}
		load = []string{"mysql", "-uroot", "-p" + pw}
		count = []string{"mysql", "-uroot", "-p" + pw, "-N", "-B", "-e", "SELECT CONCAT(table_schema,'.',table_name,'=',table_rows) FROM information_schema.tables WHERE table_type='BASE TABLE' AND table_schema NOT IN ('mysql','sys','performance_schema','information_schema') ORDER BY 1"}
	}
	progress(75, "starting a "+engine+" sandbox")
	if o, err := e.run(ctx, "/", baseEnv(), bin, append(args, s.Image)...); err != nil {
		return nil, fmt.Errorf("sandbox did not start: %v: %s", err, tailString(string(o), 512))
	}
	deadline := time.Now().Add(time.Duration(s.Seconds+90) * time.Second)
	for {
		if _, err := e.run(ctx, "/", baseEnv(), bin, append([]string{"exec", name}, ready...)...); err == nil {
			break
		}
		if time.Now().After(deadline) {
			return nil, errors.New("the sandbox database did not become ready")
		}
		if err := sleepCtx(ctx, sandboxPoll); err != nil {
			return nil, err
		}
	}
	progress(85, "loading the dump into the sandbox")
	f, err := os.Open(dump)
	if err != nil {
		return nil, fmt.Errorf("no %s dump in the backup", engine)
	}
	defer f.Close()
	var stderr bytes.Buffer
	if err := e.stream(ctx, baseEnv(), f, &stderr, bin, append([]string{"exec", "-i", name}, load...)...); err != nil {
		return nil, fmt.Errorf("the dump did not load: %v: %s", err, tailString(stderr.String(), 512))
	}
	progress(95, "counting rows")
	o, err := e.run(ctx, "/", baseEnv(), bin, append([]string{"exec", name}, count...)...)
	if err != nil {
		return nil, fmt.Errorf("counting rows failed: %v: %s", err, tailString(string(o), 512))
	}
	tables := map[string]int64{}
	var rows int64
	for _, line := range strings.Split(strings.TrimSpace(string(o)), "\n") {
		k, v, ok := strings.Cut(strings.TrimSpace(line), "=")
		if !ok {
			continue
		}
		n, _ := strconv.ParseInt(v, 10, 64)
		tables[k] = n
		rows += n
	}
	out := map[string]any{"kind": "db", "image": s.Image, "tables": len(tables), "rows": rows, "counts": tables}
	if len(tables) == 0 {
		return out, errors.New("the restored database has no tables")
	}
	return out, nil
}

func sleepCtx(ctx context.Context, d time.Duration) error {
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-time.After(d):
		return nil
	}
}
