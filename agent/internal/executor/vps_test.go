package executor

import (
	"context"
	"testing"
	"time"
)

const vpsKey = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKhkp4Qd0lVbq3KXc8fWl5WQh2v0b3d0aTVpY2hrZXk user@laptop"

func vpsPayload(extra string) string {
	return `{"name":"vps-abc","hostname":"web-1","image":"nestybox/ubuntu-noble-systemd","memory_mb":2048,"cpus":2,"ssh_port":22001,"authorized_keys":["` + vpsKey + `"]` + extra + `}`
}

func TestVPSCreateRunsUnderSysboxOnly(t *testing.T) {
	e, fd, _, _ := setup(t)
	fd.runtimes = `"runc":{},"sysbox-runc":{"path":"/usr/bin/sysbox-runc"}`
	r, err := e.Handle(context.Background(), cmd("v1", "vps.create", vpsPayload(""), now.Add(-time.Minute)))
	if err != nil || r.Status != "succeeded" {
		t.Fatalf("vps.create: %v %+v", err, r)
	}
	hc := fd.body["HostConfig"].(map[string]any)
	if hc["Runtime"] != "sysbox-runc" || hc["Privileged"] != false {
		t.Fatalf("must run unprivileged under sysbox: %v", hc)
	}
	if fd.body["Hostname"] != "web-1" || fd.body["Labels"].(map[string]any)[VPSLabel] != "true" {
		t.Fatalf("hostname and vps label: %v", fd.body)
	}
	if !fd.has("POST /containers/newid/exec") || !fd.has("POST /exec/ex1/start") {
		t.Fatal("the SSH key is installed with an exec")
	}
}

func TestVPSCreateRefusesWithoutSysbox(t *testing.T) {
	e, fd, _, _ := setup(t)
	fd.runtimes = `"runc":{}`
	r, _ := e.Handle(context.Background(), cmd("v2", "vps.create", vpsPayload(""), now.Add(-time.Minute)))
	if r.Status == "succeeded" || fd.has("POST /containers/create") {
		t.Fatalf("no VPS without the sysbox runtime: %+v", r)
	}
}

func TestVPSSpecRejectsBadInput(t *testing.T) {
	bad := []VPSSpec{
		{Name: "v", Hostname: "Bad_Host", Image: "ubuntu", MemoryMB: 512, CPUs: 1, SSHPort: 22001, AuthorizedKey: []string{vpsKey}},
		{Name: "v", Hostname: "ok", Image: "ubuntu", MemoryMB: 512, CPUs: 1, SSHPort: 22, AuthorizedKey: []string{vpsKey}},
		{Name: "v", Hostname: "ok", Image: "ubuntu", MemoryMB: 512, CPUs: 1, SSHPort: 22001, AuthorizedKey: []string{"not a key; rm -rf /"}},
		{Name: "v", Hostname: "ok", Image: "ubuntu", MemoryMB: 512, CPUs: 1, SSHPort: 22001},
		{Name: "v", Hostname: "ok", Image: "ubuntu", MemoryMB: 64, CPUs: 1, SSHPort: 22001, AuthorizedKey: []string{vpsKey}},
	}
	for i, s := range bad {
		if _, err := BuildVPSBody(&s); err == nil {
			t.Errorf("case %d accepted", i)
		}
	}
}
