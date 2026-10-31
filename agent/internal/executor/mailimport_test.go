package executor

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

func TestMailImportRunsImapsyncWithPasswordsInFiles(t *testing.T) {
	state := t.TempDir()
	var args []string
	var seen map[string]string
	e := &Executor{StateDir: state, Run: func(_ context.Context, _ string, _ []string, name string, a ...string) ([]byte, error) {
		args = append([]string{name}, a...)
		// The password files exist while imapsync runs.
		for i, v := range a {
			if v == "-v" {
				dir := strings.SplitN(a[i+1], ":", 2)[0]
				p1, _ := os.ReadFile(filepath.Join(dir, "p1"))
				p2, _ := os.ReadFile(filepath.Join(dir, "p2"))
				seen = map[string]string{"p1": string(p1), "p2": string(p2)}
			}
		}
		return []byte("Messages transferred       : 1234\nFolders synced             : 12/12 synced\nTotal bytes transferred    : 98765\nDetected 0 errors\n"), nil
	}}
	out, err := e.mailImport(context.Background(), json.RawMessage(`{"run_id":"imp1","container":"mailserver",
		"source":{"host":"imap.gmail.com","port":993,"security":"ssl","user":"ann@gmail.com","password":"src-secret"},
		"dest":{"user":"ann@example.lk*dmpimport","password":"master-secret"}}`), nil)
	if err != nil {
		t.Fatal(err)
	}
	joined := strings.Join(args, " ")
	if strings.Contains(joined, "src-secret") || strings.Contains(joined, "master-secret") {
		t.Fatal("passwords must never be on the command line: " + joined)
	}
	for _, want := range []string{"docker run --rm --name ws-import-imp1 --network container:mailserver", ImapsyncImage, "--host1 imap.gmail.com --port1 993 --user1 ann@gmail.com --passfile1 /secrets/p1",
		"--host2 127.0.0.1 --port2 993 --ssl2", "--user2 ann@example.lk*dmpimport --passfile2 /secrets/p2", "--ssl1"} {
		if !strings.Contains(joined, want) {
			t.Fatalf("missing %q in %s", want, joined)
		}
	}
	if seen["p1"] != "src-secret\n" || seen["p2"] != "master-secret\n" {
		t.Fatalf("password files: %v", seen)
	}
	m := out.(map[string]any)
	if m["messages"] != int64(1234) || m["folders"] != int64(12) || m["bytes"] != int64(98765) || m["errors"] != int64(0) || m["exit_code"] != 0 {
		t.Fatalf("%#v", m)
	}
	if left, _ := filepath.Glob(filepath.Join(state, "imap-*")); len(left) != 0 {
		t.Fatal("password files must be removed afterwards")
	}
}

func TestMailImportReportsAFailedLoginAsAResult(t *testing.T) {
	e := &Executor{StateDir: t.TempDir(), Run: func(context.Context, string, []string, string, ...string) ([]byte, error) {
		return []byte("Host1 failure: Error login on [imap.example.com] with user [x]: AUTHENTICATIONFAILED\n"), exec.Command("sh", "-c", "exit 16").Run()
	}}
	out, err := e.mailImport(context.Background(), json.RawMessage(`{"run_id":"imp2","container":"mailserver","source":{"host":"imap.example.com","port":143,"security":"starttls","user":"x","password":"p"},"dest":{"user":"x@y*dmpimport","password":"m"}}`), nil)
	if err != nil || out.(map[string]any)["exit_code"] != 16 {
		t.Fatalf("%v %v", out, err)
	}
}

func TestMailImportValidatesInput(t *testing.T) {
	e := &Executor{StateDir: t.TempDir(), Run: func(context.Context, string, []string, string, ...string) ([]byte, error) { return nil, nil }}
	for _, bad := range []string{
		`{"run_id":"a","container":"mailserver","source":{"host":"-oProxy","port":993,"user":"u","password":"p"},"dest":{"user":"d","password":"m"}}`,
		`{"run_id":"a","container":"mailserver","source":{"host":"imap.x.com","port":993,"user":"u v","password":"p"},"dest":{"user":"d","password":"m"}}`,
		`{"run_id":"a","container":"mailserver","source":{"host":"imap.x.com","port":993,"user":"u","password":"p\nq"},"dest":{"user":"d","password":"m"}}`,
		`{"run_id":"a","container":"a;b","source":{"host":"imap.x.com","port":993,"user":"u","password":"p"},"dest":{"user":"d","password":"m"}}`,
		`{"run_id":"a","container":"mailserver","source":{"host":"imap.x.com","port":993,"security":"tls13","user":"u","password":"p"},"dest":{"user":"d","password":"m"}}`,
	} {
		if _, err := e.mailImport(context.Background(), json.RawMessage(bad), nil); err == nil {
			t.Errorf("%s must be refused", bad)
		}
	}
}

func TestMailImportReportsProgressFromTheLog(t *testing.T) {
	importPollEvery = 5 * time.Millisecond
	defer func() { importPollEvery = 10 * time.Second }()
	var mu sync.Mutex
	var seen []string
	e := &Executor{StateDir: t.TempDir(), Run: func(_ context.Context, _ string, _ []string, name string, a ...string) ([]byte, error) {
		if a[0] == "logs" {
			if a[len(a)-1] != "ws-import-imp3" {
				t.Errorf("logs of %v", a)
			}
			return []byte("ETA: Thu Oct 31 10:00:00 2026  120 s  900/1000 msgs left\nETA: Thu Oct 31 10:00:05 2026  100 s  750/1000 msgs left\n"), nil
		}
		time.Sleep(60 * time.Millisecond)
		return []byte("Messages transferred       : 1000\n"), nil
	}}
	_, err := e.mailImport(context.Background(), json.RawMessage(`{"run_id":"imp3","container":"mailserver","source":{"host":"imap.example.com","port":993,"security":"ssl","user":"x","password":"p"},"dest":{"user":"x@y*dmpimport","password":"m"}}`),
		func(pct int, msg string) { mu.Lock(); seen = append(seen, fmt.Sprintf("%d %s", pct, msg)); mu.Unlock() })
	if err != nil {
		t.Fatal(err)
	}
	mu.Lock()
	defer mu.Unlock()
	if len(seen) == 0 || seen[0] != "25 250 of 1000 messages copied" {
		t.Fatalf("progress: %v", seen)
	}
}
