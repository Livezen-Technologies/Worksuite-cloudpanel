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
