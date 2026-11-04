package executor

import (
	"context"
	"errors"
	"fmt"
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

type swarmServiceSpec struct {
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

func buildSwarmService(s *swarmServiceSpec) (map[string]any, error) {
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
			"ContainerSpec": map[string]any{"Image": s.Image, "Env": env, "Labels": labels, "Privileges": map[string]any{"NoNewPrivileges": true}, "CapabilityDrop": []string{"ALL"}},
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
		spec, err := buildSwarmService(&s)
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
