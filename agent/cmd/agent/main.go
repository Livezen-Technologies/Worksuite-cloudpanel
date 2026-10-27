// Command agent is the Docker Manager Portal server agent.
//
//	agent enroll --url https://panel --token XYZ
//	agent run
//	agent rollback
//	agent version
package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"log"
	"os"
	"os/signal"
	"path/filepath"
	"syscall"
	"time"

	"github.com/livezen-technologies/cloudpanel/agent/internal/config"
	"github.com/livezen-technologies/cloudpanel/agent/internal/docker"
	"github.com/livezen-technologies/cloudpanel/agent/internal/facts"
	"github.com/livezen-technologies/cloudpanel/agent/internal/protocol"
	"github.com/livezen-technologies/cloudpanel/agent/internal/update"
)

// Set at build time: -ldflags "-X main.version=1.0.0 -X main.updatePublicKey=<base64>"
var (
	version         = "dev"
	updatePublicKey = ""
)

func usage() {
	fmt.Fprintf(os.Stderr, `Docker Manager Portal agent %s

Usage:
  agent enroll --url https://panel.example.com --token TOKEN [--config PATH]
  agent run [--config PATH]
  agent rollback [--binary PATH]
  agent version

Config path defaults to %s (override with --config or DOCKERMGR_AGENT_CONFIG).
`, version, config.DefaultPath)
}

func main() {
	if len(os.Args) < 2 {
		usage()
		os.Exit(2)
	}
	var err error
	switch os.Args[1] {
	case "enroll":
		err = cmdEnroll(os.Args[2:])
	case "run":
		err = cmdRun(os.Args[2:])
	case "rollback":
		err = cmdRollback(os.Args[2:])
	case "version", "--version", "-v":
		fmt.Println(version)
	case "help", "-h", "--help":
		usage()
	default:
		usage()
		os.Exit(2)
	}
	if err != nil {
		fmt.Fprintln(os.Stderr, "error:", err)
		os.Exit(1)
	}
}

func cmdEnroll(args []string) error {
	fs := flag.NewFlagSet("enroll", flag.ExitOnError)
	url := fs.String("url", "", "panel base URL (https)")
	token := fs.String("token", "", "single-use enrolment token")
	cfgPath := fs.String("config", "", "config file path")
	hostname := fs.String("hostname", "", "hostname to register (default: system hostname)")
	stateDir := fs.String("state-dir", "", "state directory (default /var/lib/dockermgr)")
	caFile := fs.String("ca-file", "", "extra CA bundle to trust for the panel")
	sock := fs.String("docker-socket", "", "Docker socket (default /var/run/docker.sock)")
	pub := fs.String("update-public-key", "", "base64 ed25519 key for agent updates (overrides built-in)")
	insecureHTTP := fs.Bool("allow-http", false, "allow a plain http panel URL (development only)")
	fs.Parse(args)
	if *url == "" || *token == "" {
		return errors.New("--url and --token are required")
	}
	if len(*url) < 8 || ((*url)[:8] != "https://" && !*insecureHTTP) {
		return errors.New("panel URL must use https")
	}
	cfg := &config.Config{PanelURL: *url, StateDir: *stateDir, CAFile: *caFile, DockerSocket: *sock, UpdatePublicKey: *pub}
	cfg.ApplyDefaults()
	f := facts.Collector{}.Collect()
	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
	defer cancel()
	dv, err := docker.New(cfg.DockerSocket).Version(ctx)
	if err != nil {
		return fmt.Errorf("cannot reach Docker at %s: %w", cfg.DockerSocket, err)
	}
	f.DockerVersion = dv.Version
	h := *hostname
	if h == "" {
		h, _ = os.Hostname()
	}
	hc, err := protocol.NewHTTPClient(cfg.CAFile)
	if err != nil {
		return err
	}
	resp, err := (&protocol.Client{BaseURL: cfg.PanelURL, HTTP: hc}).Enroll(ctx, protocol.EnrollRequest{Token: *token, Hostname: h, Facts: f})
	if err != nil {
		return fmt.Errorf("enrolment failed: %w", err)
	}
	cfg.ServerID, cfg.AgentSecret, cfg.HMACKey = resp.ServerID, resp.AgentSecret, resp.HMACKey
	cfg.HeartbeatInterval, cfg.Certificate = resp.HeartbeatInterval, resp.Certificate
	cfg.ApplyDefaults()
	p := config.ResolvePath(*cfgPath)
	if err := config.Save(p, cfg); err != nil {
		return err
	}
	fmt.Printf("enrolled as server %s; config written to %s\n", cfg.ServerID, p)
	return nil
}

func cmdRollback(args []string) error {
	fs := flag.NewFlagSet("rollback", flag.ExitOnError)
	bin := fs.String("binary", "", "agent binary path (default: this executable)")
	fs.Parse(args)
	p := *bin
	if p == "" {
		var err error
		if p, err = os.Executable(); err != nil {
			return err
		}
	}
	if err := (&update.Updater{BinaryPath: p}).Rollback(); err != nil {
		return err
	}
	fmt.Println("rolled back", p)
	return nil
}

func cmdRun(args []string) error {
	fs := flag.NewFlagSet("run", flag.ExitOnError)
	cfgPath := fs.String("config", "", "config file path")
	fs.Parse(args)
	cfg, err := config.Load(config.ResolvePath(*cfgPath))
	if err != nil {
		return err
	}
	logger := log.New(os.Stderr, "", log.LstdFlags)
	exe, err := os.Executable()
	if err != nil {
		return err
	}
	if p, err := filepath.EvalSymlinks(exe); err == nil {
		exe = p
	}
	reexec := func() {
		logger.Printf("re-executing %s", exe)
		if err := syscall.Exec(exe, os.Args, os.Environ()); err != nil {
			logger.Printf("re-exec failed: %v; exiting so the service manager restarts us", err)
			os.Exit(1)
		}
	}
	a, err := newAgent(cfg, exe, logger)
	if err != nil {
		return err
	}
	a.exec.Restart = reexec
	if a.checkUpdateMarker() {
		reexec()
	}
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()
	logger.Printf("agent %s starting; server %s; panel %s", version, cfg.ServerID, cfg.PanelURL)
	a.Run(ctx)
	return nil
}
