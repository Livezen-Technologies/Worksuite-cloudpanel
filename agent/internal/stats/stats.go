// Package stats samples host statistics from /proc and builds the Docker
// inventory reported on every heartbeat.
package stats

import (
	"bufio"
	"context"
	"fmt"
	"os"
	"os/exec"
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
	st.Disks = s.Disks()
	return st
}

// maxDisks bounds the heartbeat payload on hosts with very many mounts.
const maxDisks = 32

// Filesystems that carry no capacity worth reporting: pseudo filesystems, RAM
// backed mounts, read-only images (snaps, the OVH rescue partition) and the
// overlays Docker stacks on top of a filesystem already reported.
var skipFS = map[string]bool{
	"tmpfs": true, "devtmpfs": true, "ramfs": true, "overlay": true,
	"squashfs": true, "iso9660": true, "autofs": true, "efivarfs": true,
}

// Disks lists mounted local block filesystems, largest first.
//
// A device is reported once however many places it is mounted, so a bind mount
// never counts the same array twice, and mounts Docker owns are left out
// because they describe a filesystem the engine's own root already covers.
func (s *Sampler) Disks() []protocol.Disk {
	fh, err := os.Open(s.proc("mounts"))
	if err != nil {
		return nil
	}
	defer fh.Close()

	seen := map[string]bool{}
	var out []protocol.Disk
	sc := bufio.NewScanner(fh)
	for sc.Scan() {
		f := strings.Fields(sc.Text())
		if len(f) < 3 {
			continue
		}
		dev, mount, fstype := f[0], unescapeMount(f[1]), f[2]
		if !strings.HasPrefix(dev, "/dev/") || skipFS[fstype] || seen[dev] {
			continue
		}
		if mount == "/var/lib/docker" || strings.HasPrefix(mount, "/var/lib/docker/") {
			continue
		}
		var fs syscall.Statfs_t
		if syscall.Statfs(mount, &fs) != nil || fs.Blocks == 0 {
			continue
		}
		used := fs.Blocks - fs.Bfree
		d := protocol.Disk{
			Mount:  mount,
			Device: dev,
			FSType: fstype,
			// Megabytes, because a 988 MB /boot truncates to 0 in whole gigabytes.
			SizeMB: int64(fs.Blocks) * int64(fs.Bsize) / (1 << 20),
			UsedMB: int64(used) * int64(fs.Bsize) / (1 << 20),
		}
		// Match df: the denominator excludes blocks only root may use.
		if denom := used + fs.Bavail; denom > 0 {
			d.UsedPct = round(float64(used) / float64(denom) * 100)
		}
		seen[dev] = true
		out = append(out, d)
	}
	sort.Slice(out, func(i, j int) bool {
		if out[i].SizeMB != out[j].SizeMB {
			return out[i].SizeMB > out[j].SizeMB
		}
		return out[i].Mount < out[j].Mount
	})
	if len(out) > maxDisks {
		out = out[:maxDisks]
	}
	return out
}

// unescapeMount decodes the octal escapes /proc/mounts writes for spaces,
// tabs, newlines and backslashes in a mount point.
func unescapeMount(p string) string {
	if !strings.Contains(p, `\`) {
		return p
	}
	var b strings.Builder
	for i := 0; i < len(p); i++ {
		if p[i] == '\\' && i+3 < len(p) {
			if n, err := strconv.ParseUint(p[i+1:i+4], 8, 8); err == nil {
				b.WriteByte(byte(n))
				i += 3
				continue
			}
		}
		b.WriteByte(p[i])
	}
	return b.String()
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

// Budgets for one inventory. On a busy node (panel-01, ~70 containers) the
// per-container stats calls used to eat the whole 20 s heartbeat context: a
// `stream=false` stats read makes dockerd wait for a second CPU sample (about
// a second, several on a loaded daemon) before it answers, so 70 containers
// at 8 in parallel ran past the deadline, and the image, network and volume
// lists that followed were called with an expired context. Their errors were
// swallowed and they went out as empty lists, so the panel's image inventory
// stopped tracking the node. Now the cheap lists run first, stats use
// `one-shot=true` (no second sample: CPU is computed against this agent's
// previous sample) and stop when their share of the budget is spent, and a
// list that fails is reported as null ("unknown", the panel keeps the last
// one) instead of empty.
const (
	statsConcurrency = 8
	perContainer     = 5 * time.Second
	// The share of the context's remaining time container details may use.
	statsShare = 0.6
	// Volume sizes are measured at most this often (du walks the data).
	volumeSizeEvery = 10 * time.Minute
)

// prevCPU remembers each container's last cumulative CPU sample, so a
// one-shot stats read (which carries no precpu_stats) still yields a
// utilisation since the previous heartbeat.
var (
	prevMu  sync.Mutex
	prevCPU = map[string][2]uint64{} // id -> {container total, system total}

	volMu    sync.Mutex
	volSizes = map[string]volSize{}
)

type volSize struct {
	mb float64
	at time.Time
}

// cpuSince returns the CPU percentage between this sample and the previous one
// kept for the container (0 on the first sample) and stores this one.
func cpuSince(id string, s *docker.StatsSample) float64 {
	if s.PreCPUStats.SystemUsage > 0 { // a two-sample answer (older engines ignore one-shot)
		return s.CPUPercent()
	}
	prevMu.Lock()
	prev, ok := prevCPU[id]
	prevCPU[id] = [2]uint64{s.CPUStats.CPUUsage.TotalUsage, s.CPUStats.SystemUsage}
	prevMu.Unlock()
	if !ok {
		return 0
	}
	cp := *s
	cp.PreCPUStats.CPUUsage.TotalUsage, cp.PreCPUStats.SystemUsage = prev[0], prev[1]
	return cp.CPUPercent()
}

// forget drops remembered samples of containers that no longer exist.
func forget(live map[string]bool) {
	prevMu.Lock()
	for id := range prevCPU {
		if !live[id] {
			delete(prevCPU, id)
		}
	}
	prevMu.Unlock()
}

// CollectInventory lists containers (with health and one-shot stats), images,
// networks and volumes. A list that cannot be read is left nil.
func CollectInventory(ctx context.Context, dc *docker.Client) (*Inventory, error) {
	cs, err := dc.ListContainers(ctx)
	if err != nil {
		return nil, err
	}
	inv := &Inventory{Containers: make([]protocol.Container, len(cs))}

	// The cheap lists first, so slow container stats can never starve them.
	if ims, err := dc.ListImages(ctx); err == nil {
		inv.Images = []protocol.Image{}
		for _, im := range ims {
			tags := im.RepoTags
			if tags == nil {
				tags = []string{}
			}
			inv.Images = append(inv.Images, protocol.Image{ID: im.ID, Tags: tags, SizeMB: round(float64(im.Size) / (1 << 20)), Created: time.Unix(im.Created, 0).UTC().Format(time.RFC3339)})
		}
	}
	if ns, err := dc.ListNetworks(ctx); err == nil {
		inv.Networks = []protocol.Network{}
		for _, n := range ns {
			subnet, gateway := n.FirstSubnet()
			inv.Networks = append(inv.Networks, protocol.Network{ID: n.ID, Name: n.Name, Driver: n.Driver, Scope: n.Scope, Internal: n.Internal, Subnet: subnet, Gateway: gateway})
		}
	}
	vs, verr := dc.ListVolumes(ctx)

	// Container details within a share of what is left of the context.
	dctx := ctx
	if dl, ok := ctx.Deadline(); ok {
		var cancel context.CancelFunc
		dctx, cancel = context.WithTimeout(ctx, time.Duration(float64(time.Until(dl))*statsShare))
		defer cancel()
	}
	// Which named volumes each container mounts, so volumes can report a use count.
	mounted := make([][]string, len(cs))
	live := map[string]bool{}
	sem := make(chan struct{}, statsConcurrency)
	var wg sync.WaitGroup
	for i, c := range cs {
		live[c.ID] = true
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
			select {
			case sem <- struct{}{}:
			case <-dctx.Done():
				return // out of budget: this container goes without details this beat
			}
			defer func() { <-sem }()
			cctx, cancel := context.WithTimeout(dctx, perContainer)
			defer cancel()
			if in, err := dc.InspectContainer(cctx, id); err == nil {
				if in.State.Health != nil {
					inv.Containers[i].Health = in.State.Health.Status
				}
				inv.Containers[i].User = in.Config.User
				inv.Containers[i].Labels = in.Config.Labels
				inv.Containers[i].RestartPolicy = in.HostConfig.RestartPolicy.Name
				if in.HostConfig.NanoCpus > 0 {
					inv.Containers[i].CPUs = round(float64(in.HostConfig.NanoCpus) / 1e9)
				}
				if in.HostConfig.Memory > 0 {
					inv.Containers[i].MemLimitMB = round(float64(in.HostConfig.Memory) / (1024 * 1024))
				}
				for _, m := range in.Mounts {
					if m.Type == "volume" && m.Name != "" {
						mounted[i] = append(mounted[i], m.Name)
					}
				}
			}
			if state != "running" {
				return
			}
			if s, err := dc.ContainerStats(cctx, id); err == nil {
				inv.Containers[i].CPUPct = round(cpuSince(id, s))
				inv.Containers[i].MemMB = round(s.MemMB())
			}
		}(i, c.ID, c.State)
	}
	wg.Wait()
	forget(live)

	if verr == nil {
		usedBy := map[string]int{}
		for _, names := range mounted {
			for _, n := range names {
				usedBy[n]++
			}
		}
		vols := make([]protocol.Volume, len(vs))
		var vwg sync.WaitGroup
		vsem := make(chan struct{}, 4)
		now := time.Now()
		for i, v := range vs {
			vols[i] = protocol.Volume{Name: v.Name, Driver: v.Driver, Mountpoint: v.Mountpoint, UsedBy: usedBy[v.Name]}
			if v.Mountpoint == "" {
				continue
			}
			volMu.Lock()
			cached, ok := volSizes[v.Name]
			volMu.Unlock()
			if ok {
				vols[i].SizeMB = cached.mb
				if now.Sub(cached.at) < volumeSizeEvery {
					continue
				}
			}
			vwg.Add(1)
			go func(i int, name, path string) {
				defer vwg.Done()
				select {
				case vsem <- struct{}{}:
				case <-ctx.Done():
					return
				}
				defer func() { <-vsem }()
				if mb, err := dirSizeMB(ctx, path); err == nil {
					vols[i].SizeMB = mb
					volMu.Lock()
					volSizes[name] = volSize{mb: mb, at: time.Now()}
					volMu.Unlock()
				}
			}(i, v.Name, v.Mountpoint)
		}
		vwg.Wait()
		inv.Volumes = append([]protocol.Volume{}, vols...)
	}
	return inv, nil
}

// dirSizeMB measures a volume's mountpoint. Docker exposes no size for local
// volumes, and walking a large one is slow, so it is bounded and best-effort.
func dirSizeMB(ctx context.Context, path string) (float64, error) {
	cctx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()
	out, err := exec.CommandContext(cctx, "du", "-sb", "--one-file-system", path).Output()
	if err != nil {
		return 0, err
	}
	fields := strings.Fields(string(out))
	if len(fields) == 0 {
		return 0, fmt.Errorf("du: empty output")
	}
	bytes, err := strconv.ParseInt(fields[0], 10, 64)
	if err != nil {
		return 0, err
	}
	return round(float64(bytes) / (1024 * 1024)), nil
}
