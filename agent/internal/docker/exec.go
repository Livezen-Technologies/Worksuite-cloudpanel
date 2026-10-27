package docker

import (
	"context"
	"errors"
	"io"
	"net/http"
	"net/url"
	"strconv"
)

// ExecConfig is the subset of the Docker exec create body the agent uses.
type ExecConfig struct {
	Cmd          []string `json:"Cmd"`
	User         string   `json:"User"`
	Env          []string `json:"Env,omitempty"`
	WorkingDir   string   `json:"WorkingDir,omitempty"`
	Tty          bool     `json:"Tty"`
	AttachStdin  bool     `json:"AttachStdin"`
	AttachStdout bool     `json:"AttachStdout"`
	AttachStderr bool     `json:"AttachStderr"`
	ConsoleSize  []int    `json:"ConsoleSize,omitempty"` // [rows, cols]
	Privileged   bool     `json:"Privileged"`
}

// ExecCreate creates an exec instance in a running container.
func (c *Client) ExecCreate(ctx context.Context, container string, cfg ExecConfig) (string, error) {
	var out struct {
		ID string `json:"Id"`
	}
	if err := c.call(ctx, "POST", "/containers/"+url.PathEscape(container)+"/exec", nil, cfg, &out); err != nil {
		return "", err
	}
	if out.ID == "" {
		return "", errors.New("docker: exec create returned no id")
	}
	return out.ID, nil
}

// ExecAttach starts the exec with a hijacked (Upgrade: tcp) bidirectional
// stream. With a TTY the stream is raw (no multiplexing headers).
func (c *Client) ExecAttach(ctx context.Context, id string, tty bool) (io.ReadWriteCloser, error) {
	resp, err := c.do(ctx, "POST", "/exec/"+url.PathEscape(id)+"/start", nil,
		map[string]any{"Detach": false, "Tty": tty},
		map[string]string{"Connection": "Upgrade", "Upgrade": "tcp"})
	if err != nil {
		return nil, err
	}
	if resp.StatusCode != http.StatusSwitchingProtocols {
		// Older engines answer 200 with the stream in the body (read-only).
		resp.Body.Close()
		return nil, errors.New("docker: exec start did not upgrade the connection")
	}
	rwc, ok := resp.Body.(io.ReadWriteCloser)
	if !ok {
		resp.Body.Close()
		return nil, errors.New("docker: exec stream is not writable")
	}
	return rwc, nil
}

// ExecResize resizes the exec TTY.
func (c *Client) ExecResize(ctx context.Context, id string, rows, cols int) error {
	q := url.Values{"h": {strconv.Itoa(rows)}, "w": {strconv.Itoa(cols)}}
	return c.call(ctx, "POST", "/exec/"+url.PathEscape(id)+"/resize", q, nil, nil)
}

// ExecInspect returns whether the exec is still running and its exit code.
func (c *Client) ExecInspect(ctx context.Context, id string) (running bool, exitCode int, err error) {
	var out struct {
		Running  bool `json:"Running"`
		ExitCode int  `json:"ExitCode"`
	}
	err = c.call(ctx, "GET", "/exec/"+url.PathEscape(id)+"/json", nil, nil, &out)
	return out.Running, out.ExitCode, err
}
