package executor

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/livezen-technologies/cloudpanel/agent/internal/docker"
)

// CreateSpec is the container.create payload.
type CreateSpec struct {
	Name           string               `json:"name"`
	Image          string               `json:"image"`
	RegistryAuth   *docker.RegistryAuth `json:"registry_auth"`
	Cmd            []string             `json:"cmd"`
	Entrypoint     []string             `json:"entrypoint"`
	Env            json.RawMessage      `json:"env"` // {"K":"V"} or ["K=V"]
	User           string               `json:"user"`
	WorkingDir     string               `json:"working_dir"`
	Ports          []PortSpec           `json:"ports"`
	Networks       []NetworkSpec        `json:"networks"`
	Mounts         []MountSpec          `json:"mounts"`
	ReadOnlyRootfs bool                 `json:"read_only_rootfs"`
	Tmpfs          []string             `json:"tmpfs"`
	MemoryMB       int64                `json:"memory_mb"`
	CPUs           float64              `json:"cpus"`
	PidsLimit      int64                `json:"pids_limit"`
	RestartPolicy  string               `json:"restart_policy"`
	MaxRetries     int                  `json:"max_retries"`
	Healthcheck    *HealthSpec          `json:"healthcheck"`
	Labels         map[string]string    `json:"labels"`
	CapAdd         []string             `json:"cap_add"`
	Start          *bool                `json:"start"`
	Pull           string               `json:"pull"` // missing (default) | always | never
}

type PortSpec struct {
	ContainerPort int    `json:"container_port"`
	HostPort      int    `json:"host_port"`
	Protocol      string `json:"protocol"`
	HostIP        string `json:"host_ip"`
}

type NetworkSpec struct {
	Name    string   `json:"name"`
	Aliases []string `json:"aliases"`
	// IPv4 pins the container's address on this network (PNET: dmp-mesh).
	IPv4 string `json:"ipv4_address"`
}

type MountSpec struct {
	Type     string `json:"type"` // volume | bind | tmpfs
	Source   string `json:"source"`
	Target   string `json:"target"`
	ReadOnly bool   `json:"read_only"`
}

type HealthSpec struct {
	Test         json.RawMessage `json:"test"` // ["CMD", ...] or "shell string"
	IntervalS    int             `json:"interval_s"`
	TimeoutS     int             `json:"timeout_s"`
	Retries      int             `json:"retries"`
	StartPeriodS int             `json:"start_period_s"`
	Disable      bool            `json:"disable"`
}

// Capabilities that may be re-added after CapDrop ALL.
var allowedCaps = map[string]bool{
	"CHOWN": true, "DAC_OVERRIDE": true, "FOWNER": true, "FSETID": true, "KILL": true,
	"SETGID": true, "SETUID": true, "NET_BIND_SERVICE": true, "SETPCAP": false,
}

// BuildCreateBody turns a CreateSpec into a Docker create body with secure
// defaults: CapDrop ALL, no-new-privileges, never privileged, no host
// namespaces, pids limit, restart policy, optional read-only root.
func (e *Executor) BuildCreateBody(s *CreateSpec) (map[string]any, []NetworkSpec, error) {
	if err := checkImage(s.Image); err != nil {
		return nil, nil, err
	}
	if s.Name != "" {
		if err := checkRef("container name", s.Name); err != nil {
			return nil, nil, err
		}
	}
	env, err := parseEnv(s.Env)
	if err != nil {
		return nil, nil, err
	}
	cfg := map[string]any{
		"Image":  s.Image,
		"Env":    env,
		"Labels": managedLabels(s.Labels), // Traefik labels pass through unchanged
	}
	if len(s.Cmd) > 0 {
		cfg["Cmd"] = s.Cmd
	}
	if len(s.Entrypoint) > 0 {
		cfg["Entrypoint"] = s.Entrypoint
	}
	if s.User != "" {
		if s.User == "root" || s.User == "0" || strings.HasPrefix(s.User, "0:") || strings.HasPrefix(s.User, "root:") {
			return nil, nil, errors.New("running as root is not allowed; omit user to use the image default")
		}
		cfg["User"] = s.User
	}
	if s.WorkingDir != "" {
		cfg["WorkingDir"] = s.WorkingDir
	}
	if s.Healthcheck != nil {
		hc, err := buildHealth(s.Healthcheck)
		if err != nil {
			return nil, nil, err
		}
		cfg["Healthcheck"] = hc
	}

	caps := []string{}
	for _, c := range s.CapAdd {
		c = strings.TrimPrefix(strings.ToUpper(c), "CAP_")
		if !allowedCaps[c] {
			return nil, nil, fmt.Errorf("capability %s not allowed", c)
		}
		caps = append(caps, c)
	}
	rp := firstNonEmpty(s.RestartPolicy, "unless-stopped")
	switch rp {
	case "no", "always", "unless-stopped", "on-failure":
	default:
		return nil, nil, fmt.Errorf("invalid restart_policy %q", rp)
	}
	restart := map[string]any{"Name": rp}
	if rp == "on-failure" && s.MaxRetries > 0 {
		restart["MaximumRetryCount"] = s.MaxRetries
	}
	pids := s.PidsLimit
	if pids <= 0 {
		pids = 1024
	}
	host := map[string]any{
		"CapDrop":        []string{"ALL"},
		"CapAdd":         caps,
		"SecurityOpt":    []string{"no-new-privileges:true"},
		"Privileged":     false,
		"ReadonlyRootfs": s.ReadOnlyRootfs,
		"RestartPolicy":  restart,
		"PidsLimit":      pids,
		"Init":           true,
		"LogConfig":      map[string]any{"Type": "json-file", "Config": map[string]string{"max-size": "10m", "max-file": "3"}},
	}
	if s.MemoryMB > 0 {
		host["Memory"] = s.MemoryMB << 20
		host["MemorySwap"] = s.MemoryMB << 20
	}
	if s.CPUs > 0 {
		host["NanoCpus"] = int64(s.CPUs * 1e9)
	}
	if len(s.Tmpfs) > 0 || s.ReadOnlyRootfs {
		t := map[string]string{}
		if s.ReadOnlyRootfs {
			t["/tmp"] = "rw,noexec,nosuid,size=64m"
		}
		for _, p := range s.Tmpfs {
			if !filepath.IsAbs(p) {
				return nil, nil, fmt.Errorf("tmpfs path %q must be absolute", p)
			}
			t[p] = "rw,nosuid,size=64m"
		}
		host["Tmpfs"] = t
	}

	exposed := map[string]any{}
	bindings := map[string][]map[string]string{}
	for _, p := range s.Ports {
		proto := firstNonEmpty(p.Protocol, "tcp")
		if proto != "tcp" && proto != "udp" {
			return nil, nil, fmt.Errorf("invalid protocol %q", proto)
		}
		if p.ContainerPort < 1 || p.ContainerPort > 65535 || p.HostPort < 0 || p.HostPort > 65535 {
			return nil, nil, errors.New("invalid port")
		}
		k := strconv.Itoa(p.ContainerPort) + "/" + proto
		exposed[k] = map[string]any{}
		if p.HostPort > 0 {
			bindings[k] = append(bindings[k], map[string]string{"HostIp": p.HostIP, "HostPort": strconv.Itoa(p.HostPort)})
		}
	}
	if len(exposed) > 0 {
		cfg["ExposedPorts"] = exposed
	}
	if len(bindings) > 0 {
		host["PortBindings"] = bindings
	}

	var mounts []map[string]any
	for _, m := range s.Mounts {
		if !filepath.IsAbs(m.Target) {
			return nil, nil, fmt.Errorf("mount target %q must be absolute", m.Target)
		}
		mm := map[string]any{"Type": firstNonEmpty(m.Type, "volume"), "Target": m.Target, "ReadOnly": m.ReadOnly}
		switch mm["Type"] {
		case "volume":
			if err := checkRef("volume", m.Source); err != nil {
				return nil, nil, err
			}
			mm["Source"] = m.Source
		case "bind":
			if err := e.checkBind(m.Source); err != nil {
				return nil, nil, err
			}
			mm["Source"] = filepath.Clean(m.Source)
		case "tmpfs":
		default:
			return nil, nil, fmt.Errorf("mount type %q not allowed", m.Type)
		}
		mounts = append(mounts, mm)
	}
	if len(mounts) > 0 {
		host["Mounts"] = mounts
	}

	var extra []NetworkSpec
	if len(s.Networks) > 0 {
		for _, n := range s.Networks {
			if err := checkRef("network", n.Name); err != nil {
				return nil, nil, err
			}
			if n.Name == "host" {
				return nil, nil, errors.New("host networking not allowed")
			}
			if n.IPv4 != "" {
				if ip := net.ParseIP(n.IPv4); ip == nil || ip.To4() == nil {
					return nil, nil, fmt.Errorf("invalid ipv4_address %q", n.IPv4)
				}
			}
		}
		first := s.Networks[0]
		host["NetworkMode"] = first.Name
		ep := map[string]any{}
		if len(first.Aliases) > 0 {
			ep["Aliases"] = first.Aliases
		}
		if first.IPv4 != "" {
			ep["IPAMConfig"] = map[string]string{"IPv4Address": first.IPv4}
		}
		cfg["NetworkingConfig"] = map[string]any{"EndpointsConfig": map[string]any{first.Name: ep}}
		extra = s.Networks[1:]
	}
	cfg["HostConfig"] = host
	return cfg, extra, nil
}

func (e *Executor) checkBind(src string) error {
	if !filepath.IsAbs(src) {
		return fmt.Errorf("bind source %q must be absolute", src)
	}
	c := filepath.Clean(src)
	for _, root := range e.AllowedBindRoots {
		r := filepath.Clean(root)
		if c == r || strings.HasPrefix(c, r+"/") {
			return nil
		}
	}
	return fmt.Errorf("bind mount %q is outside the allowed roots %v", src, e.AllowedBindRoots)
}

func parseEnv(raw json.RawMessage) ([]string, error) {
	if len(raw) == 0 || string(raw) == "null" {
		return []string{}, nil
	}
	var m map[string]string
	if json.Unmarshal(raw, &m) == nil {
		keys := make([]string, 0, len(m))
		for k := range m {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		out := make([]string, 0, len(m))
		for _, k := range keys {
			if !reEnvKey.MatchString(k) {
				return nil, fmt.Errorf("invalid env key %q", k)
			}
			out = append(out, k+"="+m[k])
		}
		return out, nil
	}
	var l []string
	if err := json.Unmarshal(raw, &l); err != nil {
		return nil, errors.New("env must be an object or a list of K=V")
	}
	for _, kv := range l {
		k, _, _ := strings.Cut(kv, "=")
		if !reEnvKey.MatchString(k) {
			return nil, fmt.Errorf("invalid env key %q", k)
		}
	}
	return l, nil
}

func buildHealth(h *HealthSpec) (map[string]any, error) {
	if h.Disable {
		return map[string]any{"Test": []string{"NONE"}}, nil
	}
	var test []string
	var s string
	if json.Unmarshal(h.Test, &s) == nil && s != "" {
		test = []string{"CMD-SHELL", s} // runs inside the container, not on the host
	} else if err := json.Unmarshal(h.Test, &test); err != nil || len(test) == 0 {
		return nil, errors.New("healthcheck.test must be a string or a non-empty list")
	}
	if test[0] != "CMD" && test[0] != "CMD-SHELL" && test[0] != "NONE" {
		test = append([]string{"CMD"}, test...)
	}
	sec := func(n, def int) int64 {
		if n <= 0 {
			n = def
		}
		return int64(time.Duration(n) * time.Second)
	}
	r := h.Retries
	if r <= 0 {
		r = 3
	}
	return map[string]any{
		"Test": test, "Interval": sec(h.IntervalS, 30), "Timeout": sec(h.TimeoutS, 5),
		"Retries": r, "StartPeriod": sec(h.StartPeriodS, 10),
	}, nil
}

func (e *Executor) createContainer(ctx context.Context, raw []byte, progress func(int, string)) (any, error) {
	var s CreateSpec
	if err := decode(raw, &s); err != nil {
		return nil, err
	}
	body, extra, err := e.BuildCreateBody(&s)
	if err != nil {
		return nil, err
	}
	d := e.Docker
	pull := func() error {
		img, tag := splitTag(s.Image, "")
		progress(10, "pulling "+s.Image)
		return d.PullImage(ctx, img, tag, s.RegistryAuth, nil)
	}
	if s.Pull == "always" {
		if err := pull(); err != nil {
			// A registry outage or rate limit (e.g. HTTP 429) must not take down a redeploy of an
			// image that is already on this host: fall back to the local copy and say so.
			if ok, _ := d.ImageExists(ctx, s.Image); !ok {
				return nil, err
			}
			progress(20, fmt.Sprintf("pull failed (%v); using the local image %s", err, s.Image))
		}
	}
	id, err := d.CreateContainer(ctx, s.Name, body)
	if docker.IsNotFound(err) && s.Pull != "never" && strings.Contains(strings.ToLower(err.Error()), "image") {
		if perr := pull(); perr != nil {
			return nil, perr
		}
		id, err = d.CreateContainer(ctx, s.Name, body)
	}
	if err != nil {
		return nil, err
	}
	progress(60, "created")
	for _, n := range extra {
		if err := d.ConnectNetwork(ctx, n.Name, id, n.Aliases, n.IPv4); err != nil {
			return map[string]any{"container_id": id}, fmt.Errorf("connect %s: %w", n.Name, err)
		}
	}
	if s.Start == nil || *s.Start {
		if err := d.StartContainer(ctx, id); err != nil {
			return map[string]any{"container_id": id}, err
		}
	}
	return map[string]any{"container_id": id}, nil
}
