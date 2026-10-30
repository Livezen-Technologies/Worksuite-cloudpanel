package executor

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"
)

// MCL-10 / UMAIL: copy a remote IMAP account into a local mailbox with imapsync.
// imapsync runs in a one-off container that shares the mail container's network,
// so the destination is 127.0.0.1:993. Passwords travel in files mounted read-only
// (never on the command line, where ps would show them) and are removed afterwards.

// ImapsyncImage is the imapsync image the import runs in.
const ImapsyncImage = "gilleslamiral/imapsync:2.290"

var (
	reIMAPHost = regexp.MustCompile(`^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$`)
	reIMAPUser = regexp.MustCompile(`^[^\s\x00-\x1f]{1,190}$`)
	reTransfer = regexp.MustCompile(`(?m)^Messages transferred\s*:\s*(\d+)`)
	reFolders  = regexp.MustCompile(`(?m)^Folders synced\s*:\s*(\d+)`)
	reBytes    = regexp.MustCompile(`(?m)^Total bytes transferred\s*:\s*(\d+)`)
	reErrors   = regexp.MustCompile(`(?m)^Detected (\d+) errors`)
)

type imapEnd struct {
	Host     string `json:"host"`
	Port     int    `json:"port"`
	Security string `json:"security"` // ssl|starttls|none
	User     string `json:"user"`
	Password string `json:"password"`
}

type mailImport struct {
	RunID     string  `json:"run_id"`
	Container string  `json:"container"`
	Source    imapEnd `json:"source"`
	Dest      imapEnd `json:"dest"`
}

func (e *Executor) mailImport(ctx context.Context, raw json.RawMessage) (any, error) {
	var p mailImport
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if !reRunID.MatchString(p.RunID) {
		return nil, errors.New("invalid import id")
	}
	if !reRef.MatchString(p.Container) {
		return nil, errors.New("invalid mail container")
	}
	if !reIMAPHost.MatchString(p.Source.Host) || p.Source.Port < 1 || p.Source.Port > 65535 {
		return nil, errors.New("invalid source host or port")
	}
	if !reIMAPUser.MatchString(p.Source.User) || !reIMAPUser.MatchString(p.Dest.User) {
		return nil, errors.New("invalid user name")
	}
	if p.Source.Password == "" || p.Dest.Password == "" || strings.ContainsAny(p.Source.Password+p.Dest.Password, "\x00\n\r") {
		return nil, errors.New("invalid password")
	}

	dir, err := os.MkdirTemp(firstNonEmpty(e.StateDir, os.TempDir()), "imap-"+p.RunID+"-")
	if err != nil {
		return nil, err
	}
	defer os.RemoveAll(dir)
	if err := os.Chmod(dir, 0o755); err != nil {
		return nil, err
	}
	for name, pw := range map[string]string{"p1": p.Source.Password, "p2": p.Dest.Password} {
		// Readable by the container's user only for the length of the run.
		if err := os.WriteFile(filepath.Join(dir, name), []byte(pw+"\n"), 0o644); err != nil {
			return nil, err
		}
	}

	args := []string{"run", "--rm", "--name", "ws-import-" + p.RunID, "--network", "container:" + p.Container,
		"-v", dir + ":/secrets:ro", ImapsyncImage, "imapsync",
		"--host1", p.Source.Host, "--port1", strconv.Itoa(p.Source.Port), "--user1", p.Source.User, "--passfile1", "/secrets/p1",
		"--host2", "127.0.0.1", "--port2", "993", "--ssl2", "--sslargs2", "SSL_verify_mode=0", "--user2", p.Dest.User, "--passfile2", "/secrets/p2",
		"--nolog", "--noreleasecheck", "--automap", "--addheader"}
	switch p.Source.Security {
	case "", "ssl":
		args = append(args, "--ssl1")
	case "starttls":
		args = append(args, "--tls1")
	case "none":
	default:
		return nil, fmt.Errorf("unknown security %q", p.Source.Security)
	}

	cctx, cancel := context.WithTimeout(ctx, 12*time.Hour)
	defer cancel()
	e.cron.add(p.RunID, cancel)
	defer e.cron.remove(p.RunID)
	started := time.Now()
	out, err := e.Run(cctx, "", nil, "docker", args...)
	text := string(out)
	res := map[string]any{"exit_code": 0, "output": tailString(text, 16<<10), "duration_ms": time.Since(started).Milliseconds()}
	for key, re := range map[string]*regexp.Regexp{"messages": reTransfer, "folders": reFolders, "bytes": reBytes, "errors": reErrors} {
		if m := re.FindStringSubmatch(text); m != nil {
			n, _ := strconv.ParseInt(m[1], 10, 64)
			res[key] = n
		}
	}
	if err != nil {
		var exit *exec.ExitError
		if !errors.As(err, &exit) {
			return nil, err
		}
		res["exit_code"] = exit.ExitCode()
	}
	// A failed sign-in or a partial copy is a result the panel records, not an agent error.
	return res, nil
}
