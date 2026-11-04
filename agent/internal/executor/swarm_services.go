package executor

import (
	"context"
	"encoding/base64"
	"errors"
	"fmt"
	"path/filepath"
	"regexp"
	"sort"
)

// Swarm services: create from an image, roll a new image out, remove.

var reConstraint = regexp.MustCompile(`^(node\.role|node\.hostname|node\.labels\.[a-zA-Z0-9._-]{1,63})\s*(==|!=)\s*[a-zA-Z0-9._-]{1,63}$`)

type swarmPort struct {
	Target    int    `json:"target"`
	Published int    `json:"published"`
	Protocol  string `json:"protocol"`
}

type swarmMount struct {
	Source   string `json:"source"`
	Target   string `json:"target"`
	ReadOnly bool   `json:"read_only"`
}

type swarmServiceSpec struct {
	Secrets     []string          `json:"secrets"`
	Mounts      []swarmMount      `json:"mounts"`
	Name        string            `json:"name"`
	Image       string            `json:"image"`
	Replicas    int64             `json:"replicas"`
	Env         map[string]string `json:"env"`
	Ports       []swarmPort       `json:"ports"`
	Constraints []string          `json:"constraints"`
	Labels      map[string]string `json:"labels"`
	MemoryMB    int64             `json:"memory_mb"`
	CPUs        float64           `json:"cpus"`
}

// rollingUpdate replaces one task at a time, starts the new one before stopping the old,
// and rolls back if a new task fails to come up.
var rollingUpdate = map[string]any{"Parallelism": 1, "Delay": int64(5e9), "FailureAction": "rollback", "Monitor": int64(15e9), "Order": "start-first"}

// buildSwarmService turns the payload into a service spec. secretIDs maps secret names to ids.
func buildSwarmService(s *swarmServiceSpec, secretIDs map[string]string) (map[string]any, error) {
	if err := checkRef("service name", s.Name); err != nil {
		return nil, err
	}
	if err := checkImage(s.Image); err != nil {
		return nil, err
	}
	if s.Replicas < 0 || s.Replicas > 100 {
		return nil, errors.New("replicas must be 0-100")
	}
	env := []string{}
	keys := make([]string, 0, len(s.Env))
	for k := range s.Env {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		if !reEnvKey.MatchString(k) {
			return nil, fmt.Errorf("invalid env key %q", k)
		}
		env = append(env, k+"="+s.Env[k])
	}
	for _, c := range s.Constraints {
		if !reConstraint.MatchString(c) {
			return nil, fmt.Errorf("invalid placement constraint %q", c)
		}
	}
	ports := []map[string]any{}
	for _, p := range s.Ports {
		proto := firstNonEmpty(p.Protocol, "tcp")
		if proto != "tcp" && proto != "udp" {
			return nil, fmt.Errorf("invalid protocol %q", proto)
		}
		if p.Target < 1 || p.Target > 65535 || p.Published < 1 || p.Published > 65535 {
			return nil, errors.New("invalid port")
		}
		ports = append(ports, map[string]any{"TargetPort": p.Target, "PublishedPort": p.Published, "Protocol": proto, "PublishMode": "ingress"})
	}
	secrets := []map[string]any{}
	for _, name := range s.Secrets {
		if err := checkRef("secret name", name); err != nil {
			return nil, err
		}
		id, ok := secretIDs[name]
		if !ok {
			return nil, fmt.Errorf("secret %q does not exist in the cluster", name)
		}
		// Mounted read-only at /run/secrets/<name>, owned by root, mode 0400.
		secrets = append(secrets, map[string]any{"SecretID": id, "SecretName": name, "File": map[string]any{"Name": name, "UID": "0", "GID": "0", "Mode": 0o400}})
	}
	mounts := []map[string]any{}
	for _, m := range s.Mounts {
		if err := checkRef("volume", m.Source); err != nil {
			return nil, err
		}
		if !filepath.IsAbs(m.Target) || filepath.Clean(m.Target) != m.Target || m.Target == "/" {
			return nil, fmt.Errorf("mount target %q must be a clean absolute path", m.Target)
		}
		// Named volumes only: no bind mounts of the host into a service.
		mounts = append(mounts, map[string]any{"Type": "volume", "Source": m.Source, "Target": m.Target, "ReadOnly": m.ReadOnly})
	}
	labels := managedLabels(s.Labels)
	res := map[string]any{}
	if s.MemoryMB > 0 {
		res["MemoryBytes"] = s.MemoryMB << 20
	}
	if s.CPUs > 0 {
		res["NanoCPUs"] = int64(s.CPUs * 1e9)
	}
	return map[string]any{
		"Name":   s.Name,
		"Labels": labels,
		"TaskTemplate": map[string]any{
			// Same hardening as container.create: no added capabilities, no privilege escalation.
			"ContainerSpec": map[string]any{"Image": s.Image, "Env": env, "Labels": labels, "Privileges": map[string]any{"NoNewPrivileges": true}, "CapabilityDrop": []string{"ALL"}, "Secrets": secrets, "Mounts": mounts},
			"Placement":     map[string]any{"Constraints": s.Constraints},
			"Resources":     map[string]any{"Limits": res},
			"RestartPolicy": map[string]any{"Condition": "any", "Delay": int64(5e9)},
		},
		"Mode":           map[string]any{"Replicated": map[string]any{"Replicas": s.Replicas}},
		"UpdateConfig":   rollingUpdate,
		"RollbackConfig": map[string]any{"Parallelism": 1, "Order": "stop-first"},
		"EndpointSpec":   map[string]any{"Ports": ports},
	}, nil
}

func (e *Executor) swarmServices(ctx context.Context, typ string, raw []byte) (any, error) {
	d := e.Docker
	switch typ {
	case "swarm.service_create":
		var s swarmServiceSpec
		if err := decode(raw, &s); err != nil {
			return nil, err
		}
		ids := map[string]string{}
		if len(s.Secrets) > 0 {
			list, err := d.SwarmSecrets(ctx)
			if err != nil {
				return nil, err
			}
			for _, x := range list {
				ids[jstr(jpath(x, "Spec", "Name"))] = jstr(x["ID"])
			}
		}
		spec, err := buildSwarmService(&s, ids)
		if err != nil {
			return nil, err
		}
		id, err := d.SwarmServiceCreate(ctx, spec)
		return map[string]any{"service_id": id, "name": s.Name}, err
	case "swarm.service_image":
		var p struct {
			ServiceID string `json:"service_id"`
			Image     string `json:"image"`
		}
		if err := decode(raw, &p); err != nil {
			return nil, err
		}
		if !reSwarmID.MatchString(p.ServiceID) {
			return nil, errors.New("invalid service id")
		}
		if err := checkImage(p.Image); err != nil {
			return nil, err
		}
		s, err := d.SwarmServiceInspect(ctx, p.ServiceID)
		if err != nil {
			return nil, err
		}
		spec, _ := s["Spec"].(map[string]any)
		cs, _ := jpath(spec, "TaskTemplate", "ContainerSpec").(map[string]any)
		if cs == nil {
			return nil, errors.New("service has no container spec")
		}
		cs["Image"] = p.Image
		if _, ok := spec["UpdateConfig"]; !ok {
			spec["UpdateConfig"] = rollingUpdate
		}
		return map[string]any{"service_id": p.ServiceID, "image": p.Image}, d.SwarmServiceUpdate(ctx, p.ServiceID, jnum(jpath(s, "Version", "Index")), spec)
	case "swarm.secret_create":
		var p struct {
			Name string `json:"name"`
			Data string `json:"data"` // base64
		}
		if err := decode(raw, &p); err != nil {
			return nil, err
		}
		if err := checkRef("secret name", p.Name); err != nil {
			return nil, err
		}
		if b, err := base64.StdEncoding.DecodeString(p.Data); err != nil || len(b) == 0 || len(b) > 500*1024 {
			return nil, errors.New("secret data must be base64, 1 byte to 500 KB")
		}
		id, err := d.SwarmSecretCreate(ctx, p.Name, p.Data, managedLabels(nil))
		// The value is never echoed back.
		return map[string]any{"secret_id": id, "name": p.Name}, err
	case "swarm.secret_remove":
		var p struct {
			SecretID string `json:"secret_id"`
		}
		if err := decode(raw, &p); err != nil {
			return nil, err
		}
		if !reSwarmID.MatchString(p.SecretID) {
			return nil, errors.New("invalid secret id")
		}
		return map[string]any{"removed": p.SecretID}, d.SwarmSecretRemove(ctx, p.SecretID)
	case "swarm.service_remove":
		var p struct {
			ServiceID string `json:"service_id"`
		}
		if err := decode(raw, &p); err != nil {
			return nil, err
		}
		if !reSwarmID.MatchString(p.ServiceID) {
			return nil, errors.New("invalid service id")
		}
		return map[string]any{"removed": p.ServiceID}, d.SwarmServiceRemove(ctx, p.ServiceID)
	}
	return nil, fmt.Errorf("unknown swarm command %s", typ)
}
