package executor

// Backups (restic), application-consistent database dumps, integrity checks,
// restore tests, snapshot export, and the Traefik file-provider/ACME helpers.
// Every external program is a fixed binary with validated arguments; there is
// no shell.

import (
	"bufio"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"crypto/x509"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
)

// Streamer runs a fixed program with stdin/stdout attached (dumps, restic dump).
type Streamer func(ctx context.Context, env []string, stdin io.Reader, stdout io.Writer, name string, args ...string) error

// ExecStreamer is the real Streamer; stderr is captured into the error.
func ExecStreamer(ctx context.Context, env []string, stdin io.Reader, stdout io.Writer, name string, args ...string) error {
	cmd := exec.CommandContext(ctx, name, args...)
	cmd.Env = env
	cmd.Stdin = stdin
	cmd.Stdout = stdout
	var stderr bytes.Buffer
	cmd.Stderr = &stderr
	if err := cmd.Run(); err != nil {
		return fmt.Errorf("%s: %w: %s", filepath.Base(name), err, tailString(strings.TrimSpace(stderr.String()), 2048))
	}
	return nil
}

func (e *Executor) stream(ctx context.Context, env []string, stdin io.Reader, stdout io.Writer, name string, args ...string) error {
	s := e.Stream
	if s == nil {
		s = ExecStreamer
	}
	return s(ctx, env, stdin, stdout, name, args...)
}

var resticEnvPrefixes = []string{"RESTIC_", "AWS_", "B2_", "AZURE_", "GOOGLE_", "OS_", "ST_"}

var (
	reContainer  = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$`)
	reDBIdent    = regexp.MustCompile(`^[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,62}$`)
	reSubset     = regexp.MustCompile(`^((100|[0-9]{1,2}(\.[0-9]+)?)%|[0-9]+/[0-9]+|[0-9]+[KMG])$`)
	reYAMLName   = regexp.MustCompile(`^[a-z0-9][a-z0-9._-]{0,120}\.ya?ml$`)
	reSecretName = regexp.MustCompile(`^[A-Z][A-Z0-9_]{0,63}$`)
)

type resticRepo struct {
	Repository string            `json:"repository"`
	Env        map[string]string `json:"env"`
	Init       bool              `json:"init"`
}

// DumpSpec describes an application-consistent database dump taken with
// `docker exec` inside the database container (BAK-002).
type DumpSpec struct {
	Engine    string `json:"engine"` // postgres|mysql|mariadb|redis|mongodb
	Container string `json:"container"`
	Database  string `json:"database"`
	User      string `json:"user"`
	Password  string `json:"password"`
}

type resticCtx struct {
	bin  string
	env  []string
	base []string
}

func (e *Executor) resticSetup(ctx context.Context, p resticRepo) (*resticCtx, error) {
	look := e.LookPath
	if look == nil {
		look = execLookPath
	}
	bin, err := look("restic")
	if err != nil {
		return nil, errors.New("restic is not installed on this server")
	}
	if p.Repository == "" || strings.HasPrefix(p.Repository, "-") || strings.ContainsAny(p.Repository, "\x00\n\r") {
		return nil, errors.New("invalid repository")
	}
	env := baseEnv()
	keys := make([]string, 0, len(p.Env))
	for k := range p.Env {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		ok := false
		for _, pre := range resticEnvPrefixes {
			if strings.HasPrefix(k, pre) {
				ok = true
			}
		}
		if !ok || !reEnvKey.MatchString(k) || strings.ContainsAny(p.Env[k], "\x00\n\r") {
			return nil, fmt.Errorf("env %q not allowed for restic", k)
		}
		env = append(env, k+"="+p.Env[k])
	}
	r := &resticCtx{bin: bin, env: env, base: []string{"--repo=" + p.Repository}}
	if !strings.Contains(p.Repository, ":") {
		if err := os.MkdirAll(filepath.Dir(p.Repository), 0o700); err != nil {
			return nil, err
		}
	}
	if p.Init {
		// "already initialised" is the common case; the real backup reports real errors.
		_, _ = e.run(ctx, "/", env, bin, append(r.base, "init")...)
	}
	return r, nil
}

func checkAbs(s string) error {
	if !filepath.IsAbs(s) || strings.ContainsAny(s, "\x00\n") || strings.Contains(s, "/../") || strings.HasSuffix(s, "/..") {
		return fmt.Errorf("invalid path %q (must be absolute)", s)
	}
	return nil
}

func (d *DumpSpec) validate() error {
	switch d.Engine {
	case "postgres", "mysql", "mariadb", "redis", "mongodb":
	default:
		return fmt.Errorf("unsupported dump engine %q", d.Engine)
	}
	if !reContainer.MatchString(d.Container) {
		return fmt.Errorf("invalid container %q", d.Container)
	}
	for _, v := range []string{d.Database, d.User} {
		if v != "" && !reDBIdent.MatchString(v) {
			return fmt.Errorf("invalid database identifier %q", v)
		}
	}
	if strings.ContainsAny(d.Password, "\x00\n\r") {
		return errors.New("invalid password")
	}
	return nil
}

func (d *DumpSpec) fileName() string {
	switch d.Engine {
	case "postgres":
		return "postgres.dump"
	case "mysql", "mariadb":
		return d.Engine + ".sql"
	case "redis":
		return "dump.rdb"
	default:
		return "mongodb.archive.gz"
	}
}

// dumpDir is stable per container so restic groups snapshots by path and
// retention (forget) works across runs.
func (e *Executor) dumpDir(d *DumpSpec) string {
	return filepath.Join(e.StateDir, "dumps", d.Container)
}

// execArgs builds `docker exec` arguments; passwords travel in the docker CLI
// environment (`-e NAME` without a value), never in argv.
func (d *DumpSpec) execEnv(interactive bool) (args []string, env []string) {
	args = []string{"exec"}
	if interactive {
		args = append(args, "-i")
	}
	env = baseEnv()
	pw := func(name string) {
		if d.Password != "" {
			args = append(args, "-e", name)
			env = append(env, name+"="+d.Password)
		}
	}
	switch d.Engine {
	case "postgres":
		pw("PGPASSWORD")
	case "mysql", "mariadb":
		pw("MYSQL_PWD")
	case "redis":
		pw("REDISCLI_AUTH")
	}
	return append(args, d.Container), env
}

func (d *DumpSpec) user(def string) string { return firstNonEmpty(d.User, def) }

func (d *DumpSpec) mongoAuth() []string {
	if d.User == "" || d.Password == "" {
		return nil
	}
	return []string{"--username=" + d.User, "--password=" + d.Password, "--authenticationDatabase=admin"}
}

// Dump writes the database dump into the stable dump directory.
func (e *Executor) Dump(ctx context.Context, d *DumpSpec) (string, int64, error) {
	if err := d.validate(); err != nil {
		return "", 0, err
	}
	docker, err := e.docker()
	if err != nil {
		return "", 0, err
	}
	dir := e.dumpDir(d)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return "", 0, err
	}
	file := filepath.Join(dir, d.fileName())
	tmp := file + ".partial"
	f, err := os.OpenFile(tmp, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0o600)
	if err != nil {
		return "", 0, err
	}
	defer os.Remove(tmp)
	args, env := d.execEnv(false)
	switch d.Engine {
	case "postgres":
		args = append(args, "pg_dump", "-U", d.user("postgres"), "--format=custom", "--no-owner", firstNonEmpty(d.Database, d.user("postgres")))
	case "mysql":
		args = append(args, "mysqldump", "-u", d.user("root"), "--single-transaction", "--routines", "--events", "--all-databases")
	case "mariadb":
		args = append(args, "mariadb-dump", "-u", d.user("root"), "--single-transaction", "--routines", "--events", "--all-databases")
	case "mongodb":
		args = append(args, "mongodump", "--archive", "--gzip")
		args = append(args, d.mongoAuth()...)
	case "redis":
		if err := e.redisBGSave(ctx, d, docker); err != nil {
			f.Close()
			return "", 0, err
		}
		args = append(args, "cat", "/data/dump.rdb")
	}
	if err := e.stream(ctx, env, nil, f, docker, args...); err != nil {
		f.Close()
		return "", 0, fmt.Errorf("%s dump failed: %w", d.Engine, err)
	}
	if err := f.Sync(); err != nil {
		f.Close()
		return "", 0, err
	}
	st, _ := f.Stat()
	f.Close()
	if st == nil || st.Size() == 0 {
		return "", 0, fmt.Errorf("%s dump is empty", d.Engine)
	}
	if err := os.Rename(tmp, file); err != nil {
		return "", 0, err
	}
	return file, st.Size(), nil
}

// redisBGSave triggers BGSAVE and waits until LASTSAVE moves.
func (e *Executor) redisBGSave(ctx context.Context, d *DumpSpec, docker string) error {
	args, env := d.execEnv(false)
	last := func() string {
		out, _ := e.run(ctx, "/", env, docker, append(append([]string{}, args...), "redis-cli", "LASTSAVE")...)
		return strings.TrimSpace(string(out))
	}
	before := last()
	if out, err := e.run(ctx, "/", env, docker, append(append([]string{}, args...), "redis-cli", "BGSAVE")...); err != nil {
		return fmt.Errorf("redis BGSAVE: %v: %s", err, tailString(string(out), 512))
	}
	for i := 0; i < 120; i++ {
		if now := last(); now != "" && now != before {
			return nil
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(500 * time.Millisecond):
		}
	}
	return errors.New("redis BGSAVE did not finish within 60s")
}

func (e *Executor) docker() (string, error) {
	look := e.LookPath
	if look == nil {
		look = execLookPath
	}
	bin, err := look("docker")
	if err != nil {
		return "", errors.New("docker CLI is not installed on this server")
	}
	return bin, nil
}

type forgetSpec struct {
	KeepLast    int    `json:"keep_last"`
	KeepDaily   int    `json:"keep_daily"`
	KeepWeekly  int    `json:"keep_weekly"`
	KeepMonthly int    `json:"keep_monthly"`
	KeepWithin  string `json:"keep_within"`
	Tag         string `json:"tag"`
}

type checkSpec struct {
	ReadDataSubset string `json:"read_data_subset"`
}

// backupRun: [dump] -> restic backup -> [forget --prune] -> [check].
func (e *Executor) backupRun(ctx context.Context, raw []byte, progress func(int, string)) (any, error) {
	var p struct {
		resticRepo
		Paths   []string    `json:"paths"`
		Tags    []string    `json:"tags"`
		Exclude []string    `json:"exclude"`
		Dump    *DumpSpec   `json:"dump"`
		Forget  *forgetSpec `json:"forget"`
		Check   *checkSpec  `json:"check"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	cctx, cancel := context.WithTimeout(ctx, 6*time.Hour)
	defer cancel()
	out := map[string]any{}
	paths := append([]string{}, p.Paths...)
	if p.Dump != nil {
		progress(5, "dumping "+p.Dump.Engine+" database")
		file, size, err := e.Dump(cctx, p.Dump)
		if err != nil {
			return nil, err
		}
		out["dump"] = map[string]any{"engine": p.Dump.Engine, "file": file, "bytes": size}
		paths = []string{filepath.Dir(file)}
	}
	if len(paths) == 0 {
		return nil, errors.New("paths required")
	}
	r, err := e.resticSetup(cctx, p.resticRepo)
	if err != nil {
		return nil, err
	}
	args := append(append([]string{}, r.base...), "backup", "--json")
	for _, t := range p.Tags {
		if !reSnap.MatchString(t) {
			return nil, fmt.Errorf("invalid tag %q", t)
		}
		args = append(args, "--tag="+t)
	}
	for _, x := range p.Exclude {
		if strings.ContainsAny(x, "\x00\n") {
			return nil, fmt.Errorf("invalid exclude %q", x)
		}
		args = append(args, "--exclude="+x)
	}
	args = append(args, "--")
	for _, s := range paths {
		if err := checkAbs(s); err != nil {
			return nil, err
		}
		args = append(args, s)
	}
	progress(20, "restic backup")
	b, err := e.run(cctx, "/", r.env, r.bin, args...)
	summary := parseResticSummary(b)
	if summary != nil {
		out["summary"] = summary
		out["snapshot_id"] = summary["snapshot_id"]
	}
	if err != nil {
		out["output"] = tailString(string(b), 16<<10)
		return out, fmt.Errorf("restic backup: %w: %s", err, tailString(lastLine(b), 512))
	}
	if summary == nil || summary["snapshot_id"] == nil {
		out["output"] = tailString(string(b), 16<<10)
		return out, errors.New("restic backup finished without a snapshot id")
	}
	if p.Forget != nil {
		progress(70, "applying retention")
		fa, err := forgetArgs(p.Forget)
		if err != nil {
			return out, err
		}
		fb, ferr := e.run(cctx, "/", r.env, r.bin, append(append(append([]string{}, r.base...), "forget", "--prune", "--json"), fa...)...)
		if ferr != nil {
			return out, fmt.Errorf("restic forget: %w: %s", ferr, tailString(lastLine(fb), 512))
		}
		out["forget"] = map[string]any{"removed": countForgetRemoved(fb)}
	}
	if p.Check != nil {
		progress(85, "integrity check")
		out["check"] = e.resticCheck(cctx, r, p.Check)
	}
	return out, nil
}

func (e *Executor) resticCheck(ctx context.Context, r *resticCtx, c *checkSpec) map[string]any {
	subset := firstNonEmpty(c.ReadDataSubset, "5%")
	if !reSubset.MatchString(subset) {
		return map[string]any{"ok": false, "subset": subset, "message": "invalid read_data_subset"}
	}
	b, err := e.run(ctx, "/", r.env, r.bin, append(append([]string{}, r.base...), "check", "--read-data-subset="+subset)...)
	res := map[string]any{"ok": err == nil, "subset": subset}
	if err != nil {
		res["message"] = tailString(strings.TrimSpace(string(b)), 2048)
	}
	return res
}

func forgetArgs(f *forgetSpec) ([]string, error) {
	var a []string
	add := func(flag string, n int) {
		if n > 0 {
			a = append(a, flag+"="+strconv.Itoa(n))
		}
	}
	add("--keep-last", f.KeepLast)
	add("--keep-daily", f.KeepDaily)
	add("--keep-weekly", f.KeepWeekly)
	add("--keep-monthly", f.KeepMonthly)
	if f.KeepWithin != "" {
		if !regexp.MustCompile(`^([0-9]+[ymdh])+$`).MatchString(f.KeepWithin) {
			return nil, fmt.Errorf("invalid keep_within %q", f.KeepWithin)
		}
		a = append(a, "--keep-within="+f.KeepWithin)
	}
	if len(a) == 0 {
		return nil, errors.New("forget needs at least one keep rule")
	}
	if f.Tag != "" {
		if !reSnap.MatchString(f.Tag) {
			return nil, fmt.Errorf("invalid tag %q", f.Tag)
		}
		a = append(a, "--tag="+f.Tag)
	}
	return a, nil
}

func lastLine(b []byte) string {
	s := strings.TrimSpace(string(b))
	if i := strings.LastIndexByte(s, '\n'); i >= 0 {
		return s[i+1:]
	}
	return s
}

// parseResticSummary picks the {"message_type":"summary"} line of `restic backup --json`.
func parseResticSummary(b []byte) map[string]any {
	var found map[string]any
	sc := bufio.NewScanner(bytes.NewReader(b))
	sc.Buffer(make([]byte, 64<<10), 4<<20)
	for sc.Scan() {
		line := sc.Bytes()
		if !bytes.Contains(line, []byte(`"summary"`)) {
			continue
		}
		var m map[string]any
		if json.Unmarshal(line, &m) == nil && m["message_type"] == "summary" {
			found = m
		}
	}
	return found
}

func countForgetRemoved(b []byte) int {
	var groups []struct {
		Remove []any `json:"remove"`
	}
	n := 0
	for _, line := range strings.Split(string(b), "\n") {
		if json.Unmarshal([]byte(line), &groups) == nil {
			for _, g := range groups {
				n += len(g.Remove)
			}
		}
	}
	return n
}

// restoreRun restores files in place, or restores a dump and loads it into the DB container.
func (e *Executor) restoreRun(ctx context.Context, id string, raw []byte, progress func(int, string)) (any, error) {
	var p struct {
		resticRepo
		Snapshot string    `json:"snapshot"`
		Target   string    `json:"target"`
		Include  []string  `json:"include"`
		Dump     *DumpSpec `json:"dump"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	snap := firstNonEmpty(p.Snapshot, "latest")
	if !reSnap.MatchString(snap) {
		return nil, fmt.Errorf("invalid snapshot %q", snap)
	}
	cctx, cancel := context.WithTimeout(ctx, 6*time.Hour)
	defer cancel()
	r, err := e.resticSetup(cctx, p.resticRepo)
	if err != nil {
		return nil, err
	}
	if p.Dump != nil {
		if err := p.Dump.validate(); err != nil {
			return nil, err
		}
		tmp, err := e.scratch("restore", id)
		if err != nil {
			return nil, err
		}
		defer os.RemoveAll(tmp)
		src := e.dumpDir(p.Dump)
		progress(10, "restoring dump from snapshot")
		if b, err := e.run(cctx, "/", r.env, r.bin, append(append([]string{}, r.base...), "restore", "--target="+tmp, "--include="+src, snap)...); err != nil {
			return nil, fmt.Errorf("restic restore: %w: %s", err, tailString(lastLine(b), 512))
		}
		file := filepath.Join(tmp, src, p.Dump.fileName())
		progress(50, "loading dump into "+p.Dump.Container)
		if err := e.loadDump(cctx, p.Dump, file); err != nil {
			return nil, err
		}
		st, _ := os.Stat(file)
		var size int64
		if st != nil {
			size = st.Size()
		}
		return map[string]any{"snapshot": snap, "engine": p.Dump.Engine, "bytes": size}, nil
	}
	if err := checkAbs(p.Target); err != nil {
		return nil, err
	}
	args := append(append([]string{}, r.base...), "restore", "--target="+p.Target, snap)
	for _, inc := range p.Include {
		if err := checkAbs(inc); err != nil {
			return nil, err
		}
		args = append(args, "--include="+inc)
	}
	b, err := e.run(cctx, "/", r.env, r.bin, args...)
	o := map[string]any{"snapshot": snap, "output": tailString(string(b), 16<<10)}
	if err != nil {
		return o, fmt.Errorf("restic restore: %w", err)
	}
	return o, nil
}

func (e *Executor) loadDump(ctx context.Context, d *DumpSpec, file string) error {
	docker, err := e.docker()
	if err != nil {
		return err
	}
	f, err := os.Open(file)
	if err != nil {
		return fmt.Errorf("dump not found in snapshot: %w", err)
	}
	defer f.Close()
	args, env := d.execEnv(true)
	switch d.Engine {
	case "postgres":
		args = append(args, "pg_restore", "-U", d.user("postgres"), "--clean", "--if-exists", "--no-owner", "-d", firstNonEmpty(d.Database, d.user("postgres")))
	case "mysql":
		args = append(args, "mysql", "-u", d.user("root"))
	case "mariadb":
		args = append(args, "mariadb", "-u", d.user("root"))
	case "mongodb":
		args = append(args, "mongorestore", "--archive", "--gzip", "--drop")
		args = append(args, d.mongoAuth()...)
	case "redis":
		// Redis loads dump.rdb at start: stop, copy, start (AOF, if enabled, wins).
		env := baseEnv()
		for _, step := range [][]string{{"stop", d.Container}, {"cp", file, d.Container + ":/data/dump.rdb"}, {"start", d.Container}} {
			if b, err := e.run(ctx, "/", env, docker, step...); err != nil {
				return fmt.Errorf("redis restore (%s): %v: %s", step[0], err, tailString(string(b), 512))
			}
		}
		return nil
	}
	var sink bytes.Buffer
	if err := e.stream(ctx, env, f, &sink, docker, args...); err != nil {
		return fmt.Errorf("%s restore failed: %w", d.Engine, err)
	}
	return nil
}

func (e *Executor) scratch(kind, id string) (string, error) {
	base := filepath.Join(e.StateDir, "tmp")
	if err := os.MkdirAll(base, 0o700); err != nil {
		return "", err
	}
	return os.MkdirTemp(base, kind+"-"+safeName(id)+"-")
}

func safeName(s string) string {
	return regexp.MustCompile(`[^a-zA-Z0-9_-]`).ReplaceAllString(s, "")
}

// backupTest is the scheduled restore test (BAK-005): restore the snapshot
// into a scratch directory, validate it, run an integrity check, clean up.
func (e *Executor) backupTest(ctx context.Context, id string, raw []byte, progress func(int, string)) (any, error) {
	var p struct {
		resticRepo
		Snapshot string     `json:"snapshot"`
		Engine   string     `json:"engine"`
		Check    *checkSpec `json:"check"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	snap := firstNonEmpty(p.Snapshot, "latest")
	if !reSnap.MatchString(snap) {
		return nil, fmt.Errorf("invalid snapshot %q", snap)
	}
	cctx, cancel := context.WithTimeout(ctx, 2*time.Hour)
	defer cancel()
	r, err := e.resticSetup(cctx, p.resticRepo)
	if err != nil {
		return nil, err
	}
	tmp, err := e.scratch("test", id)
	if err != nil {
		return nil, err
	}
	defer os.RemoveAll(tmp)
	progress(10, "test restore")
	if b, err := e.run(cctx, "/", r.env, r.bin, append(append([]string{}, r.base...), "restore", "--target="+tmp, snap)...); err != nil {
		return nil, fmt.Errorf("test restore failed: %w: %s", err, tailString(lastLine(b), 512))
	}
	var files, size int64
	var dumpOK *bool
	_ = filepath.Walk(tmp, func(path string, info os.FileInfo, err error) error {
		if err != nil || !info.Mode().IsRegular() {
			return nil
		}
		files++
		size += info.Size()
		if p.Engine != "" {
			if ok, matched := validDump(p.Engine, path); matched {
				dumpOK = &ok
			}
		}
		return nil
	})
	out := map[string]any{"snapshot": snap, "files": files, "bytes": size}
	if p.Engine != "" {
		ok := dumpOK != nil && *dumpOK
		out["dump_valid"] = ok
		if !ok {
			return out, fmt.Errorf("restored %s dump is missing or invalid", p.Engine)
		}
	}
	if files == 0 {
		return out, errors.New("test restore produced no files")
	}
	if p.Check != nil {
		progress(70, "integrity check")
		c := e.resticCheck(cctx, r, p.Check)
		out["check"] = c
		if c["ok"] != true {
			return out, errors.New("restic integrity check failed")
		}
	}
	return out, nil
}

// validDump reports (valid, isDumpFile) by magic bytes.
func validDump(engine, path string) (bool, bool) {
	d := DumpSpec{Engine: engine}
	if filepath.Base(path) != d.fileName() {
		return false, false
	}
	f, err := os.Open(path)
	if err != nil {
		return false, true
	}
	defer f.Close()
	head := make([]byte, 4096)
	n, _ := io.ReadFull(f, head)
	head = head[:n]
	switch engine {
	case "postgres":
		return bytes.HasPrefix(head, []byte("PGDMP")), true
	case "mysql", "mariadb":
		return bytes.Contains(head, []byte("dump")) || bytes.Contains(head, []byte("CREATE")), true
	case "redis":
		return bytes.HasPrefix(head, []byte("REDIS")), true
	case "mongodb":
		return len(head) > 2 && head[0] == 0x1f && head[1] == 0x8b, true
	}
	return false, true
}

// backupExport writes `restic dump --archive tar` of a snapshot as tar.gz and
// uploads it to a presigned URL (S3 PUT) or the panel's signed upload URL.
func (e *Executor) backupExport(ctx context.Context, id string, raw []byte, progress func(int, string)) (any, error) {
	var p struct {
		resticRepo
		Snapshot string `json:"snapshot"`
		Path     string `json:"path"`
		Upload   struct {
			URL     string            `json:"url"`
			Method  string            `json:"method"`
			Headers map[string]string `json:"headers"`
		} `json:"upload"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	snap := firstNonEmpty(p.Snapshot, "latest")
	if !reSnap.MatchString(snap) {
		return nil, fmt.Errorf("invalid snapshot %q", snap)
	}
	root := firstNonEmpty(p.Path, "/")
	if err := checkAbs(root); err != nil {
		return nil, err
	}
	u, err := url.Parse(p.Upload.URL)
	if err != nil || (u.Scheme != "https" && u.Scheme != "http") || u.Host == "" {
		return nil, errors.New("invalid upload url")
	}
	method := strings.ToUpper(firstNonEmpty(p.Upload.Method, "PUT"))
	if method != "PUT" && method != "POST" {
		return nil, fmt.Errorf("invalid upload method %q", method)
	}
	cctx, cancel := context.WithTimeout(ctx, 6*time.Hour)
	defer cancel()
	r, err := e.resticSetup(cctx, p.resticRepo)
	if err != nil {
		return nil, err
	}
	tmp, err := e.scratch("export", id)
	if err != nil {
		return nil, err
	}
	defer os.RemoveAll(tmp)
	file := filepath.Join(tmp, "backup.tar.gz")
	f, err := os.OpenFile(file, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return nil, err
	}
	h := sha256.New()
	gz := gzip.NewWriter(io.MultiWriter(f, h))
	progress(10, "exporting snapshot "+snap)
	err = e.stream(cctx, r.env, nil, gz, r.bin, append(append([]string{}, r.base...), "dump", "--archive=tar", snap, root)...)
	if cerr := gz.Close(); err == nil {
		err = cerr
	}
	if cerr := f.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return nil, fmt.Errorf("restic dump: %w", err)
	}
	st, err := os.Stat(file)
	if err != nil {
		return nil, err
	}
	sum := hex.EncodeToString(h.Sum(nil))
	progress(60, "uploading "+strconv.FormatInt(st.Size(), 10)+" bytes")
	body, err := os.Open(file)
	if err != nil {
		return nil, err
	}
	defer body.Close()
	req, err := http.NewRequestWithContext(cctx, method, p.Upload.URL, body)
	if err != nil {
		return nil, err
	}
	req.ContentLength = st.Size()
	req.Header.Set("Content-Type", "application/gzip")
	for k, v := range p.Upload.Headers {
		if strings.ContainsAny(k+v, "\r\n") {
			return nil, errors.New("invalid upload header")
		}
		req.Header.Set(k, v)
	}
	req.Header.Set("X-Content-SHA256", sum)
	client := e.HTTP
	if client == nil {
		client = &http.Client{Timeout: 6 * time.Hour}
	}
	resp, err := client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("upload: %w", err)
	}
	defer resp.Body.Close()
	rb, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		return nil, fmt.Errorf("upload: HTTP %d: %s", resp.StatusCode, tailString(string(rb), 512))
	}
	return map[string]any{"snapshot": snap, "bytes": st.Size(), "sha256": sum, "format": "tar.gz"}, nil
}

// ---- Traefik (file provider + ACME status) ----

const (
	DefaultProxyDir = "/etc/dockermgr/traefik/dynamic"
	DefaultAcmeJSON = "/etc/dockermgr/traefik/acme.json"
)

// proxyConfig writes Traefik dynamic configuration files (file provider).
func (e *Executor) proxyConfig(raw []byte) (any, error) {
	var p struct {
		Files       map[string]string `json:"files"`
		PrunePrefix string            `json:"prune_prefix"`
		// Secrets are DNS-01 provider credentials, written as <dir>/../secrets/<NAME> (0600)
		// for Traefik's NAME_FILE environment variables.
		Secrets map[string]string `json:"secrets"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	dir := firstNonEmpty(e.ProxyDir, DefaultProxyDir)
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return nil, err
	}
	names := make([]string, 0, len(p.Files))
	for n, content := range p.Files {
		if !reYAMLName.MatchString(n) {
			return nil, fmt.Errorf("invalid file name %q", n)
		}
		if len(content) > 1<<20 {
			return nil, fmt.Errorf("%s too large", n)
		}
		names = append(names, n)
	}
	sort.Strings(names)
	for _, n := range names {
		if err := writeAtomic(filepath.Join(dir, n), []byte(p.Files[n]), 0o644); err != nil {
			return nil, err
		}
	}
	removed := []string{}
	if p.PrunePrefix != "" {
		if !reSnap.MatchString(p.PrunePrefix) {
			return nil, errors.New("invalid prune_prefix")
		}
		entries, _ := os.ReadDir(dir)
		for _, ent := range entries {
			n := ent.Name()
			if _, keep := p.Files[n]; keep || !strings.HasPrefix(n, p.PrunePrefix) || !reYAMLName.MatchString(n) {
				continue
			}
			if os.Remove(filepath.Join(dir, n)) == nil {
				removed = append(removed, n)
			}
		}
	}
	secretNames := []string{}
	if len(p.Secrets) > 0 {
		sdir := filepath.Join(filepath.Dir(dir), "secrets")
		if err := os.MkdirAll(sdir, 0o700); err != nil {
			return nil, err
		}
		for k, v := range p.Secrets {
			if !reSecretName.MatchString(k) || strings.ContainsAny(v, "\x00\n\r") {
				return nil, fmt.Errorf("invalid secret %q", k)
			}
			if err := writeAtomic(filepath.Join(sdir, k), []byte(v), 0o600); err != nil {
				return nil, err
			}
			secretNames = append(secretNames, k)
		}
		sort.Strings(secretNames)
	}
	return map[string]any{"dir": dir, "written": names, "removed": removed, "secrets": secretNames}, nil
}

// ProxyCerts reads Traefik's acme.json and returns names and expiry only;
// private keys are never read into the result.
func (e *Executor) ProxyCerts() (any, error) {
	path := firstNonEmpty(e.AcmeJSON, DefaultAcmeJSON)
	b, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return map[string]any{"certificates": []any{}, "acme_json": path, "missing": true}, nil
	}
	if err != nil {
		return nil, err
	}
	var store map[string]struct {
		Certificates []struct {
			Domain struct {
				Main string   `json:"main"`
				SANs []string `json:"sans"`
			} `json:"domain"`
			Certificate string `json:"certificate"`
		} `json:"Certificates"`
	}
	if err := json.Unmarshal(b, &store); err != nil {
		return nil, fmt.Errorf("parse %s: %w", path, err)
	}
	certs := []map[string]any{}
	resolvers := make([]string, 0, len(store))
	for k := range store {
		resolvers = append(resolvers, k)
	}
	sort.Strings(resolvers)
	for _, res := range resolvers {
		for _, c := range store[res].Certificates {
			item := map[string]any{"resolver": res, "domain": c.Domain.Main, "sans": nonNil(c.Domain.SANs)}
			if pemBytes, err := base64.StdEncoding.DecodeString(c.Certificate); err == nil {
				if blk, _ := pem.Decode(pemBytes); blk != nil {
					if x, err := x509.ParseCertificate(blk.Bytes); err == nil {
						item["not_after"] = x.NotAfter.UTC().Format(time.RFC3339)
						item["not_before"] = x.NotBefore.UTC().Format(time.RFC3339)
						item["issuer"] = x.Issuer.CommonName
					}
				}
			}
			certs = append(certs, item)
		}
	}
	return map[string]any{"certificates": certs, "acme_json": path}, nil
}

func nonNil(s []string) []string {
	if s == nil {
		return []string{}
	}
	return s
}
