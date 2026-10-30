package executor

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"strings"
)

// WordPress toolkit (SRS 4.x WP). WP-CLI runs in a short-lived container that
// shares the site container's files (--volumes-from) and network namespace
// (--network container:), so it sees the same wp-config.php and reaches the
// same database as the site itself. It runs as www-data and never through a
// shell; the first word must be an allowed WP-CLI command and options that
// load or run arbitrary code are refused.

const DefaultWPCLIImage = "wordpress:cli"

// wpAllowed are the WP-CLI commands the panel may run.
var wpAllowed = map[string]bool{
	"core": true, "plugin": true, "theme": true, "option": true, "config": true, "cache": true,
	"search-replace": true, "user": true, "cron": true, "maintenance-mode": true, "cli": true,
	"rewrite": true, "transient": true, "language": true, "redis": true, "db": true,
}

// wpRefused are global options that would load or execute code from elsewhere.
var wpRefused = []string{"--exec", "--require", "--ssh", "--http", "--path", "--url=", "--context"}

type wpPayload struct {
	Container string   `json:"container"`
	Args      []string `json:"args"`
	URL       string   `json:"url"`
}

// reWPDump is a restore-point dump inside the site's own content folder.
var reWPDump = regexp.MustCompile(`^wp-content/ws-restore/[A-Za-z0-9-]{1,64}\.sql$`)

func checkWPArgs(args []string) error {
	if len(args) == 0 || !wpAllowed[args[0]] {
		return fmt.Errorf("wp %q is not an allowed command", strings.Join(args[:min(1, len(args))], ""))
	}
	if args[0] == "db" {
		// Only restore-point dumps: export/import of a file in the restore folder.
		if len(args) != 3 || (args[1] != "export" && args[1] != "import") || !reWPDump.MatchString(args[2]) {
			return errors.New("wp db is limited to restore-point export and import")
		}
	}
	for _, a := range args {
		if strings.ContainsRune(a, 0) {
			return errors.New("invalid argument")
		}
		for _, bad := range wpRefused {
			if a == bad || strings.HasPrefix(a, bad+"=") || (strings.HasSuffix(bad, "=") && strings.HasPrefix(a, bad)) {
				return fmt.Errorf("wp option %s is not allowed", bad)
			}
		}
	}
	return nil
}

func (e *Executor) runWP(ctx context.Context, container string, args []string, url string) ([]byte, error) {
	if !reRef.MatchString(container) {
		return nil, errors.New("invalid container name")
	}
	if err := checkWPArgs(args); err != nil {
		return nil, err
	}
	return e.execWP(ctx, container, args, url)
}

// execWP runs WP-CLI without the allow-list; only fixed, agent-built commands use it.
func (e *Executor) execWP(ctx context.Context, container string, args []string, url string) ([]byte, error) {
	if !reRef.MatchString(container) {
		return nil, errors.New("invalid container name")
	}
	image := firstNonEmpty(e.WPCLIImage, DefaultWPCLIImage)
	run := []string{"run", "--rm", "--volumes-from", container, "--network", "container:" + container,
		"--user", "33:33", "-e", "HOME=/tmp", image, "wp", "--path=/var/www/html", "--no-color"}
	if url != "" {
		if strings.ContainsAny(url, " \n") || !(strings.HasPrefix(url, "https://") || strings.HasPrefix(url, "http://")) {
			return nil, errors.New("invalid site URL")
		}
		run = append(run, "--url="+url)
	}
	return e.Run(ctx, "", nil, "docker", append(run, args...)...)
}

func (e *Executor) wpCLI(ctx context.Context, raw json.RawMessage) (any, error) {
	var p wpPayload
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	out, err := e.runWP(ctx, p.Container, p.Args, p.URL)
	text := tailString(strings.TrimSpace(string(out)), 64000)
	if err != nil {
		if text == "" {
			return nil, err
		}
		return map[string]any{"output": text}, fmt.Errorf("wp %s: %s", p.Args[0], tailString(text, 600))
	}
	return map[string]any{"output": text}, nil
}

// wpInventory collects what the toolkit lists for one site in one command.
// A failing probe leaves its field empty rather than failing the inventory.
func (e *Executor) wpInventory(ctx context.Context, raw json.RawMessage) (any, error) {
	var p wpPayload
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	probe := func(args ...string) (string, bool) {
		out, err := e.runWP(ctx, p.Container, args, p.URL)
		return strings.TrimSpace(string(out)), err == nil
	}
	version, ok := probe("core", "version")
	if !ok {
		return nil, fmt.Errorf("WordPress was not found in %s: %s", p.Container, tailString(version, 400))
	}
	inv := map[string]any{"version": version}
	jsonProbe := func(key string, args ...string) {
		if out, ok := probe(args...); ok {
			var v any
			if json.Unmarshal([]byte(out), &v) == nil {
				inv[key] = v
			}
		}
	}
	jsonProbe("cli", "cli", "info", "--format=json")
	jsonProbe("plugins", "plugin", "list", "--fields=name,title,status,version,update,update_version,auto_update", "--format=json")
	jsonProbe("themes", "theme", "list", "--fields=name,title,status,version,update,update_version", "--format=json")
	jsonProbe("core_updates", "core", "check-update", "--format=json")
	for key, args := range map[string][]string{
		"blog_public":  {"option", "get", "blog_public"},
		"siteurl":      {"option", "get", "siteurl"},
		"wp_debug":     {"config", "get", "WP_DEBUG"},
		"auto_core":    {"config", "get", "WP_AUTO_UPDATE_CORE"},
		"disable_cron": {"config", "get", "DISABLE_WP_CRON"},
		"maintenance":  {"maintenance-mode", "is-active"},
	} {
		out, ok := probe(args...)
		if key == "maintenance" {
			inv[key] = ok // is-active exits 0 only when active
			continue
		}
		if ok {
			inv[key] = out
		}
	}
	return inv, nil
}

// rePrefix is a WordPress table prefix the staging copy accepts.
var rePrefix = regexp.MustCompile(`^[a-z][a-z0-9]{0,15}_$`)

type wpTablesPayload struct {
	Container string `json:"container"`
	From      string `json:"from"`
	To        string `json:"to"`
}

// wpCopyTables copies every table with one prefix to another prefix in the same
// database (WP-010 staging and push to live), replacing what the target had, and
// renames the prefix-bound option and user-meta keys. Neither prefix may start
// the other, so a copy can never touch its own source.
func (e *Executor) wpCopyTables(ctx context.Context, raw json.RawMessage) (any, error) {
	var p wpTablesPayload
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if !rePrefix.MatchString(p.From) || !rePrefix.MatchString(p.To) || strings.HasPrefix(p.From, p.To) || strings.HasPrefix(p.To, p.From) {
		return nil, errors.New("invalid or overlapping table prefixes")
	}
	out, err := e.execWP(ctx, p.Container, []string{"db", "query", "SHOW TABLES", "--skip-column-names"}, "")
	if err != nil {
		return nil, fmt.Errorf("listing tables: %s", tailString(strings.TrimSpace(string(out)), 400))
	}
	var src, stale []string
	for _, t := range strings.Fields(string(out)) {
		switch {
		case strings.HasPrefix(t, p.From):
			src = append(src, t)
		case strings.HasPrefix(t, p.To):
			stale = append(stale, t)
		}
	}
	if len(src) == 0 {
		return nil, fmt.Errorf("no tables start with %s", p.From)
	}
	q := func(name string) string { return "`" + strings.ReplaceAll(name, "`", "") + "`" }
	var sql strings.Builder
	sql.WriteString("SET FOREIGN_KEY_CHECKS=0;")
	for _, t := range stale {
		fmt.Fprintf(&sql, "DROP TABLE %s;", q(t))
	}
	for _, t := range src {
		dst := p.To + strings.TrimPrefix(t, p.From)
		fmt.Fprintf(&sql, "CREATE TABLE %s LIKE %s;INSERT INTO %s SELECT * FROM %s;", q(dst), q(t), q(dst), q(t))
	}
	n := len(p.From) + 1
	fmt.Fprintf(&sql, "UPDATE %s SET option_name=CONCAT('%s',SUBSTRING(option_name,%d)) WHERE LEFT(option_name,%d)='%s';", q(p.To+"options"), p.To, n, len(p.From), p.From)
	fmt.Fprintf(&sql, "UPDATE %s SET meta_key=CONCAT('%s',SUBSTRING(meta_key,%d)) WHERE LEFT(meta_key,%d)='%s';", q(p.To+"usermeta"), p.To, n, len(p.From), p.From)
	sql.WriteString("SET FOREIGN_KEY_CHECKS=1;")
	if out, err := e.execWP(ctx, p.Container, []string{"db", "query", sql.String()}, ""); err != nil {
		return nil, fmt.Errorf("copying tables: %s", tailString(strings.TrimSpace(string(out)), 600))
	}
	return map[string]any{"tables": len(src), "dropped": len(stale)}, nil
}

// wpDropTables removes a staging copy's tables. Only prefixes starting with
// "stg" are accepted, so a live site's tables can never be dropped this way.
func (e *Executor) wpDropTables(ctx context.Context, raw json.RawMessage) (any, error) {
	var p wpTablesPayload
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if !rePrefix.MatchString(p.From) || !strings.HasPrefix(p.From, "stg") {
		return nil, errors.New("only staging table prefixes (stg…_) can be dropped")
	}
	out, err := e.execWP(ctx, p.Container, []string{"db", "query", "SHOW TABLES", "--skip-column-names"}, "")
	if err != nil {
		return nil, fmt.Errorf("listing tables: %s", tailString(strings.TrimSpace(string(out)), 400))
	}
	var drop []string
	for _, t := range strings.Fields(string(out)) {
		if strings.HasPrefix(t, p.From) {
			drop = append(drop, "`"+strings.ReplaceAll(t, "`", "")+"`")
		}
	}
	if len(drop) > 0 {
		if out, err := e.execWP(ctx, p.Container, []string{"db", "query", "SET FOREIGN_KEY_CHECKS=0;DROP TABLE " + strings.Join(drop, ",") + ";SET FOREIGN_KEY_CHECKS=1;"}, ""); err != nil {
			return nil, fmt.Errorf("dropping tables: %s", tailString(strings.TrimSpace(string(out)), 400))
		}
	}
	return map[string]any{"dropped": len(drop)}, nil
}
