// Package docker is a minimal Docker Engine API client over the local unix
// socket, built on net/http only.
package docker

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/binary"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"sort"
	"strings"
	"time"
)

// Client talks to dockerd. HTTP may be replaced in tests.
type Client struct {
	HTTP *http.Client
	Base string // e.g. "http://docker"
}

// New returns a client for the given unix socket path.
func New(socket string) *Client {
	tr := &http.Transport{
		DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
			var d net.Dialer
			return d.DialContext(ctx, "unix", socket)
		},
		MaxIdleConns:    8,
		IdleConnTimeout: 30 * time.Second,
	}
	return &Client{HTTP: &http.Client{Transport: tr}, Base: "http://docker"}
}

// APIError is a non-2xx response from dockerd.
type APIError struct {
	Status  int
	Message string
}

func (e *APIError) Error() string { return fmt.Sprintf("docker: %d %s", e.Status, e.Message) }

// IsNotFound reports a 404.
func IsNotFound(err error) bool {
	ae, ok := err.(*APIError)
	return ok && ae.Status == http.StatusNotFound
}

func (c *Client) do(ctx context.Context, method, path string, q url.Values, body any, hdr map[string]string) (*http.Response, error) {
	var rd io.Reader
	if body != nil {
		b, err := json.Marshal(body)
		if err != nil {
			return nil, err
		}
		rd = bytes.NewReader(b)
	}
	u := c.Base + path
	if len(q) > 0 {
		u += "?" + q.Encode()
	}
	req, err := http.NewRequestWithContext(ctx, method, u, rd)
	if err != nil {
		return nil, err
	}
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	for k, v := range hdr {
		req.Header.Set(k, v)
	}
	resp, err := c.HTTP.Do(req)
	if err != nil {
		return nil, err
	}
	if resp.StatusCode >= 300 && resp.StatusCode != http.StatusNotModified {
		defer resp.Body.Close()
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 64<<10))
		var m struct {
			Message string `json:"message"`
		}
		msg := strings.TrimSpace(string(b))
		if json.Unmarshal(b, &m) == nil && m.Message != "" {
			msg = m.Message
		}
		return nil, &APIError{Status: resp.StatusCode, Message: msg}
	}
	return resp, nil
}

func (c *Client) call(ctx context.Context, method, path string, q url.Values, body, out any) error {
	resp, err := c.do(ctx, method, path, q, body, nil)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if out == nil {
		_, _ = io.Copy(io.Discard, resp.Body)
		return nil
	}
	if err := json.NewDecoder(resp.Body).Decode(out); err != nil && err != io.EOF {
		return err
	}
	return nil
}

// ---- system ----

type Version struct {
	Version    string `json:"Version"`
	APIVersion string `json:"ApiVersion"`
	Arch       string `json:"Arch"`
}

func (c *Client) Version(ctx context.Context) (*Version, error) {
	var v Version
	return &v, c.call(ctx, "GET", "/version", nil, nil, &v)
}

// ---- containers ----

type Port struct {
	IP          string `json:"IP"`
	PrivatePort int    `json:"PrivatePort"`
	PublicPort  int    `json:"PublicPort"`
	Type        string `json:"Type"`
}

type ContainerSummary struct {
	ID      string            `json:"Id"`
	Names   []string          `json:"Names"`
	Image   string            `json:"Image"`
	State   string            `json:"State"`
	Status  string            `json:"Status"`
	Created int64             `json:"Created"`
	Ports   []Port            `json:"Ports"`
	Labels  map[string]string `json:"Labels"`
}

func (c *Client) ListContainers(ctx context.Context) ([]ContainerSummary, error) {
	var out []ContainerSummary
	return out, c.call(ctx, "GET", "/containers/json", url.Values{"all": {"1"}}, nil, &out)
}

type ContainerInspect struct {
	ID    string `json:"Id"`
	Name  string `json:"Name"`
	State struct {
		Status   string `json:"Status"`
		Running  bool   `json:"Running"`
		ExitCode int    `json:"ExitCode"`
		Health   *struct {
			Status string `json:"Status"`
		} `json:"Health"`
	} `json:"State"`
	Config struct {
		Tty bool `json:"Tty"`
	} `json:"Config"`
	NetworkSettings struct {
		IPAddress string `json:"IPAddress"`
		Networks  map[string]struct {
			IPAddress string `json:"IPAddress"`
		} `json:"Networks"`
	} `json:"NetworkSettings"`
}

// IP returns the container's first internal IPv4 address ("" if none).
func (ci *ContainerInspect) IP() string {
	if ci.NetworkSettings.IPAddress != "" {
		return ci.NetworkSettings.IPAddress
	}
	names := make([]string, 0, len(ci.NetworkSettings.Networks))
	for n := range ci.NetworkSettings.Networks {
		names = append(names, n)
	}
	sort.Strings(names)
	for _, n := range names {
		if ip := ci.NetworkSettings.Networks[n].IPAddress; ip != "" {
			return ip
		}
	}
	return ""
}

// ImageExists reports whether the image reference is present locally.
func (c *Client) ImageExists(ctx context.Context, ref string) (bool, error) {
	var out struct {
		ID string `json:"Id"`
	}
	err := c.call(ctx, "GET", "/images/"+url.PathEscape(ref)+"/json", nil, nil, &out)
	if IsNotFound(err) {
		return false, nil
	}
	return err == nil && out.ID != "", err
}

func (c *Client) InspectContainer(ctx context.Context, id string) (*ContainerInspect, error) {
	var out ContainerInspect
	return &out, c.call(ctx, "GET", "/containers/"+url.PathEscape(id)+"/json", nil, nil, &out)
}

// StatsSample is the subset of /containers/{id}/stats used for CPU/mem.
type StatsSample struct {
	CPUStats    cpuStats `json:"cpu_stats"`
	PreCPUStats cpuStats `json:"precpu_stats"`
	MemoryStats struct {
		Usage uint64            `json:"usage"`
		Stats map[string]uint64 `json:"stats"`
	} `json:"memory_stats"`
}

type cpuStats struct {
	CPUUsage struct {
		TotalUsage  uint64   `json:"total_usage"`
		PercpuUsage []uint64 `json:"percpu_usage"`
	} `json:"cpu_usage"`
	SystemUsage uint64 `json:"system_cpu_usage"`
	OnlineCPUs  int    `json:"online_cpus"`
}

// ContainerStats does a single non-streaming stats read.
func (c *Client) ContainerStats(ctx context.Context, id string) (*StatsSample, error) {
	var s StatsSample
	return &s, c.call(ctx, "GET", "/containers/"+url.PathEscape(id)+"/stats", url.Values{"stream": {"false"}}, nil, &s)
}

// CPUPercent computes the docker-cli style CPU percentage.
func (s *StatsSample) CPUPercent() float64 {
	cpuDelta := float64(s.CPUStats.CPUUsage.TotalUsage) - float64(s.PreCPUStats.CPUUsage.TotalUsage)
	sysDelta := float64(s.CPUStats.SystemUsage) - float64(s.PreCPUStats.SystemUsage)
	n := s.CPUStats.OnlineCPUs
	if n == 0 {
		n = len(s.CPUStats.CPUUsage.PercpuUsage)
	}
	if n == 0 {
		n = 1
	}
	if cpuDelta <= 0 || sysDelta <= 0 {
		return 0
	}
	return cpuDelta / sysDelta * float64(n) * 100
}

// MemMB returns usage minus page cache, in MiB.
func (s *StatsSample) MemMB() float64 {
	u := s.MemoryStats.Usage
	cache := s.MemoryStats.Stats["inactive_file"]
	if cache == 0 {
		cache = s.MemoryStats.Stats["cache"]
	}
	if cache < u {
		u -= cache
	}
	return float64(u) / (1 << 20)
}

func (c *Client) StartContainer(ctx context.Context, id string) error {
	return c.call(ctx, "POST", "/containers/"+url.PathEscape(id)+"/start", nil, nil, nil)
}

func timeoutQ(t int) url.Values {
	if t <= 0 {
		return nil
	}
	return url.Values{"t": {fmt.Sprint(t)}}
}

func (c *Client) StopContainer(ctx context.Context, id string, timeout int) error {
	return c.call(ctx, "POST", "/containers/"+url.PathEscape(id)+"/stop", timeoutQ(timeout), nil, nil)
}

func (c *Client) RestartContainer(ctx context.Context, id string, timeout int) error {
	return c.call(ctx, "POST", "/containers/"+url.PathEscape(id)+"/restart", timeoutQ(timeout), nil, nil)
}

func (c *Client) PauseContainer(ctx context.Context, id string) error {
	return c.call(ctx, "POST", "/containers/"+url.PathEscape(id)+"/pause", nil, nil, nil)
}

func (c *Client) UnpauseContainer(ctx context.Context, id string) error {
	return c.call(ctx, "POST", "/containers/"+url.PathEscape(id)+"/unpause", nil, nil, nil)
}

// RemoveContainer removes a container; anonymous volumes are only removed when removeVolumes is set.
func (c *Client) RemoveContainer(ctx context.Context, id string, force, removeVolumes bool) error {
	q := url.Values{"force": {fmt.Sprint(force)}, "v": {fmt.Sprint(removeVolumes)}}
	return c.call(ctx, "DELETE", "/containers/"+url.PathEscape(id), q, nil, nil)
}

// CreateContainer posts a raw create body.
func (c *Client) CreateContainer(ctx context.Context, name string, body any) (string, error) {
	var q url.Values
	if name != "" {
		q = url.Values{"name": {name}}
	}
	var out struct {
		ID       string   `json:"Id"`
		Warnings []string `json:"Warnings"`
	}
	if err := c.call(ctx, "POST", "/containers/create", q, body, &out); err != nil {
		return "", err
	}
	return out.ID, nil
}

// ConnectNetwork attaches a container to an additional network.
func (c *Client) ConnectNetwork(ctx context.Context, network, container string, aliases []string) error {
	body := map[string]any{"Container": container}
	if len(aliases) > 0 {
		body["EndpointConfig"] = map[string]any{"Aliases": aliases}
	}
	return c.call(ctx, "POST", "/networks/"+url.PathEscape(network)+"/connect", nil, body, nil)
}

// Logs returns the last `tail` lines of stdout+stderr as text.
func (c *Client) Logs(ctx context.Context, id string, tail int, timestamps bool) (string, error) {
	insp, err := c.InspectContainer(ctx, id)
	if err != nil {
		return "", err
	}
	q := url.Values{"stdout": {"1"}, "stderr": {"1"}, "tail": {fmt.Sprint(tail)}, "timestamps": {fmt.Sprint(timestamps)}}
	resp, err := c.do(ctx, "GET", "/containers/"+url.PathEscape(id)+"/logs", q, nil, nil)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	raw, err := io.ReadAll(io.LimitReader(resp.Body, 8<<20))
	if err != nil {
		return "", err
	}
	if insp.Config.Tty {
		return string(raw), nil
	}
	return string(Demux(raw)), nil
}

// Demux strips the 8-byte stream headers from a multiplexed log stream.
func Demux(raw []byte) []byte {
	var out bytes.Buffer
	for len(raw) >= 8 {
		if raw[0] > 2 || raw[1] != 0 || raw[2] != 0 || raw[3] != 0 {
			out.Write(raw) // not multiplexed after all
			return out.Bytes()
		}
		n := int(binary.BigEndian.Uint32(raw[4:8]))
		raw = raw[8:]
		if n > len(raw) {
			n = len(raw)
		}
		out.Write(raw[:n])
		raw = raw[n:]
	}
	out.Write(raw)
	return out.Bytes()
}

// PruneReport is returned by prune endpoints.
type PruneReport map[string]any

func (c *Client) prune(ctx context.Context, path string, filters map[string][]string) (PruneReport, error) {
	var q url.Values
	if len(filters) > 0 {
		f, _ := json.Marshal(filters)
		q = url.Values{"filters": {string(f)}}
	}
	var out PruneReport
	return out, c.call(ctx, "POST", path, q, nil, &out)
}

func (c *Client) PruneContainers(ctx context.Context, filters map[string][]string) (PruneReport, error) {
	return c.prune(ctx, "/containers/prune", filters)
}

func (c *Client) PruneImages(ctx context.Context, filters map[string][]string) (PruneReport, error) {
	return c.prune(ctx, "/images/prune", filters)
}

// ---- images ----

type ImageSummary struct {
	ID       string   `json:"Id"`
	RepoTags []string `json:"RepoTags"`
	Size     int64    `json:"Size"`
	Created  int64    `json:"Created"`
}

func (c *Client) ListImages(ctx context.Context) ([]ImageSummary, error) {
	var out []ImageSummary
	return out, c.call(ctx, "GET", "/images/json", nil, nil, &out)
}

// RegistryAuth is encoded into X-Registry-Auth.
type RegistryAuth struct {
	Username      string `json:"username,omitempty"`
	Password      string `json:"password,omitempty"`
	ServerAddress string `json:"serveraddress,omitempty"`
	IdentityToken string `json:"identitytoken,omitempty"`
}

func (a *RegistryAuth) header() string {
	b, _ := json.Marshal(a)
	return base64.URLEncoding.EncodeToString(b)
}

// PullImage pulls image:tag and consumes the progress stream, calling
// progress (may be nil) with status lines.
func (c *Client) PullImage(ctx context.Context, image, tag string, auth *RegistryAuth, progress func(string)) error {
	q := url.Values{"fromImage": {image}}
	if tag != "" {
		q.Set("tag", tag)
	}
	hdr := map[string]string{}
	if auth != nil {
		hdr["X-Registry-Auth"] = auth.header()
	}
	resp, err := c.do(ctx, "POST", "/images/create", q, nil, hdr)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	dec := json.NewDecoder(resp.Body)
	for {
		var m struct {
			Status string `json:"status"`
			ID     string `json:"id"`
			Error  string `json:"error"`
		}
		if err := dec.Decode(&m); err == io.EOF {
			return nil
		} else if err != nil {
			return err
		}
		if m.Error != "" {
			return fmt.Errorf("pull %s: %s", image, m.Error)
		}
		if progress != nil && m.Status != "" && m.ID == "" {
			progress(m.Status)
		}
	}
}

func (c *Client) RemoveImage(ctx context.Context, image string, force bool) (any, error) {
	var out any
	return out, c.call(ctx, "DELETE", "/images/"+url.PathEscape(image), url.Values{"force": {fmt.Sprint(force)}}, nil, &out)
}

// ---- networks ----

type NetworkSummary struct {
	ID       string            `json:"Id"`
	Name     string            `json:"Name"`
	Driver   string            `json:"Driver"`
	Scope    string            `json:"Scope"`
	Internal bool              `json:"Internal"`
	Labels   map[string]string `json:"Labels"`
	IPAM     struct {
		Config []struct {
			Subnet  string `json:"Subnet"`
			Gateway string `json:"Gateway"`
		} `json:"Config"`
	} `json:"IPAM"`
}

// FirstSubnet returns the first IPAM subnet and gateway, if any.
func (n NetworkSummary) FirstSubnet() (subnet, gateway string) {
	for _, c := range n.IPAM.Config {
		if c.Subnet != "" {
			return c.Subnet, c.Gateway
		}
	}
	return "", ""
}

func (c *Client) ListNetworks(ctx context.Context) ([]NetworkSummary, error) {
	var out []NetworkSummary
	return out, c.call(ctx, "GET", "/networks", nil, nil, &out)
}

func (c *Client) CreateNetwork(ctx context.Context, body any) (string, error) {
	var out struct {
		ID string `json:"Id"`
	}
	return out.ID, c.call(ctx, "POST", "/networks/create", nil, body, &out)
}

func (c *Client) RemoveNetwork(ctx context.Context, id string) error {
	return c.call(ctx, "DELETE", "/networks/"+url.PathEscape(id), nil, nil, nil)
}

// ---- volumes ----

type VolumeSummary struct {
	Name       string            `json:"Name"`
	Driver     string            `json:"Driver"`
	Mountpoint string            `json:"Mountpoint"`
	Labels     map[string]string `json:"Labels"`
}

func (c *Client) ListVolumes(ctx context.Context) ([]VolumeSummary, error) {
	var out struct {
		Volumes []VolumeSummary `json:"Volumes"`
	}
	return out.Volumes, c.call(ctx, "GET", "/volumes", nil, nil, &out)
}

func (c *Client) CreateVolume(ctx context.Context, body any) (*VolumeSummary, error) {
	var out VolumeSummary
	return &out, c.call(ctx, "POST", "/volumes/create", nil, body, &out)
}

func (c *Client) RemoveVolume(ctx context.Context, name string, force bool) error {
	return c.call(ctx, "DELETE", "/volumes/"+url.PathEscape(name), url.Values{"force": {fmt.Sprint(force)}}, nil, nil)
}
