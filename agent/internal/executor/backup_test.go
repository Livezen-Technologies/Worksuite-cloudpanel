package executor

import (
	"compress/gzip"
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/json"
	"encoding/pem"
	"io"
	"math/big"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

type fakeTools struct {
	runs    [][]string
	envs    [][]string
	streams [][]string
	// restoreFiles are written under --target= on "restic restore".
	restoreFiles map[string]string
	checkFails   bool
}

func (f *fakeTools) install(e *Executor) {
	e.LookPath = func(n string) (string, error) { return "/usr/bin/" + n, nil }
	e.Run = func(_ context.Context, _ string, env []string, name string, args ...string) ([]byte, error) {
		f.runs = append(f.runs, append([]string{name}, args...))
		f.envs = append(f.envs, env)
		joined := strings.Join(args, " ")
		switch {
		case strings.Contains(joined, " backup "):
			return []byte("{\"message_type\":\"status\"}\n{\"message_type\":\"summary\",\"snapshot_id\":\"snap1\",\"total_bytes_processed\":2048}\n"), nil
		case strings.Contains(joined, " snapshots "):
			if f.checkFails {
				return []byte("Fatal: unable to open config file: Stat: 403 Forbidden"), io.ErrUnexpectedEOF
			}
			return []byte(`[{"id":"a"},{"id":"b"}]`), nil
		case strings.Contains(joined, " stats "):
			return []byte(`{"total_size":5242880}`), nil
		case strings.Contains(joined, " forget "):
			return []byte(`[{"keep":[{}],"remove":[{},{}]}]`), nil
		case strings.Contains(joined, " check "):
			if f.checkFails {
				return []byte("Fatal: repository contains errors"), io.ErrUnexpectedEOF
			}
			return []byte("no errors were found"), nil
		case strings.Contains(joined, " restore "):
			for _, a := range args {
				if t, ok := strings.CutPrefix(a, "--target="); ok {
					for rel, body := range f.restoreFiles {
						p := filepath.Join(t, rel)
						os.MkdirAll(filepath.Dir(p), 0o700)
						os.WriteFile(p, []byte(body), 0o600)
					}
				}
			}
		}
		return []byte("ok"), nil
	}
	e.Stream = func(_ context.Context, env []string, stdin io.Reader, stdout io.Writer, name string, args ...string) error {
		f.streams = append(f.streams, append([]string{name}, args...))
		f.envs = append(f.envs, env)
		if stdin != nil {
			io.Copy(io.Discard, stdin)
		}
		if stdout != nil {
			if strings.Contains(strings.Join(args, " "), "pg_dump") {
				stdout.Write([]byte("PGDMP-fake-dump"))
			} else {
				stdout.Write([]byte("tar-bytes"))
			}
		}
		return nil
	}
}

func TestBackupRunWithDumpRetentionAndCheck(t *testing.T) {
	e, _, fp, _ := setup(t)
	f := &fakeTools{}
	f.install(e)
	payload := `{"repository":"/repo","env":{"RESTIC_PASSWORD":"pw"},"paths":[],"tags":["dockermgr","policy-1"],` +
		`"dump":{"engine":"postgres","container":"db-shop","database":"shop","user":"app","password":"s3cret"},` +
		`"forget":{"keep_daily":7,"keep_weekly":4,"tag":"policy-1"},"check":{"read_data_subset":"10%"}}`
	r, err := e.Handle(context.Background(), cmd("bk1", "backup.run", payload, now))
	if err != nil {
		t.Fatal(err, r.Message)
	}
	dump := strings.Join(f.streams[0], " ")
	if dump != "/usr/bin/docker exec -e PGPASSWORD db-shop pg_dump -U app --format=custom --no-owner shop" {
		t.Fatal(dump)
	}
	if strings.Contains(dump, "s3cret") || !strings.Contains(strings.Join(f.envs[0], " "), "PGPASSWORD=s3cret") {
		t.Fatal("password must travel via env, not argv")
	}
	dumpDir := filepath.Join(e.StateDir, "dumps", "db-shop")
	if b, _ := os.ReadFile(filepath.Join(dumpDir, "postgres.dump")); string(b) != "PGDMP-fake-dump" {
		t.Fatal("dump not written", string(b))
	}
	var backup, forget, check string
	for _, c := range f.runs {
		j := strings.Join(c, " ")
		switch {
		case strings.Contains(j, " backup "):
			backup = j
		case strings.Contains(j, " forget "):
			forget = j
		case strings.Contains(j, " check "):
			check = j
		}
	}
	if backup != "/usr/bin/restic --repo=/repo backup --json --tag=dockermgr --tag=policy-1 -- "+dumpDir {
		t.Fatal(backup)
	}
	if forget != "/usr/bin/restic --repo=/repo forget --prune --json --keep-daily=7 --keep-weekly=4 --tag=policy-1" {
		t.Fatal(forget)
	}
	if check != "/usr/bin/restic --repo=/repo check --read-data-subset=10%" {
		t.Fatal(check)
	}
	out := r.Output.(map[string]any)
	if out["snapshot_id"] != "snap1" || out["check"].(map[string]any)["ok"] != true || out["forget"].(map[string]any)["removed"] != 2 {
		t.Fatalf("%#v", out)
	}
	_ = fp
}

func TestBackupRunRejectsBadDump(t *testing.T) {
	e, _, _, _ := setup(t)
	(&fakeTools{}).install(e)
	for _, p := range []string{
		`{"repository":"/r","dump":{"engine":"oracle","container":"x"}}`,
		`{"repository":"/r","dump":{"engine":"postgres","container":"x; rm -rf /"}}`,
		`{"repository":"/r","dump":{"engine":"postgres","container":"x","database":"--help"}}`,
		`{"repository":"/r","paths":["/d"],"forget":{"keep_within":"1d;x"}}`,
	} {
		if _, err := e.Handle(context.Background(), cmd("bad"+p[30:34], "backup.run", p, now)); err == nil {
			t.Fatal("accepted", p)
		}
	}
}

func TestOtherDumpEngines(t *testing.T) {
	e, _, _, _ := setup(t)
	f := &fakeTools{}
	f.install(e)
	for _, c := range []struct{ engine, want string }{
		{"mysql", "/usr/bin/docker exec -e MYSQL_PWD my mysqldump -u root --single-transaction --routines --events --all-databases"},
		{"mongodb", "/usr/bin/docker exec my mongodump --archive --gzip --username=u --password=p --authenticationDatabase=admin"},
	} {
		d := &DumpSpec{Engine: c.engine, Container: "my", Password: "p"}
		if c.engine == "mongodb" {
			d.User = "u"
		}
		if _, _, err := e.Dump(context.Background(), d); err != nil {
			t.Fatal(err)
		}
		if got := strings.Join(f.streams[len(f.streams)-1], " "); got != c.want {
			t.Fatal(got)
		}
	}
}

func TestRestoreDumpLoadsIntoContainer(t *testing.T) {
	e, _, _, _ := setup(t)
	f := &fakeTools{}
	f.install(e)
	src := filepath.Join(e.StateDir, "dumps", "db-shop", "postgres.dump")
	f.restoreFiles = map[string]string{src: "PGDMP..."}
	r, err := e.Handle(context.Background(), cmd("rs1", "restore.run", `{"repository":"/repo","snapshot":"snap1","dump":{"engine":"postgres","container":"db-shop","database":"shop","user":"app"}}`, now))
	if err != nil {
		t.Fatal(err, r.Message)
	}
	load := strings.Join(f.streams[len(f.streams)-1], " ")
	if load != "/usr/bin/docker exec -i db-shop pg_restore -U app --clean --if-exists --no-owner -d shop" {
		t.Fatal(load)
	}
	if !strings.Contains(strings.Join(f.runs[len(f.runs)-1], " "), "--include="+filepath.Dir(src)) {
		t.Fatal(f.runs)
	}
}

func TestBackupTestRestoresAndValidates(t *testing.T) {
	e, _, _, _ := setup(t)
	f := &fakeTools{restoreFiles: map[string]string{"var/lib/dockermgr/dumps/db/postgres.dump": "PGDMP-ok"}}
	f.install(e)
	r, err := e.Handle(context.Background(), cmd("bt1", "backup.test", `{"repository":"/repo","snapshot":"snap1","engine":"postgres","check":{"read_data_subset":"5%"}}`, now))
	if err != nil {
		t.Fatal(err, r.Message)
	}
	out := r.Output.(map[string]any)
	if out["dump_valid"] != true || out["files"].(int64) != 1 {
		t.Fatalf("%#v", out)
	}
	entries, _ := os.ReadDir(filepath.Join(e.StateDir, "tmp"))
	if len(entries) != 0 {
		t.Fatal("scratch not cleaned")
	}
	f.restoreFiles = map[string]string{"x/postgres.dump": "garbage"}
	if _, err := e.Handle(context.Background(), cmd("bt2", "backup.test", `{"repository":"/repo","engine":"postgres"}`, now)); err == nil {
		t.Fatal("invalid dump accepted")
	}
	f.restoreFiles = map[string]string{"a": "b"}
	f.checkFails = true
	if _, err := e.Handle(context.Background(), cmd("bt3", "backup.test", `{"repository":"/repo","check":{}}`, now)); err == nil {
		t.Fatal("failed check accepted")
	}
}

func TestBackupExportUploads(t *testing.T) {
	e, _, _, _ := setup(t)
	f := &fakeTools{}
	f.install(e)
	var got []byte
	var hdr http.Header
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hdr = r.Header
		if r.Method != http.MethodPut || r.ContentLength <= 0 {
			w.WriteHeader(400)
			return
		}
		got, _ = io.ReadAll(r.Body)
	}))
	defer srv.Close()
	p, _ := json.Marshal(map[string]any{"repository": "/repo", "snapshot": "snap1", "path": "/var/lib/docker/volumes/v/_data",
		"upload": map[string]any{"url": srv.URL + "/put?sig=1", "method": "PUT", "headers": map[string]string{"X-Token": "t"}}})
	r, err := e.Handle(context.Background(), cmd("ex1", "backup.export", string(p), now))
	if err != nil {
		t.Fatal(err, r.Message)
	}
	if s := strings.Join(f.streams[0], " "); s != "/usr/bin/restic --repo=/repo dump --archive=tar snap1 /var/lib/docker/volumes/v/_data" {
		t.Fatal(s)
	}
	zr, err := gzip.NewReader(strings.NewReader(string(got)))
	if err != nil {
		t.Fatal(err)
	}
	plain, _ := io.ReadAll(zr)
	if string(plain) != "tar-bytes" || hdr.Get("X-Token") != "t" || hdr.Get("Content-Type") != "application/gzip" {
		t.Fatal(string(plain), hdr)
	}
	if r.Output.(map[string]any)["sha256"] != hdr.Get("X-Content-SHA256") {
		t.Fatal("sha mismatch")
	}
	if _, err := e.Handle(context.Background(), cmd("ex2", "backup.export", `{"repository":"/repo","upload":{"url":"file:///etc/passwd"}}`, now)); err == nil {
		t.Fatal("file url accepted")
	}
}

func TestProxyConfigWritesAndPrunes(t *testing.T) {
	e, _, _, dir := setup(t)
	e.ProxyDir = filepath.Join(dir, "dynamic")
	os.MkdirAll(e.ProxyDir, 0o755)
	os.WriteFile(filepath.Join(e.ProxyDir, "dmp-old.yml"), []byte("x"), 0o644)
	os.WriteFile(filepath.Join(e.ProxyDir, "manual.yml"), []byte("x"), 0o644)
	r, err := e.Handle(context.Background(), cmd("pc1", "proxy.config", `{"files":{"dmp-domains.yml":"http:\n  routers: {}\n"},"prune_prefix":"dmp-","secrets":{"CF_DNS_API_TOKEN":"tok"}}`, now))
	if err != nil {
		t.Fatal(err)
	}
	if b, _ := os.ReadFile(filepath.Join(e.ProxyDir, "dmp-domains.yml")); !strings.Contains(string(b), "routers") {
		t.Fatal("not written")
	}
	if _, err := os.Stat(filepath.Join(e.ProxyDir, "dmp-old.yml")); err == nil {
		t.Fatal("stale file kept")
	}
	if _, err := os.Stat(filepath.Join(e.ProxyDir, "manual.yml")); err != nil {
		t.Fatal("unmanaged file removed")
	}
	if b, _ := os.ReadFile(filepath.Join(dir, "secrets", "CF_DNS_API_TOKEN")); string(b) != "tok" {
		t.Fatal("secret not written")
	}
	if rm := r.Output.(map[string]any)["removed"].([]string); len(rm) != 1 {
		t.Fatal(rm)
	}
	if _, err := e.Handle(context.Background(), cmd("pc2", "proxy.config", `{"files":{"../../etc/x.yml":"a"}}`, now)); err == nil {
		t.Fatal("traversal accepted")
	}
}

func TestProxyCertsReadsAcmeJSONWithoutKeys(t *testing.T) {
	e, _, _, dir := setup(t)
	e.AcmeJSON = filepath.Join(dir, "acme.json")
	k, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	notAfter := time.Date(2027, 1, 20, 0, 0, 0, 0, time.UTC)
	tpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "shop.example.com"}, Issuer: pkix.Name{CommonName: "R11"},
		NotBefore: notAfter.Add(-90 * 24 * time.Hour), NotAfter: notAfter, DNSNames: []string{"shop.example.com"}}
	der, _ := x509.CreateCertificate(rand.Reader, tpl, tpl, &k.PublicKey, k)
	certB64 := base64.StdEncoding.EncodeToString(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}))
	acme := `{"le":{"Account":{"Email":"a@b"},"Certificates":[{"domain":{"main":"shop.example.com","sans":["www.shop.example.com"]},"certificate":"` + certB64 + `","key":"PRIVATEKEYDATA","Store":"default"}]}}`
	os.WriteFile(e.AcmeJSON, []byte(acme), 0o600)
	r, err := e.Handle(context.Background(), cmd("ce1", "proxy.certs", `{}`, now))
	if err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(r.Output)
	if strings.Contains(string(b), "PRIVATEKEYDATA") {
		t.Fatal("private key leaked")
	}
	certs := r.Output.(map[string]any)["certificates"].([]map[string]any)
	if len(certs) != 1 || certs[0]["domain"] != "shop.example.com" || certs[0]["not_after"] != "2027-01-20T00:00:00Z" || certs[0]["resolver"] != "le" {
		t.Fatalf("%#v", certs)
	}
	e.AcmeJSON = filepath.Join(dir, "missing.json")
	r, err = e.Handle(context.Background(), cmd("ce2", "proxy.certs", `{}`, now))
	if err != nil || r.Output.(map[string]any)["missing"] != true {
		t.Fatal(err, r.Output)
	}
}

func TestBackupProbeReportsReachabilityAndSize(t *testing.T) {
	e, _, _, _ := setup(t)
	f := &fakeTools{}
	f.install(e)
	out, err := e.backupProbe(context.Background(), []byte(`{"repository":"rclone:sftp:/backups/x","env":{"RESTIC_PASSWORD":"pw","RCLONE_CONFIG_SFTP_TYPE":"sftp","RCLONE_CONFIG_SFTP_HOST":"backup.example.com"}}`))
	if err != nil {
		t.Fatal(err)
	}
	m := out.(map[string]any)
	if m["reachable"] != true || m["snapshots"] != 2 || m["stored_bytes"] != int64(5242880) {
		t.Fatalf("%#v", m)
	}
	if !strings.Contains(strings.Join(f.envs[0], " "), "RCLONE_CONFIG_SFTP_HOST=backup.example.com") {
		t.Fatal("rclone remote settings travel via env")
	}
	f.checkFails = true
	out, err = e.backupProbe(context.Background(), []byte(`{"repository":"/repo","env":{"RESTIC_PASSWORD":"pw"}}`))
	if err != nil || out.(map[string]any)["reachable"] != false || !strings.Contains(out.(map[string]any)["message"].(string), "403") {
		t.Fatalf("an unreachable repository is a result: %v %v", out, err)
	}
}

func TestForgetWithoutPruneForObjectLockedBuckets(t *testing.T) {
	e, _, _, _ := setup(t)
	f := &fakeTools{}
	f.install(e)
	if _, err := e.backupRun(context.Background(), []byte(`{"repository":"/repo","env":{"RESTIC_PASSWORD":"pw"},"paths":["/srv/data"],"forget":{"keep_daily":7,"no_prune":true}}`), func(int, string) {}); err != nil {
		t.Fatal(err)
	}
	for _, c := range f.runs {
		if j := strings.Join(c, " "); strings.Contains(j, " forget ") && strings.Contains(j, "--prune") {
			t.Fatal("an object-locked repository must not be pruned:", j)
		}
	}
}
