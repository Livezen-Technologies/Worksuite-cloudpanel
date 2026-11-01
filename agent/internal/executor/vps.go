package executor

import (
	"context"
	"errors"
	"fmt"
	"regexp"
	"strconv"
	"strings"

	"github.com/livezen-technologies/cloudpanel/agent/internal/docker"
)

// VPSRuntime is the only runtime a customer VPS may use: Sysbox maps root in the
// container to an unprivileged user on the host, so handing out root is safe.
const VPSRuntime = "sysbox-runc"

// VPSLabel marks a container as a customer VPS.
const VPSLabel = "io.dockermgr.vps"

var (
	reVPSHostname = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$`)
	reSSHKey      = regexp.MustCompile(`^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521)|sk-ssh-ed25519@openssh\.com) [A-Za-z0-9+/=]{40,}( [^\n\r]{0,200})?$`)
)

// VPSSpec is the vps.create payload.
type VPSSpec struct {
	Name          string            `json:"name"`
	Hostname      string            `json:"hostname"`
	Image         string            `json:"image"`
	MemoryMB      int64             `json:"memory_mb"`
	CPUs          float64           `json:"cpus"`
	SSHPort       int               `json:"ssh_port"`
	AuthorizedKey []string          `json:"authorized_keys"`
	Labels        map[string]string `json:"labels"`
}

// BuildVPSBody builds the create body for a system container under Sysbox. Never
// privileged, no host namespaces, hard CPU/memory/pid limits, SSH on one host port.
func BuildVPSBody(s *VPSSpec) (map[string]any, error) {
	if err := checkImage(s.Image); err != nil {
		return nil, err
	}
	if err := checkRef("vps name", s.Name); err != nil {
		return nil, err
	}
	if !reVPSHostname.MatchString(s.Hostname) {
		return nil, fmt.Errorf("invalid hostname %q", s.Hostname)
	}
	if s.MemoryMB < 256 || s.CPUs <= 0 || s.CPUs > 64 {
		return nil, errors.New("a VPS needs memory_mb >= 256 and 0 < cpus <= 64")
	}
	if s.SSHPort < 1024 || s.SSHPort > 65535 {
		return nil, errors.New("ssh_port must be between 1024 and 65535")
	}
	if len(s.AuthorizedKey) == 0 {
		return nil, errors.New("at least one SSH public key is required")
	}
	for _, k := range s.AuthorizedKey {
		if !reSSHKey.MatchString(strings.TrimSpace(k)) {
			return nil, errors.New("invalid SSH public key")
		}
	}
	labels := managedLabels(s.Labels)
	labels[VPSLabel] = "true"
	return map[string]any{
		"Image":        s.Image,
		"Hostname":     s.Hostname,
		"Labels":       labels,
		"ExposedPorts": map[string]any{"22/tcp": map[string]any{}},
		"HostConfig": map[string]any{
			"Runtime":       VPSRuntime,
			"Privileged":    false,
			"Memory":        s.MemoryMB << 20,
			"MemorySwap":    s.MemoryMB << 20,
			"NanoCpus":      int64(s.CPUs * 1e9),
			"PidsLimit":     4096,
			"RestartPolicy": map[string]any{"Name": "unless-stopped"},
			"PortBindings":  map[string][]map[string]string{"22/tcp": {{"HostPort": strconv.Itoa(s.SSHPort)}}},
			"LogConfig":     map[string]any{"Type": "json-file", "Config": map[string]string{"max-size": "10m", "max-file": "3"}},
		},
	}, nil
}

func (e *Executor) createVPS(ctx context.Context, raw []byte, progress func(int, string)) (any, error) {
	var s VPSSpec
	if err := decode(raw, &s); err != nil {
		return nil, err
	}
	body, err := BuildVPSBody(&s)
	if err != nil {
		return nil, err
	}
	d := e.Docker
	info, err := d.Info(ctx)
	if err != nil {
		return nil, err
	}
	if _, ok := info.Runtimes[VPSRuntime]; !ok {
		return nil, errors.New("this server cannot host a VPS: the Sysbox runtime (sysbox-runc) is not installed")
	}
	id, err := d.CreateContainer(ctx, s.Name, body)
	if docker.IsNotFound(err) {
		img, tag := splitTag(s.Image, "")
		progress(10, "pulling "+s.Image)
		if perr := d.PullImage(ctx, img, tag, nil, nil); perr != nil {
			return nil, perr
		}
		id, err = d.CreateContainer(ctx, s.Name, body)
	}
	if err != nil {
		return nil, err
	}
	progress(60, "created")
	if err := d.StartContainer(ctx, id); err != nil {
		return map[string]any{"container_id": id}, err
	}
	// The keys travel as an environment variable, never inside a shell string.
	keys := strings.Join(s.AuthorizedKey, "\n") + "\n"
	ex, err := d.ExecCreate(ctx, id, docker.ExecConfig{
		Cmd:  []string{"/bin/sh", "-c", `umask 077 && mkdir -p /root/.ssh && printf '%s' "$DMP_KEYS" > /root/.ssh/authorized_keys`},
		User: "root", Env: []string{"DMP_KEYS=" + keys},
	})
	if err == nil {
		err = d.ExecStartDetached(ctx, ex)
	}
	if err != nil {
		return map[string]any{"container_id": id}, fmt.Errorf("install SSH key: %w", err)
	}
	progress(100, "running")
	return map[string]any{"container_id": id, "ssh_port": s.SSHPort}, nil
}
