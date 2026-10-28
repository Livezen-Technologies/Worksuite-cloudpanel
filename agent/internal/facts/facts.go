// Package facts collects host facts sent at enrolment.
package facts

import (
	"bufio"
	"os"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"syscall"

	"github.com/livezen-technologies/cloudpanel/agent/internal/protocol"
)

// Collector reads facts below Root (normally "/"), to allow tests.
type Collector struct {
	Root     string
	DiskPath string
}

// Collect gathers OS, kernel, CPU, RAM, disk and arch. dockerVersion is filled by the caller.
func (c Collector) Collect() protocol.Facts {
	root := c.Root
	if root == "" {
		root = "/"
	}
	disk := c.DiskPath
	if disk == "" {
		disk = "/"
	}
	f := protocol.Facts{
		OS:       OSName(filepath.Join(root, "etc/os-release")),
		Kernel:   readTrim(filepath.Join(root, "proc/sys/kernel/osrelease")),
		CPUCores: runtime.NumCPU(),
		RAMMB:    MemTotalKB(filepath.Join(root, "proc/meminfo")) / 1024,
		Arch:     Arch(),
	}
	var st syscall.Statfs_t
	if syscall.Statfs(disk, &st) == nil {
		f.DiskGB = int64(st.Blocks) * int64(st.Bsize) / (1 << 30)
	}
	return f
}

// Arch maps GOARCH to the uname-style name the SRS uses.
func Arch() string {
	switch runtime.GOARCH {
	case "amd64":
		return "x86_64"
	case "arm64":
		return "aarch64"
	}
	return runtime.GOARCH
}

func readTrim(p string) string {
	b, err := os.ReadFile(p)
	if err != nil {
		return ""
	}
	return strings.TrimSpace(string(b))
}

// OSName parses PRETTY_NAME (or NAME VERSION_ID) from an os-release file.
func OSName(p string) string {
	fh, err := os.Open(p)
	if err != nil {
		return runtime.GOOS
	}
	defer fh.Close()
	kv := map[string]string{}
	s := bufio.NewScanner(fh)
	for s.Scan() {
		k, v, ok := strings.Cut(s.Text(), "=")
		if !ok {
			continue
		}
		if uq, err := strconv.Unquote(v); err == nil {
			v = uq
		} else {
			v = strings.Trim(v, `"'`)
		}
		kv[k] = v
	}
	if v := kv["PRETTY_NAME"]; v != "" {
		return v
	}
	return strings.TrimSpace(kv["NAME"] + " " + kv["VERSION_ID"])
}

// MemTotalKB reads MemTotal from /proc/meminfo.
func MemTotalKB(p string) int64 {
	m := Meminfo(p)
	return int64(m["MemTotal"])
}

// Meminfo parses /proc/meminfo into kB values.
func Meminfo(p string) map[string]uint64 {
	out := map[string]uint64{}
	fh, err := os.Open(p)
	if err != nil {
		return out
	}
	defer fh.Close()
	s := bufio.NewScanner(fh)
	for s.Scan() {
		k, v, ok := strings.Cut(s.Text(), ":")
		if !ok {
			continue
		}
		fs := strings.Fields(v)
		if len(fs) == 0 {
			continue
		}
		n, _ := strconv.ParseUint(fs[0], 10, 64)
		out[k] = n
	}
	return out
}
