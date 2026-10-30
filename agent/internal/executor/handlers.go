package executor

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"time"

	"github.com/livezen-technologies/cloudpanel/agent/internal/docker"
	"github.com/livezen-technologies/cloudpanel/agent/internal/protocol"
)

var (
	reRef     = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$`)
	reProject = regexp.MustCompile(`^[a-z0-9][a-z0-9_-]{0,62}$`)
	reImage   = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9._/:@+-]{0,254}$`)
	reEnvKey  = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]{0,127}$`)
	reSnap    = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9:_.-]{0,127}$`)
)

func checkRef(kind, v string) error {
	if !reRef.MatchString(v) {
		return fmt.Errorf("invalid %s %q", kind, v)
	}
	return nil
}

func checkImage(v string) error {
	if !reImage.MatchString(v) {
		return fmt.Errorf("invalid image reference %q", v)
	}
	return nil
}

type containerRef struct {
	ContainerID string `json:"container_id"`
	Timeout     int    `json:"timeout"`
	Force       bool   `json:"force"`
	KeepVolumes *bool  `json:"keep_volumes"`
	Tail        int    `json:"tail"`
	Timestamps  bool   `json:"timestamps"`
}

func (e *Executor) dispatch(ctx context.Context, c protocol.Command, progress func(int, string)) (any, error) {
	d := e.Docker
	switch c.Type {
	case "container.start", "container.stop", "container.restart", "container.pause",
		"container.unpause", "container.remove", "container.logs":
		var p containerRef
		if err := decode(c.Payload, &p); err != nil {
			return nil, err
		}
		if err := checkRef("container_id", p.ContainerID); err != nil {
			return nil, err
		}
		switch c.Type {
		case "container.start":
			return nil, d.StartContainer(ctx, p.ContainerID)
		case "container.stop":
			return nil, d.StopContainer(ctx, p.ContainerID, p.Timeout)
		case "container.restart":
			return nil, d.RestartContainer(ctx, p.ContainerID, p.Timeout)
		case "container.pause":
			return nil, d.PauseContainer(ctx, p.ContainerID)
		case "container.unpause":
			return nil, d.UnpauseContainer(ctx, p.ContainerID)
		case "container.remove":
			keep := p.KeepVolumes == nil || *p.KeepVolumes // CTR-004: keep by default
			return nil, d.RemoveContainer(ctx, p.ContainerID, p.Force, !keep)
		case "container.logs":
			tail := p.Tail
			if tail <= 0 || tail > 10000 {
				tail = 200
			}
			s, err := d.Logs(ctx, p.ContainerID, tail, p.Timestamps)
			if err != nil {
				return nil, err
			}
			return map[string]any{"logs": s}, nil
		}
	case "fs.list", "fs.read", "fs.write", "fs.mkdir", "fs.rename", "fs.delete":
		return e.files(ctx, c.Type, c.Payload)
	case "fs.upload", "fs.download":
		return e.transfer(ctx, c.Type, c.Payload)
	case "fs.copy", "fs.move", "fs.compress", "fs.extract", "fs.trash", "fs.trash_list", "fs.trash_restore", "fs.trash_purge":
		return e.fileOps(ctx, c.Type, c.Payload)
	case "fs.chmod", "fs.chown":
		return e.perms(ctx, c.Type, c.Payload)
	case "fs.search":
		return e.search(ctx, c.Payload)
	case "firewall.apply":
		return e.applyFirewall(ctx, c.Payload, progress)
	case "firewall.confirm":
		return e.confirmFirewall()
	case "image.build":
		return e.buildImage(ctx, c.Payload, progress)
	case "image.scan":
		return e.scanImage(ctx, c.Payload, progress)
	case "container.healthcheck":
		return e.healthCheck(ctx, c.Payload, progress)
	case "container.create":
		return e.createContainer(ctx, c.Payload, progress)
	case "container.prune":
		var p struct {
			Until  string            `json:"until"`
			Labels map[string]string `json:"labels"`
		}
		if err := decode(c.Payload, &p); err != nil {
			return nil, err
		}
		return d.PruneContainers(ctx, pruneFilters(p.Until, p.Labels, nil))
	case "image.pull":
		var p struct {
			Image        string               `json:"image"`
			Tag          string               `json:"tag"`
			RegistryAuth *docker.RegistryAuth `json:"registry_auth"`
		}
		if err := decode(c.Payload, &p); err != nil {
			return nil, err
		}
		if err := checkImage(p.Image); err != nil {
			return nil, err
		}
		img, tag := splitTag(p.Image, p.Tag)
		err := d.PullImage(ctx, img, tag, p.RegistryAuth, func(s string) { progress(50, s) })
		return map[string]any{"image": img + ":" + tag}, err
	case "image.remove":
		var p struct {
			Image string `json:"image"`
			Force bool   `json:"force"`
		}
		if err := decode(c.Payload, &p); err != nil {
			return nil, err
		}
		if err := checkImage(p.Image); err != nil {
			return nil, err
		}
		return d.RemoveImage(ctx, p.Image, p.Force)
	case "image.prune":
		var p struct {
			All    bool              `json:"all"` // false: dangling only
			Until  string            `json:"until"`
			Labels map[string]string `json:"labels"`
		}
		if err := decode(c.Payload, &p); err != nil {
			return nil, err
		}
		dangling := "true"
		if p.All {
			dangling = "false"
		}
		return d.PruneImages(ctx, pruneFilters(p.Until, p.Labels, map[string][]string{"dangling": {dangling}}))
	case "network.create":
		return e.createNetwork(ctx, c.Payload)
	case "network.remove":
		var p struct {
			NetworkID string `json:"network_id"`
			Name      string `json:"name"`
		}
		if err := decode(c.Payload, &p); err != nil {
			return nil, err
		}
		id := firstNonEmpty(p.NetworkID, p.Name)
		if err := checkRef("network", id); err != nil {
			return nil, err
		}
		if id == "bridge" || id == "host" || id == "none" {
			return nil, fmt.Errorf("refusing to remove built-in network %s", id)
		}
		return nil, d.RemoveNetwork(ctx, id)
	case "volume.create":
		var p struct {
			Name       string            `json:"name"`
			Driver     string            `json:"driver"`
			DriverOpts map[string]string `json:"driver_opts"`
			Labels     map[string]string `json:"labels"`
		}
		if err := decode(c.Payload, &p); err != nil {
			return nil, err
		}
		if err := checkRef("volume name", p.Name); err != nil {
			return nil, err
		}
		body := map[string]any{"Name": p.Name, "Driver": firstNonEmpty(p.Driver, "local"), "Labels": managedLabels(p.Labels)}
		if len(p.DriverOpts) > 0 {
			body["DriverOpts"] = p.DriverOpts
		}
		return d.CreateVolume(ctx, body)
	case "volume.remove":
		var p struct {
			Name  string `json:"name"`
			Force bool   `json:"force"`
		}
		if err := decode(c.Payload, &p); err != nil {
			return nil, err
		}
		if err := checkRef("volume name", p.Name); err != nil {
			return nil, err
		}
		return nil, d.RemoveVolume(ctx, p.Name, p.Force)
	case "compose.up", "compose.down", "compose.restart":
		return e.compose(ctx, c.Type, c.Payload)
	case "backup.run":
		return e.backupRun(ctx, c.Payload, progress)
	case "restore.run":
		return e.restoreRun(ctx, c.ID, c.Payload, progress)
	case "backup.test":
		return e.backupTest(ctx, c.ID, c.Payload, progress)
	case "backup.export":
		return e.backupExport(ctx, c.ID, c.Payload, progress)
	case "proxy.config":
		return e.proxyConfig(c.Payload)
	case "proxy.certs":
		return e.ProxyCerts()
	case "agent.update":
		var p struct {
			URL       string `json:"url"`
			Signature string `json:"signature"`
			SHA256    string `json:"sha256"`
			Version   string `json:"version"`
		}
		if err := decode(c.Payload, &p); err != nil {
			return nil, err
		}
		if e.Updater == nil {
			return nil, errors.New("updater not configured")
		}
		progress(10, "downloading "+p.Version)
		if err := e.Updater.Apply(ctx, p.URL, p.Signature, p.SHA256); err != nil {
			return nil, err
		}
		if err := e.markUpdatePending(); err != nil {
			return nil, err
		}
		return map[string]any{"version": p.Version, "restarting": true}, nil
	case "terminal.open":
		return e.openTerminal(ctx, c.Payload)
	case "agent.restart":
		if e.Restart == nil {
			return nil, errors.New("restart not supported")
		}
		return map[string]any{"restarting": true}, nil
	}
	return nil, fmt.Errorf("unhandled command type %s", c.Type)
}

// UpdateMarkerPath records an update awaiting its first successful heartbeat.
func (e *Executor) UpdateMarkerPath() string { return filepath.Join(e.StateDir, "update-pending") }

func (e *Executor) markUpdatePending() error {
	if err := os.MkdirAll(e.StateDir, 0o700); err != nil {
		return err
	}
	return os.WriteFile(e.UpdateMarkerPath(), []byte("0"), 0o600)
}

func firstNonEmpty(v ...string) string {
	for _, s := range v {
		if s != "" {
			return s
		}
	}
	return ""
}

func splitTag(image, tag string) (string, string) {
	if tag != "" {
		return image, tag
	}
	if strings.Contains(image, "@") {
		i := strings.Index(image, "@")
		return image[:i], image[i+1:]
	}
	if i := strings.LastIndex(image, ":"); i > strings.LastIndex(image, "/") {
		return image[:i], image[i+1:]
	}
	return image, "latest"
}

func pruneFilters(until string, labels map[string]string, extra map[string][]string) map[string][]string {
	f := map[string][]string{}
	for k, v := range extra {
		f[k] = v
	}
	if until != "" {
		f["until"] = []string{until}
	}
	keys := make([]string, 0, len(labels))
	for k := range labels {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		f["label"] = append(f["label"], k+"="+labels[k])
	}
	return f
}

// ManagedLabel marks resources created by the agent.
const ManagedLabel = "io.dockermgr.managed"

func managedLabels(in map[string]string) map[string]string {
	out := map[string]string{ManagedLabel: "true"}
	for k, v := range in {
		out[k] = v
	}
	return out
}

func (e *Executor) createNetwork(ctx context.Context, raw []byte) (any, error) {
	var p struct {
		Name       string            `json:"name"`
		Driver     string            `json:"driver"`
		Internal   bool              `json:"internal"`
		Attachable bool              `json:"attachable"`
		Subnet     string            `json:"subnet"`
		Gateway    string            `json:"gateway"`
		Labels     map[string]string `json:"labels"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := checkRef("network name", p.Name); err != nil {
		return nil, err
	}
	drv := firstNonEmpty(p.Driver, "bridge")
	if drv != "bridge" && drv != "overlay" && drv != "macvlan" && drv != "ipvlan" {
		return nil, fmt.Errorf("unsupported network driver %q", drv)
	}
	body := map[string]any{
		"Name": p.Name, "Driver": drv, "Internal": p.Internal, "Attachable": p.Attachable,
		"CheckDuplicate": true, "Labels": managedLabels(p.Labels),
	}
	if p.Subnet != "" {
		cfg := map[string]string{"Subnet": p.Subnet}
		if p.Gateway != "" {
			cfg["Gateway"] = p.Gateway
		}
		body["IPAM"] = map[string]any{"Driver": "default", "Config": []any{cfg}}
	}
	id, err := e.Docker.CreateNetwork(ctx, body)
	return map[string]any{"network_id": id}, err
}

// ---- compose ----

const maxComposeSize = 1 << 20

func (e *Executor) compose(ctx context.Context, typ string, raw []byte) (any, error) {
	var p struct {
		Project       string            `json:"project"`
		ComposeYAML   string            `json:"compose_yaml"`
		Env           map[string]string `json:"env"`
		RemoveVolumes bool              `json:"remove_volumes"`
		Pull          bool              `json:"pull"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if !reProject.MatchString(p.Project) {
		return nil, fmt.Errorf("invalid compose project name %q", p.Project)
	}
	dir := filepath.Join(e.StateDir, "compose", p.Project)
	file := filepath.Join(dir, "compose.yaml")
	if typ == "compose.up" {
		if strings.TrimSpace(p.ComposeYAML) == "" || len(p.ComposeYAML) > maxComposeSize {
			return nil, errors.New("compose_yaml missing or too large")
		}
		if err := os.MkdirAll(dir, 0o700); err != nil {
			return nil, err
		}
		if err := writeAtomic(file, []byte(p.ComposeYAML), 0o600); err != nil {
			return nil, err
		}
		var env strings.Builder
		keys := make([]string, 0, len(p.Env))
		for k := range p.Env {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		for _, k := range keys {
			v := p.Env[k]
			if !reEnvKey.MatchString(k) || strings.ContainsAny(v, "\n\r") {
				return nil, fmt.Errorf("invalid env entry %q", k)
			}
			fmt.Fprintf(&env, "%s=%s\n", k, v)
		}
		if err := writeAtomic(filepath.Join(dir, ".env"), []byte(env.String()), 0o600); err != nil {
			return nil, err
		}
	} else if _, err := os.Stat(file); err != nil {
		return nil, fmt.Errorf("compose project %s not found on this server", p.Project)
	}
	args := []string{"compose", "--project-name", p.Project, "--project-directory", dir, "--file", file}
	switch typ {
	case "compose.up":
		if p.Pull {
			args = append(args, "up", "--detach", "--remove-orphans", "--pull", "always")
		} else {
			args = append(args, "up", "--detach", "--remove-orphans")
		}
	case "compose.down":
		args = append(args, "down", "--remove-orphans")
		if p.RemoveVolumes {
			args = append(args, "--volumes")
		}
	case "compose.restart":
		args = append(args, "restart")
	}
	cctx, cancel := context.WithTimeout(ctx, 30*time.Minute)
	defer cancel()
	out, err := e.run(cctx, dir, baseEnv(), "docker", args...)
	o := map[string]any{"output": tailString(string(out), 64<<10)}
	if err != nil {
		return o, fmt.Errorf("docker compose %s: %w", strings.TrimPrefix(typ, "compose."), err)
	}
	return o, nil
}

func (e *Executor) run(ctx context.Context, dir string, env []string, name string, args ...string) ([]byte, error) {
	r := e.Run
	if r == nil {
		r = ExecRunner
	}
	return r(ctx, dir, env, name, args...)
}

func baseEnv() []string {
	return []string{"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin", "HOME=/root", "LANG=C.UTF-8"}
}

func tailString(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[len(s)-n:]
}

func writeAtomic(p string, b []byte, mode os.FileMode) error {
	tmp := p + ".tmp"
	if err := os.WriteFile(tmp, b, mode); err != nil {
		return err
	}
	return os.Rename(tmp, p)
}
