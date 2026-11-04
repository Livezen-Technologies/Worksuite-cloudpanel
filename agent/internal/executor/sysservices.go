package executor

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

// System services (SVC-1..5): the host's systemd services, listed with their
// health, started / stopped / enabled, set up from a fixed catalogue of
// packages, and custom services the panel creates (always named ws-*.service).
// Nothing here takes a shell: every program and argument is fixed or checked.

var (
	reUnit     = regexp.MustCompile(`^[a-zA-Z0-9@._-]{1,120}\.service$`)
	reWsUnit   = regexp.MustCompile(`^ws-[a-z0-9][a-z0-9-]{0,40}\.service$`)
	reUnixUser = regexp.MustCompile(`^[a-z_][a-z0-9_-]{0,31}$`)
)

// SvcCatalog maps a setup key to its Debian/Ubuntu packages and the unit it starts.
var SvcCatalog = map[string]struct {
	Packages []string
	Unit     string
}{
	"nginx":      {[]string{"nginx"}, "nginx.service"},
	"apache":     {[]string{"apache2"}, "apache2.service"},
	"mysql":      {[]string{"mysql-server"}, "mysql.service"},
	"mariadb":    {[]string{"mariadb-server"}, "mariadb.service"},
	"postgresql": {[]string{"postgresql"}, "postgresql.service"},
	"redis":      {[]string{"redis-server"}, "redis-server.service"},
	"memcached":  {[]string{"memcached"}, "memcached.service"},
	"php-fpm":    {[]string{"php-fpm"}, "php-fpm.service"},
	"supervisor": {[]string{"supervisor"}, "supervisor.service"},
	"fail2ban":   {[]string{"fail2ban"}, "fail2ban.service"},
	"chrony":     {[]string{"chrony"}, "chrony.service"},
	"cron":       {[]string{"cron"}, "cron.service"},
}

// Units the panel must never stop or disable: doing so would cut it off from the server.
var svcProtected = map[string]bool{"dockermgr-agent.service": true, "ssh.service": true, "sshd.service": true, "docker.service": true, "containerd.service": true, "systemd-journald.service": true, "dbus.service": true, "systemd-networkd.service": true, "systemd-resolved.service": true}

var svcActions = map[string]bool{"start": true, "stop": true, "restart": true, "reload": true, "enable": true, "disable": true}

func (e *Executor) unitDir() string { return firstNonEmpty(e.UnitDir, "/etc/systemd/system") }

func (e *Executor) systemServices(ctx context.Context, typ string, raw json.RawMessage, progress func(int, string)) (any, error) {
	switch typ {
	case "svc.list":
		return e.svcList(ctx)
	case "svc.logs":
		var p struct {
			Unit  string `json:"unit"`
			Lines int    `json:"lines"`
		}
		if err := decode(raw, &p); err != nil {
			return nil, err
		}
		if !reUnit.MatchString(p.Unit) {
			return nil, fmt.Errorf("invalid unit %q", p.Unit)
		}
		if p.Lines <= 0 || p.Lines > 1000 {
			p.Lines = 200
		}
		out, err := e.Run(ctx, "", nil, "journalctl", "-u", p.Unit, "-n", strconv.Itoa(p.Lines), "--no-pager", "-o", "short-iso")
		if err != nil {
			return nil, fmt.Errorf("journalctl: %s", strings.TrimSpace(string(out)))
		}
		return map[string]any{"unit": p.Unit, "log": string(out)}, nil
	case "svc.action":
		var p struct {
			Unit   string `json:"unit"`
			Action string `json:"action"`
		}
		if err := decode(raw, &p); err != nil {
			return nil, err
		}
		if !reUnit.MatchString(p.Unit) || !svcActions[p.Action] {
			return nil, fmt.Errorf("invalid unit or action")
		}
		if svcProtected[p.Unit] && (p.Action == "stop" || p.Action == "disable") {
			return nil, fmt.Errorf("%s is needed to manage this server and cannot be %sd from the panel", p.Unit, strings.TrimSuffix(p.Action, "e"))
		}
		if out, err := e.Run(ctx, "", nil, "systemctl", p.Action, p.Unit); err != nil {
			return nil, fmt.Errorf("systemctl %s %s: %s", p.Action, p.Unit, strings.TrimSpace(string(out)))
		}
		return e.svcState(ctx, p.Unit)
	case "svc.install":
		var p struct {
			Key string `json:"key"`
		}
		if err := decode(raw, &p); err != nil {
			return nil, err
		}
		c, ok := SvcCatalog[p.Key]
		if !ok {
			return nil, fmt.Errorf("unknown service %q", p.Key)
		}
		env := append(os.Environ(), "DEBIAN_FRONTEND=noninteractive")
		if _, err := e.lookPath("apt-get"); err != nil {
			return nil, errors.New("setup needs apt-get (Debian or Ubuntu)")
		}
		progress(10, "apt-get update")
		if out, err := e.Run(ctx, "", env, "apt-get", "update", "-q"); err != nil {
			return nil, fmt.Errorf("apt-get update: %s", tail(out))
		}
		progress(40, "installing "+strings.Join(c.Packages, " "))
		args := append([]string{"install", "-y", "-q", "--no-install-recommends"}, c.Packages...)
		if out, err := e.Run(ctx, "", env, "apt-get", args...); err != nil {
			return nil, fmt.Errorf("apt-get install: %s", tail(out))
		}
		unit := c.Unit
		if p.Key == "php-fpm" { // the unit carries the PHP version
			if u := e.findUnit(ctx, "php*-fpm.service"); u != "" {
				unit = u
			}
		}
		progress(90, "starting "+unit)
		if out, err := e.Run(ctx, "", nil, "systemctl", "enable", "--now", unit); err != nil {
			return nil, fmt.Errorf("systemctl enable %s: %s", unit, tail(out))
		}
		return e.svcState(ctx, unit)
	case "svc.create":
		return e.svcCreate(ctx, raw)
	case "svc.delete":
		var p struct {
			Unit string `json:"unit"`
		}
		if err := decode(raw, &p); err != nil {
			return nil, err
		}
		if !reWsUnit.MatchString(p.Unit) {
			return nil, errors.New("only services created in the panel (ws-*) can be deleted")
		}
		_, _ = e.Run(ctx, "", nil, "systemctl", "disable", "--now", p.Unit)
		if err := os.Remove(filepath.Join(e.unitDir(), p.Unit)); err != nil && !os.IsNotExist(err) {
			return nil, err
		}
		_, _ = e.Run(ctx, "", nil, "systemctl", "daemon-reload")
		return map[string]any{"unit": p.Unit, "deleted": true}, nil
	}
	return nil, fmt.Errorf("unknown command %s", typ)
}

func (e *Executor) lookPath(name string) (string, error) {
	if e.LookPath != nil {
		return e.LookPath(name)
	}
	return execLookPath(name)
}

func tail(b []byte) string {
	s := strings.TrimSpace(string(b))
	if len(s) > 600 {
		s = "…" + s[len(s)-600:]
	}
	return s
}

func (e *Executor) findUnit(ctx context.Context, pattern string) string {
	out, err := e.Run(ctx, "", nil, "systemctl", "list-unit-files", pattern, "--no-legend", "--plain", "--no-pager")
	if err != nil {
		return ""
	}
	for _, l := range strings.Split(string(out), "\n") {
		if f := strings.Fields(l); len(f) > 0 && reUnit.MatchString(f[0]) {
			return f[0]
		}
	}
	return ""
}

type svcCreate struct {
	Name        string            `json:"name"`
	Description string            `json:"description"`
	ExecStart   string            `json:"exec_start"`
	WorkingDir  string            `json:"working_dir"`
	User        string            `json:"user"`
	Restart     string            `json:"restart"`
	Env         map[string]string `json:"env"`
	Start       bool              `json:"start"`
}

// RenderUnit writes the unit text for a panel-created service.
func RenderUnit(p svcCreate) (string, error) {
	unit := "ws-" + p.Name + ".service"
	if !reWsUnit.MatchString(unit) {
		return "", fmt.Errorf("invalid name %q: lower-case letters, digits and dashes", p.Name)
	}
	if !strings.HasPrefix(p.ExecStart, "/") || strings.ContainsAny(p.ExecStart, "\n\r") {
		return "", errors.New("the command must start with an absolute path, e.g. /usr/bin/node")
	}
	if p.WorkingDir != "" && (!filepath.IsAbs(p.WorkingDir) || strings.ContainsAny(p.WorkingDir, "\n\r")) {
		return "", errors.New("the working directory must be an absolute path")
	}
	if p.User == "" {
		p.User = "www-data"
	}
	if !reUnixUser.MatchString(p.User) || p.User == "root" {
		return "", errors.New("run as a non-root user")
	}
	if p.Restart == "" {
		p.Restart = "on-failure"
	}
	if p.Restart != "always" && p.Restart != "on-failure" && p.Restart != "no" {
		return "", errors.New("restart must be always, on-failure or no")
	}
	desc := strings.Map(func(r rune) rune {
		if r == '\n' || r == '\r' {
			return ' '
		}
		return r
	}, firstNonEmpty(p.Description, "Service "+p.Name+" (WorkSuite)"))
	var b strings.Builder
	fmt.Fprintf(&b, "# Created by the WorkSuite panel.\n[Unit]\nDescription=%s\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nType=simple\nUser=%s\nExecStart=%s\nRestart=%s\nRestartSec=5\nNoNewPrivileges=true\n", desc, p.User, p.ExecStart, p.Restart)
	if p.WorkingDir != "" {
		fmt.Fprintf(&b, "WorkingDirectory=%s\n", p.WorkingDir)
	}
	keys := make([]string, 0, len(p.Env))
	for k := range p.Env {
		if !reEnvKey.MatchString(k) || strings.ContainsAny(p.Env[k], "\n\r\"\\") {
			return "", fmt.Errorf("invalid environment variable %q", k)
		}
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		fmt.Fprintf(&b, "Environment=\"%s=%s\"\n", k, p.Env[k])
	}
	b.WriteString("\n[Install]\nWantedBy=multi-user.target\n")
	return b.String(), nil
}

func (e *Executor) svcCreate(ctx context.Context, raw json.RawMessage) (any, error) {
	var p svcCreate
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	text, err := RenderUnit(p)
	if err != nil {
		return nil, err
	}
	unit := "ws-" + p.Name + ".service"
	if err := os.MkdirAll(e.unitDir(), 0o755); err != nil {
		return nil, err
	}
	if err := os.WriteFile(filepath.Join(e.unitDir(), unit), []byte(text), 0o644); err != nil {
		return nil, err
	}
	if out, err := e.Run(ctx, "", nil, "systemctl", "daemon-reload"); err != nil {
		return nil, fmt.Errorf("daemon-reload: %s", tail(out))
	}
	verb := []string{"enable", unit}
	if p.Start {
		verb = []string{"enable", "--now", unit}
	}
	if out, err := e.Run(ctx, "", nil, "systemctl", verb...); err != nil {
		return nil, fmt.Errorf("systemctl enable: %s", tail(out))
	}
	return e.svcState(ctx, unit)
}

var svcProps = "Id,Description,LoadState,ActiveState,SubState,UnitFileState,MainPID,MemoryCurrent,NRestarts,ActiveEnterTimestamp,FragmentPath"

// svcList returns every loaded service with its state, memory and restarts.
func (e *Executor) svcList(ctx context.Context) (any, error) {
	out, err := e.Run(ctx, "", nil, "systemctl", "list-units", "--type=service", "--all", "--no-legend", "--plain", "--no-pager")
	if err != nil {
		return nil, fmt.Errorf("systemctl list-units: %s", tail(out))
	}
	var units []string
	for _, l := range strings.Split(string(out), "\n") {
		f := strings.Fields(strings.TrimLeft(l, "● "))
		if len(f) >= 4 && reUnit.MatchString(f[0]) && f[1] != "not-found" {
			units = append(units, f[0])
		}
	}
	// Panel-created units that are not loaded yet still belong in the list.
	if m, _ := filepath.Glob(filepath.Join(e.unitDir(), "ws-*.service")); len(m) > 0 {
		seen := map[string]bool{}
		for _, u := range units {
			seen[u] = true
		}
		for _, f := range m {
			if b := filepath.Base(f); !seen[b] && reWsUnit.MatchString(b) {
				units = append(units, b)
			}
		}
	}
	sort.Strings(units)
	if len(units) > 400 {
		units = units[:400]
	}
	list := []map[string]any{}
	for i := 0; i < len(units); i += 50 {
		j := min(i+50, len(units))
		rows, err := e.svcShow(ctx, units[i:j]...)
		if err != nil {
			return nil, err
		}
		list = append(list, rows...)
	}
	return map[string]any{"services": list}, nil
}

func (e *Executor) svcState(ctx context.Context, unit string) (any, error) {
	rows, err := e.svcShow(ctx, unit)
	if err != nil || len(rows) == 0 {
		return map[string]any{"unit": unit}, err
	}
	return rows[0], nil
}

func (e *Executor) svcShow(ctx context.Context, units ...string) ([]map[string]any, error) {
	out, err := e.Run(ctx, "", nil, "systemctl", append([]string{"show", "--no-pager", "-p", svcProps}, units...)...)
	if err != nil {
		return nil, fmt.Errorf("systemctl show: %s", tail(out))
	}
	return ParseSystemctlShow(string(out)), nil
}

// ParseSystemctlShow turns `systemctl show` blocks (blank-line separated) into rows.
func ParseSystemctlShow(s string) []map[string]any {
	var rows []map[string]any
	for _, block := range strings.Split(strings.ReplaceAll(s, "\r", ""), "\n\n") {
		kv := map[string]string{}
		for _, l := range strings.Split(block, "\n") {
			if k, v, ok := strings.Cut(l, "="); ok {
				kv[k] = v
			}
		}
		if kv["Id"] == "" {
			continue
		}
		mem := int64(-1)
		if n, err := strconv.ParseUint(kv["MemoryCurrent"], 10, 64); err == nil && n < 1<<62 {
			mem = int64(n)
		}
		pid, _ := strconv.Atoi(kv["MainPID"])
		restarts, _ := strconv.Atoi(kv["NRestarts"])
		row := map[string]any{
			"unit": kv["Id"], "description": kv["Description"], "load": kv["LoadState"], "active": kv["ActiveState"], "sub": kv["SubState"],
			"enabled": kv["UnitFileState"], "pid": pid, "restarts": restarts, "since": kv["ActiveEnterTimestamp"],
			"custom": reWsUnit.MatchString(kv["Id"]), "protected": svcProtected[kv["Id"]],
		}
		if mem >= 0 {
			row["memory_bytes"] = mem
		}
		rows = append(rows, row)
	}
	return rows
}
