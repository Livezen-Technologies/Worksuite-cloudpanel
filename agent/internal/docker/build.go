package docker

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
)

// BuildOptions are the /build query parameters the agent allows. There is
// deliberately no way to request host networking, extra hosts or privileged
// entitlements.
type BuildOptions struct {
	Tag        string
	Dockerfile string // path inside the context
	BuildArgs  map[string]string
	Labels     map[string]string
	NoCache    bool
	CacheFrom  []string
}

// streamMsg is one JSON line of a build or push progress stream.
type streamMsg struct {
	Stream      string `json:"stream"`
	Status      string `json:"status"`
	ID          string `json:"id"`
	Error       string `json:"error"`
	ErrorDetail *struct {
		Message string `json:"message"`
	} `json:"errorDetail"`
	Aux json.RawMessage `json:"aux"`
}

func (m streamMsg) err() string {
	if m.Error != "" {
		return m.Error
	}
	if m.ErrorDetail != nil {
		return m.ErrorDetail.Message
	}
	return ""
}

// BuildImage posts a tar build context to /build (classic builder, default
// bridge network) and streams log lines to logLine. It returns the image ID.
func (c *Client) BuildImage(ctx context.Context, tarCtx io.Reader, o BuildOptions, logLine func(string)) (string, error) {
	q := url.Values{
		"t": {o.Tag}, "rm": {"1"}, "forcerm": {"1"}, "networkmode": {"default"}, "version": {"1"},
	}
	if o.Dockerfile != "" {
		q.Set("dockerfile", o.Dockerfile)
	}
	if o.NoCache {
		q.Set("nocache", "1")
	}
	if len(o.CacheFrom) > 0 {
		b, _ := json.Marshal(o.CacheFrom)
		q.Set("cachefrom", string(b))
	}
	if len(o.BuildArgs) > 0 {
		b, _ := json.Marshal(o.BuildArgs)
		q.Set("buildargs", string(b))
	}
	if len(o.Labels) > 0 {
		b, _ := json.Marshal(o.Labels)
		q.Set("labels", string(b))
	}
	req, err := http.NewRequestWithContext(ctx, "POST", c.Base+"/build?"+q.Encode(), tarCtx)
	if err != nil {
		return "", err
	}
	req.Header.Set("Content-Type", "application/x-tar")
	resp, err := c.HTTP.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode >= 300 {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 64<<10))
		var m struct {
			Message string `json:"message"`
		}
		msg := strings.TrimSpace(string(b))
		if json.Unmarshal(b, &m) == nil && m.Message != "" {
			msg = m.Message
		}
		return "", &APIError{Status: resp.StatusCode, Message: msg}
	}
	var imageID string
	var partial strings.Builder
	emit := func(s string) {
		partial.WriteString(s)
		for {
			v := partial.String()
			i := strings.IndexByte(v, '\n')
			if i < 0 {
				return
			}
			if line := strings.TrimRight(v[:i], "\r"); line != "" && logLine != nil {
				logLine(line)
			}
			partial.Reset()
			partial.WriteString(v[i+1:])
		}
	}
	dec := json.NewDecoder(resp.Body)
	for {
		var m streamMsg
		if err := dec.Decode(&m); err == io.EOF {
			break
		} else if err != nil {
			return "", err
		}
		if e := m.err(); e != "" {
			emit("\n")
			if logLine != nil {
				logLine("ERROR: " + e)
			}
			return "", fmt.Errorf("build failed: %s", e)
		}
		if m.Stream != "" {
			emit(m.Stream)
		}
		if len(m.Aux) > 0 {
			var aux struct {
				ID string `json:"ID"`
			}
			if json.Unmarshal(m.Aux, &aux) == nil && aux.ID != "" {
				imageID = aux.ID
			}
		}
	}
	emit("\n")
	if imageID == "" {
		// Older daemons: fall back to inspecting the tag.
		img, err := c.InspectImage(ctx, o.Tag)
		if err != nil {
			return "", fmt.Errorf("build produced no image: %w", err)
		}
		imageID = img.ID
	}
	return imageID, nil
}

// PushImage pushes name:tag. X-Registry-Auth is always sent (dockerd requires
// the header). It returns the manifest digest reported by the daemon.
func (c *Client) PushImage(ctx context.Context, name, tag string, auth *RegistryAuth, logLine func(string)) (string, error) {
	if auth == nil {
		auth = &RegistryAuth{}
	}
	resp, err := c.do(ctx, "POST", "/images/"+name+"/push", url.Values{"tag": {tag}}, nil, map[string]string{"X-Registry-Auth": auth.header()})
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	var digest string
	dec := json.NewDecoder(resp.Body)
	for {
		var m streamMsg
		if err := dec.Decode(&m); err == io.EOF {
			break
		} else if err != nil {
			return "", err
		}
		if e := m.err(); e != "" {
			return "", fmt.Errorf("push %s:%s: %s", name, tag, e)
		}
		if len(m.Aux) > 0 {
			var aux struct {
				Digest string `json:"Digest"`
			}
			if json.Unmarshal(m.Aux, &aux) == nil && aux.Digest != "" {
				digest = aux.Digest
			}
		}
		if m.Status != "" && m.ID == "" && logLine != nil {
			logLine(m.Status)
		}
	}
	if digest == "" {
		return "", fmt.Errorf("push %s:%s: daemon reported no digest", name, tag)
	}
	return digest, nil
}

// ImageInspect is the subset of /images/{name}/json the agent uses.
type ImageInspect struct {
	ID          string   `json:"Id"`
	RepoTags    []string `json:"RepoTags"`
	RepoDigests []string `json:"RepoDigests"`
	Size        int64    `json:"Size"`
}

func (c *Client) InspectImage(ctx context.Context, ref string) (*ImageInspect, error) {
	var out ImageInspect
	return &out, c.call(ctx, "GET", "/images/"+url.PathEscape(ref)+"/json", nil, nil, &out)
}
