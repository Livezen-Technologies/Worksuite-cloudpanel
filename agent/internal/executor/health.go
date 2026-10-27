package executor

import (
	"context"
	"errors"
	"fmt"
	"net"
	"net/http"
	"regexp"
	"strconv"
	"strings"
	"time"
)

var reHealthPath = regexp.MustCompile(`^/[A-Za-z0-9._~!$&'()*+,;=:@%/?-]{0,254}$`)

// healthCheck polls a started container until it is healthy or the timeout
// passes: Docker's own health status when the image defines one, otherwise
// an HTTP GET to http://<container ip>:<port><path> (2xx/3xx passes), and
// without a port just "still running". On failure the last log lines are
// returned (UHOST-006).
func (e *Executor) healthCheck(ctx context.Context, raw []byte, progress func(int, string)) (any, error) {
	var p struct {
		ContainerID string `json:"container_id"`
		Port        int    `json:"port"`
		Path        string `json:"path"`
		TimeoutS    int    `json:"timeout_s"`
		IntervalS   int    `json:"interval_s"`
		LogLines    int    `json:"log_lines"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := checkRef("container_id", p.ContainerID); err != nil {
		return nil, err
	}
	if p.Path == "" {
		p.Path = "/"
	}
	if !reHealthPath.MatchString(p.Path) {
		return nil, fmt.Errorf("invalid health path %q", p.Path)
	}
	if p.Port < 0 || p.Port > 65535 {
		return nil, fmt.Errorf("invalid port %d", p.Port)
	}
	if p.TimeoutS <= 0 || p.TimeoutS > 900 {
		p.TimeoutS = 60
	}
	interval := time.Duration(p.IntervalS) * time.Second
	if p.IntervalS <= 0 || p.IntervalS > 60 {
		interval = 2 * time.Second
	}
	if e.HealthInterval > 0 {
		interval = e.HealthInterval
	}
	if p.LogLines <= 0 || p.LogLines > 1000 {
		p.LogLines = 50
	}
	client := e.HealthHTTP
	if client == nil {
		client = &http.Client{
			Timeout:   3 * time.Second,
			Transport: &http.Transport{Proxy: nil, DialContext: (&net.Dialer{Timeout: 2 * time.Second}).DialContext, DisableKeepAlives: true},
			// A redirect is a pass; never follow it off the container.
			CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
		}
	}

	deadline := time.Now().Add(time.Duration(p.TimeoutS) * time.Second)
	fail := func(reason string) (any, error) {
		lctx, cancel := context.WithTimeout(context.WithoutCancel(ctx), 10*time.Second)
		defer cancel()
		logs, _ := e.Docker.Logs(lctx, p.ContainerID, p.LogLines, false)
		return map[string]any{"healthy": false, "reason": reason, "logs": logs}, errors.New("health check failed: " + reason)
	}
	last := "no check completed"
	runningChecks := 0
	for attempt := 1; ; attempt++ {
		insp, err := e.Docker.InspectContainer(ctx, p.ContainerID)
		if err != nil {
			last = "inspect: " + err.Error()
		} else if !insp.State.Running {
			return fail(fmt.Sprintf("container is %s (exit code %d)", firstNonEmpty(insp.State.Status, "not running"), insp.State.ExitCode))
		} else if h := insp.State.Health; h != nil && h.Status != "" && h.Status != "none" {
			switch h.Status {
			case "healthy":
				return map[string]any{"healthy": true, "method": "docker", "attempts": attempt}, nil
			case "unhealthy":
				return fail("Docker health check reports unhealthy")
			}
			last = "Docker health status " + h.Status
		} else if p.Port > 0 {
			ip := insp.IP()
			if ip == "" {
				last = "container has no internal IP address"
			} else {
				url := "http://" + net.JoinHostPort(ip, strconv.Itoa(p.Port)) + p.Path
				rctx, cancel := context.WithTimeout(ctx, 3*time.Second)
				req, _ := http.NewRequestWithContext(rctx, "GET", url, nil)
				req.Header.Set("User-Agent", "dockermgr-agent-healthcheck")
				resp, err := client.Do(req)
				if err != nil {
					last = "GET " + url + ": " + shortErr(err)
				} else {
					resp.Body.Close()
					if resp.StatusCode >= 200 && resp.StatusCode < 400 {
						cancel()
						return map[string]any{"healthy": true, "method": "http", "url": url, "status": resp.StatusCode, "attempts": attempt}, nil
					}
					last = fmt.Sprintf("GET %s returned %d", url, resp.StatusCode)
				}
				cancel()
			}
		} else {
			// No port and no Docker health check: the container must stay up for two checks.
			runningChecks++
			if runningChecks >= 2 {
				return map[string]any{"healthy": true, "method": "running", "attempts": attempt}, nil
			}
			last = "container is running"
		}
		if attempt == 1 || attempt%5 == 0 {
			progress(50, last)
		}
		if time.Now().Add(interval).After(deadline) {
			return fail(fmt.Sprintf("not healthy after %ds: %s", p.TimeoutS, last))
		}
		select {
		case <-ctx.Done():
			return fail("cancelled")
		case <-time.After(interval):
		}
	}
}

func shortErr(err error) string {
	s := err.Error()
	if i := strings.LastIndex(s, ": "); i > 0 && strings.Contains(s, "dial tcp") {
		return s[i+2:]
	}
	return s
}
