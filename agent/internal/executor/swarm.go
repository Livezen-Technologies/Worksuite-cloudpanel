package executor

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"regexp"
	"strconv"
)

// Swarm commands: create and join a cluster, inspect it, drain nodes and scale services.

var (
	reSwarmID    = regexp.MustCompile(`^[a-z0-9]{10,64}$`)
	reSwarmToken = regexp.MustCompile(`^SWMTKN-1-[a-z0-9]{50}-[a-z0-9]{25}$`)
	reLabelKV    = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9._/-]{0,62}$`)
)

func checkAddr(addr string, withPort bool) error {
	if withPort {
		host, port, err := net.SplitHostPort(addr)
		if err != nil || net.ParseIP(host) == nil {
			return fmt.Errorf("invalid address %q", addr)
		}
		if p, err := strconv.Atoi(port); err != nil || p < 1 || p > 65535 {
			return fmt.Errorf("invalid port in %q", addr)
		}
		return nil
	}
	if net.ParseIP(addr) == nil {
		return fmt.Errorf("invalid IP %q", addr)
	}
	return nil
}

func jnum(v any) int64 {
	switch n := v.(type) {
	case float64:
		return int64(n)
	case json.Number:
		i, _ := n.Int64()
		return i
	}
	return 0
}

func jpath(m map[string]any, keys ...string) any {
	var cur any = m
	for _, k := range keys {
		mm, ok := cur.(map[string]any)
		if !ok {
			return nil
		}
		cur = mm[k]
	}
	return cur
}

func jstr(v any) string { s, _ := v.(string); return s }

func (e *Executor) swarm(ctx context.Context, typ string, raw []byte) (any, error) {
	d := e.Docker
	switch typ {
	case "swarm.init":
		var p struct {
			AdvertiseAddr string `json:"advertise_addr"`
		}
		if err := decode(raw, &p); err != nil {
			return nil, err
		}
		if err := checkAddr(p.AdvertiseAddr, false); err != nil {
			return nil, err
		}
		id, err := d.SwarmInit(ctx, p.AdvertiseAddr)
		if err != nil {
			return nil, err
		}
		info, err := d.SwarmInspect(ctx)
		if err != nil {
			return nil, err
		}
		return map[string]any{"node_id": id, "swarm_id": info.ID, "worker_token": info.JoinTokens.Worker, "manager_token": info.JoinTokens.Manager}, nil
	case "swarm.join":
		var p struct {
			AdvertiseAddr string `json:"advertise_addr"`
			Manager       string `json:"manager"`
			Token         string `json:"token"`
		}
		if err := decode(raw, &p); err != nil {
			return nil, err
		}
		if err := checkAddr(p.AdvertiseAddr, false); err != nil {
			return nil, err
		}
		if err := checkAddr(p.Manager, true); err != nil {
			return nil, err
		}
		if !reSwarmToken.MatchString(p.Token) {
			return nil, errors.New("invalid swarm join token")
		}
		return map[string]any{"joined": true}, d.SwarmJoin(ctx, p.AdvertiseAddr, p.Manager, p.Token)
	case "swarm.leave":
		var p struct {
			Force bool `json:"force"`
		}
		if err := decode(raw, &p); err != nil {
			return nil, err
		}
		return map[string]any{"left": true}, d.SwarmLeave(ctx, p.Force)
	case "swarm.inspect":
		return e.swarmInspect(ctx)
	case "swarm.node_update":
		var p struct {
			NodeID       string            `json:"node_id"`
			Availability string            `json:"availability"`
			Labels       map[string]string `json:"labels"`
		}
		if err := decode(raw, &p); err != nil {
			return nil, err
		}
		if !reSwarmID.MatchString(p.NodeID) {
			return nil, errors.New("invalid node id")
		}
		n, err := d.SwarmNodeInspect(ctx, p.NodeID)
		if err != nil {
			return nil, err
		}
		spec, _ := n["Spec"].(map[string]any)
		if spec == nil {
			spec = map[string]any{}
		}
		if p.Availability != "" {
			switch p.Availability {
			case "active", "pause", "drain":
				spec["Availability"] = p.Availability
			default:
				return nil, fmt.Errorf("invalid availability %q", p.Availability)
			}
		}
		if p.Labels != nil {
			for k, v := range p.Labels {
				if !reLabelKV.MatchString(k) || (v != "" && !reLabelKV.MatchString(v)) {
					return nil, fmt.Errorf("invalid label %q", k)
				}
			}
			spec["Labels"] = p.Labels
		}
		return map[string]any{"updated": p.NodeID}, d.SwarmNodeUpdate(ctx, p.NodeID, jnum(jpath(n, "Version", "Index")), spec)
	case "swarm.service_scale":
		var p struct {
			ServiceID string `json:"service_id"`
			Replicas  int64  `json:"replicas"`
		}
		if err := decode(raw, &p); err != nil {
			return nil, err
		}
		if !reSwarmID.MatchString(p.ServiceID) || p.Replicas < 0 || p.Replicas > 100 {
			return nil, errors.New("invalid service id or replica count (0-100)")
		}
		s, err := d.SwarmServiceInspect(ctx, p.ServiceID)
		if err != nil {
			return nil, err
		}
		spec, _ := s["Spec"].(map[string]any)
		rep, _ := jpath(spec, "Mode", "Replicated").(map[string]any)
		if rep == nil {
			return nil, errors.New("only replicated services can be scaled")
		}
		rep["Replicas"] = p.Replicas
		return map[string]any{"scaled": p.ServiceID, "replicas": p.Replicas}, d.SwarmServiceUpdate(ctx, p.ServiceID, jnum(jpath(s, "Version", "Index")), spec)
	}
	return nil, fmt.Errorf("unknown swarm command %s", typ)
}

// swarmInspect reports nodes, services and how many tasks run where, trimmed to what the panel shows.
func (e *Executor) swarmInspect(ctx context.Context) (any, error) {
	d := e.Docker
	nodes, err := d.SwarmNodes(ctx)
	if err != nil {
		return nil, err
	}
	services, err := d.SwarmServices(ctx)
	if err != nil {
		return nil, err
	}
	tasks, err := d.SwarmTasks(ctx)
	if err != nil {
		return nil, err
	}
	perNode := map[string]int{}
	running := map[string]int{}
	for _, t := range tasks {
		if jstr(jpath(t, "Status", "State")) == "running" {
			perNode[jstr(t["NodeID"])]++
			running[jstr(t["ServiceID"])]++
		}
	}
	outNodes := []map[string]any{}
	for _, n := range nodes {
		outNodes = append(outNodes, map[string]any{
			"id": jstr(n["ID"]), "hostname": jstr(jpath(n, "Description", "Hostname")), "role": jstr(jpath(n, "Spec", "Role")),
			"availability": jstr(jpath(n, "Spec", "Availability")), "state": jstr(jpath(n, "Status", "State")), "addr": jstr(jpath(n, "Status", "Addr")),
			"leader": jpath(n, "ManagerStatus", "Leader") == true, "labels": jpath(n, "Spec", "Labels"), "tasks": perNode[jstr(n["ID"])],
		})
	}
	outServices := []map[string]any{}
	for _, s := range services {
		var replicas any
		if r := jpath(s, "Spec", "Mode", "Replicated", "Replicas"); r != nil {
			replicas = jnum(r)
		}
		outServices = append(outServices, map[string]any{
			"id": jstr(s["ID"]), "name": jstr(jpath(s, "Spec", "Name")), "image": jstr(jpath(s, "Spec", "TaskTemplate", "ContainerSpec", "Image")),
			"replicas": replicas, "running": running[jstr(s["ID"])], "constraints": jpath(s, "Spec", "TaskTemplate", "Placement", "Constraints"),
			"secrets": jpath(s, "Spec", "TaskTemplate", "ContainerSpec", "Secrets"), "mounts": jpath(s, "Spec", "TaskTemplate", "ContainerSpec", "Mounts"),
		})
	}
	secrets, err := d.SwarmSecrets(ctx)
	if err != nil {
		return nil, err
	}
	outSecrets := []map[string]any{}
	for _, x := range secrets {
		// Names and dates only: the Engine never returns a secret's value, and neither do we.
		outSecrets = append(outSecrets, map[string]any{"id": jstr(x["ID"]), "name": jstr(jpath(x, "Spec", "Name")), "created_at": jstr(x["CreatedAt"])})
	}
	return map[string]any{"nodes": outNodes, "services": outServices, "secrets": outSecrets}, nil
}
