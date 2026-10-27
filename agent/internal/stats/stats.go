// Package stats samples host statistics from /proc and builds the Docker
// inventory reported on every heartbeat.
package stats

import (
	"bufio"
	"context"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/livezen-technologies/cloudpanel/agent/internal/docker"
	"github.com/livezen-technologies/cloudpanel/agent/internal/facts"
	"github.com/livezen-technologies/cloudpanel/agent/internal/protocol"
)

// Sampler keeps the previous CPU sample so cpu_pct is the utilisation since
// the last heartbeat.
type Sampler struct {
	ProcRoot string // default "/proc"
	DiskPath string // default "/"
	mu       sync.Mutex
	prevIdle uint64
	prevTot  uint64
}

func (s *Sampler) proc(p string) string {
	r := s.ProcRoot
	if r == "" {
		r = "/proc"
	}
	return filepath.Join(r, p)
}

// Sample returns current host stats.
func (s *Sampler) Sample() protocol.Stats {
	var st protocol.Stats
	st.CPUPct = s.cpu()
	m := facts.Meminfo(s.proc("meminfo"))
	if tot := m["MemTotal"]; tot > 0 {
		avail, ok := m["MemAvailable"]
		if !ok {
			avail = m["MemFree"] + m["Buffers"] + m["Cached"]
		}
		st.RAMPct = round(float64(tot-min(avail, tot)) / float64(tot) * 100)
	}
	d := s.DiskPath
	if d == "" {
		d = "/"
	}
	var fs syscall.Statfs_t
	if syscall.Statfs(d, &fs) == nil && fs.Blocks > 0 {
		used := fs.Blocks - fs.Bfree
		denom := used + fs.Bavail
		if denom > 0 {
			st.DiskPct = round(float64(used) / float64(denom) * 100)
		}
	}
	st.NetRx, st.NetTx = NetCounters(s.proc("net/dev"))
	return st
}

func (s *Sampler) cpu() float64 {
	b, err := os.ReadFile(s.proc("stat"))
	if err != nil {
		return 0
	}
	line, _, _ := strings.Cut(string(b), "\n")
	fs := strings.Fields(line)
	if len(fs) < 5 || fs[0] != "cpu" {
		return 0
	}
	var tot, idle uint64
	for i, f := range fs[1:] {
		n, _ := strconv.ParseUint(f, 10, 64)
		if i >= 8 { // guest/guest_nice are already counted in user/nice
			break
		}
		tot += n
		if i == 3 || i == 4 { // idle + iowait
			idle += n
		}
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	pt, pi := s.prevTot, s.prevIdle
	s.prevTot, s.prevIdle = tot, idle
	if pt == 0 || tot <= pt {
		if tot == 0 {
			return 0
		}
		return round(float64(tot-idle) / float64(tot) * 100) // since boot
	}
	dt, di := tot-pt, idle-pi
	return round(float64(dt-min(di, dt)) / float64(dt) * 100)
}

// NetCounters sums rx/tx bytes over all non-loopback interfaces.
func NetCounters(path string) (rx, tx uint64) {
	fh, err := os.Open(path)
	if err != nil {
		return 0, 0
	}
	defer fh.Close()
	sc := bufio.NewScanner(fh)
	for sc.Scan() {
		name, rest, ok := strings.Cut(sc.Text(), ":")
		if !ok {
			continue
		}
		name = strings.TrimSpace(name)
		if name == "lo" || strings.HasPrefix(name, "veth") {
			continue
		}
		fs := strings.Fields(rest)
		if len(fs) < 9 {
			continue
		}
		r, _ := strconv.ParseUint(fs[0], 10, 64)
		t, _ := strconv.ParseUint(fs[8], 10, 64)
		rx += r
		tx += t
	}
	return
}

func round(f float64) float64 { return float64(int64(f*10+0.5)) / 10 }

// Inventory is the Docker portion of a heartbeat.
type Inventory struct {
	Containers []protocol.Container
	Images     []protocol.Image
	Networks   []protocol.Network
	Volumes    []protocol.Volume
}

// CollectInventory lists containers (with health and one-shot stats), images,
// networks and volumes. Per-container stats are fetched concurrently.
func CollectInventory(ctx context.Context, dc *docker.Client) (*Inventory, error) {
	cs, err := dc.ListContainers(ctx)
	if err != nil {
		return nil, err
	}
	inv := &Inventory{Containers: make([]protocol.Container, len(cs))}
	sem := make(chan struct{}, 8)
	var wg sync.WaitGroup
	for i, c := range cs {
		pc := protocol.Container{
			DockerID: c.ID,
			Image:    c.Image,
			State:    c.State,
			Health:   "none",
			Created:  time.Unix(c.Created, 0).UTC().Format(time.RFC3339),
			Ports:    []string{},
		}
		if len(c.Names) > 0 {
			pc.Name = strings.TrimPrefix(c.Names[0], "/")
		}
		for _, p := range c.Ports {
			if p.PublicPort > 0 {
				pc.Ports = append(pc.Ports, fmt.Sprintf("%s:%d->%d/%s", p.IP, p.PublicPort, p.PrivatePort, p.Type))
			} else {
				pc.Ports = append(pc.Ports, fmt.Sprintf("%d/%s", p.PrivatePort, p.Type))
			}
		}
		sort.Strings(pc.Ports)
		inv.Containers[i] = pc
		wg.Add(1)
		go func(i int, id, state string) {
			defer wg.Done()
			sem <- struct{}{}
			defer func() { <-sem }()
			cctx, cancel := context.WithTimeout(ctx, 5*time.Second)
			defer cancel()
			if in, err := dc.InspectContainer(cctx, id); err == nil && in.State.Health != nil {
				inv.Containers[i].Health = in.State.Health.Status
			}
			if state != "running" {
				return
			}
			if s, err := dc.ContainerStats(cctx, id); err == nil {
				inv.Containers[i].CPUPct = round(s.CPUPercent())
				inv.Containers[i].MemMB = round(s.MemMB())
			}
		}(i, c.ID, c.State)
	}
	wg.Wait()

	if ims, err := dc.ListImages(ctx); err == nil {
		for _, im := range ims {
			tags := im.RepoTags
			if tags == nil {
				tags = []string{}
			}
			inv.Images = append(inv.Images, protocol.Image{ID: im.ID, Tags: tags, SizeMB: round(float64(im.Size) / (1 << 20)), Created: time.Unix(im.Created, 0).UTC().Format(time.RFC3339)})
		}
	}
	if ns, err := dc.ListNetworks(ctx); err == nil {
		for _, n := range ns {
			inv.Networks = append(inv.Networks, protocol.Network{ID: n.ID, Name: n.Name, Driver: n.Driver, Scope: n.Scope, Internal: n.Internal})
		}
	}
	if vs, err := dc.ListVolumes(ctx); err == nil {
		for _, v := range vs {
			inv.Volumes = append(inv.Volumes, protocol.Volume{Name: v.Name, Driver: v.Driver, Mountpoint: v.Mountpoint})
		}
	}
	if inv.Images == nil {
		inv.Images = []protocol.Image{}
	}
	if inv.Networks == nil {
		inv.Networks = []protocol.Network{}
	}
	if inv.Volumes == nil {
		inv.Volumes = []protocol.Volume{}
	}
	return inv, nil
}
