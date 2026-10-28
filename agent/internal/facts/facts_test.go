package facts

import (
	"os"
	"path/filepath"
	"testing"
)

func TestCollect(t *testing.T) {
	root := t.TempDir()
	os.MkdirAll(filepath.Join(root, "etc"), 0o755)
	os.MkdirAll(filepath.Join(root, "proc/sys/kernel"), 0o755)
	os.WriteFile(filepath.Join(root, "etc/os-release"), []byte("NAME=\"Ubuntu\"\nVERSION_ID=\"24.04\"\nPRETTY_NAME=\"Ubuntu 24.04.1 LTS\"\n"), 0o644)
	os.WriteFile(filepath.Join(root, "proc/sys/kernel/osrelease"), []byte("6.8.0-45-generic\n"), 0o644)
	os.WriteFile(filepath.Join(root, "proc/meminfo"), []byte("MemTotal:       8048576 kB\nMemAvailable:   4024288 kB\n"), 0o644)
	f := Collector{Root: root, DiskPath: root}.Collect()
	if f.OS != "Ubuntu 24.04.1 LTS" || f.Kernel != "6.8.0-45-generic" || f.RAMMB != 7859 || f.CPUCores < 1 || f.Arch == "" {
		t.Fatalf("%+v", f)
	}
}
