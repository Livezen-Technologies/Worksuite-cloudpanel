package docker

import (
	"context"
	"encoding/json"
	"net/url"
	"strconv"
)

// Swarm: the Engine API's own swarm endpoints, so no CLI is needed on the host.

type SwarmInfo struct {
	ID         string `json:"ID"`
	JoinTokens struct {
		Worker  string `json:"Worker"`
		Manager string `json:"Manager"`
	} `json:"JoinTokens"`
}

func (c *Client) SwarmInit(ctx context.Context, advertiseAddr string) (string, error) {
	var nodeID string
	err := c.call(ctx, "POST", "/swarm/init", nil, map[string]any{"ListenAddr": "0.0.0.0:2377", "AdvertiseAddr": advertiseAddr}, &nodeID)
	return nodeID, err
}

func (c *Client) SwarmInspect(ctx context.Context) (*SwarmInfo, error) {
	var s SwarmInfo
	return &s, c.call(ctx, "GET", "/swarm", nil, nil, &s)
}

func (c *Client) SwarmJoin(ctx context.Context, advertiseAddr, remote, token string) error {
	return c.call(ctx, "POST", "/swarm/join", nil, map[string]any{"ListenAddr": "0.0.0.0:2377", "AdvertiseAddr": advertiseAddr, "RemoteAddrs": []string{remote}, "JoinToken": token}, nil)
}

func (c *Client) SwarmLeave(ctx context.Context, force bool) error {
	return c.call(ctx, "POST", "/swarm/leave", url.Values{"force": {strconv.FormatBool(force)}}, nil, nil)
}

// Raw lists keep whatever fields the Engine returns; the executor picks what the panel needs.
func (c *Client) SwarmNodes(ctx context.Context) ([]map[string]any, error) {
	var out []map[string]any
	return out, c.call(ctx, "GET", "/nodes", nil, nil, &out)
}

func (c *Client) SwarmServices(ctx context.Context) ([]map[string]any, error) {
	var out []map[string]any
	return out, c.call(ctx, "GET", "/services", nil, nil, &out)
}

func (c *Client) SwarmTasks(ctx context.Context) ([]map[string]any, error) {
	var out []map[string]any
	f, _ := json.Marshal(map[string]map[string]bool{"desired-state": {"running": true}})
	return out, c.call(ctx, "GET", "/tasks", url.Values{"filters": {string(f)}}, nil, &out)
}

func (c *Client) SwarmNodeInspect(ctx context.Context, id string) (map[string]any, error) {
	var out map[string]any
	return out, c.call(ctx, "GET", "/nodes/"+url.PathEscape(id), nil, nil, &out)
}

func (c *Client) SwarmNodeUpdate(ctx context.Context, id string, version int64, spec map[string]any) error {
	return c.call(ctx, "POST", "/nodes/"+url.PathEscape(id)+"/update", url.Values{"version": {strconv.FormatInt(version, 10)}}, spec, nil)
}

func (c *Client) SwarmServiceInspect(ctx context.Context, id string) (map[string]any, error) {
	var out map[string]any
	return out, c.call(ctx, "GET", "/services/"+url.PathEscape(id), nil, nil, &out)
}

func (c *Client) SwarmServiceUpdate(ctx context.Context, id string, version int64, spec map[string]any) error {
	return c.call(ctx, "POST", "/services/"+url.PathEscape(id)+"/update", url.Values{"version": {strconv.FormatInt(version, 10)}}, spec, nil)
}
