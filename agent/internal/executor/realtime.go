package executor

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"strings"
)

// Real-time scanning (AV-2). ClamAV's on-access scanner, clamonacc, watches the
// server's volumes through fanotify inside the ClamAV container (which mounts
// them read-only at the same paths) and logs every infected file written. The
// panel switches it on or off per server and collects its detections with
// av.events; they become threats like those from a full scan.

// ClamOnAccLog is where clamonacc writes detections inside the container.
const ClamOnAccLog = "/var/log/clamav/clamonacc.log"

type realtimePayload struct {
	Enabled bool     `json:"enabled"`
	Volumes []string `json:"volumes"`
}

func (e *Executor) avRealtime(ctx context.Context, raw json.RawMessage) (any, error) {
	var p realtimePayload
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	c := e.clamContainer()
	if !reRef.MatchString(c) {
		return nil, errors.New("invalid ClamAV container name")
	}
	// Stop any running watcher first, so a new volume list takes effect.
	_, _ = e.Run(ctx, "", nil, "docker", "exec", c, "pkill", "-x", "clamonacc")
	if !p.Enabled {
		return map[string]any{"enabled": false}, nil
	}
	paths := make([]string, 0, len(p.Volumes))
	for _, v := range p.Volumes {
		dir, err := e.volumeRoot(ctx, v)
		if err != nil {
			return nil, err
		}
		if strings.ContainsAny(dir, "\n\x00") {
			return nil, fmt.Errorf("volume %s has an unusable path", v)
		}
		paths = append(paths, dir)
	}
	if len(paths) == 0 {
		return nil, errors.New("no volumes to watch")
	}
	// The paths are passed as arguments, never pasted into the shell script.
	args := append([]string{"exec", c, "sh", "-c", `printf '%s\n' "$@" > /tmp/ws-watch`, "sh"}, paths...)
	if out, err := e.Run(ctx, "", nil, "docker", args...); err != nil {
		return nil, fmt.Errorf("writing the watch list: %s", strings.TrimSpace(string(out)))
	}
	if out, err := e.Run(ctx, "", nil, "docker", "exec", "-d", c, "clamonacc", "--fdpass", "--log="+ClamOnAccLog, "--watch-list=/tmp/ws-watch"); err != nil {
		return nil, fmt.Errorf("starting clamonacc: %s", strings.TrimSpace(string(out)))
	}
	return map[string]any{"enabled": true, "watching": len(paths)}, nil
}

type realtimeThreat struct {
	Threat
	Volume string `json:"volume"`
}

// avEvents reads clamonacc's recent detections and maps each to its volume.
func (e *Executor) avEvents(ctx context.Context, raw json.RawMessage) (any, error) {
	var p realtimePayload
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	c := e.clamContainer()
	if !reRef.MatchString(c) {
		return nil, errors.New("invalid ClamAV container name")
	}
	out, err := e.Run(ctx, "", nil, "docker", "exec", c, "tail", "-n", "5000", ClamOnAccLog)
	if err != nil {
		return map[string]any{"threats": []realtimeThreat{}, "running": false}, nil // no log yet: nothing found
	}
	found := []realtimeThreat{}
	seen := map[string]bool{}
	for _, v := range p.Volumes {
		dir, err := e.volumeRoot(ctx, v)
		if err != nil {
			continue
		}
		threats, err := parseClam(out, dir)
		if err != nil {
			continue
		}
		root, err := os.OpenRoot(dir)
		if err != nil {
			continue
		}
		for _, t := range threats {
			if seen[v+"\x00"+t.Path] {
				continue
			}
			seen[v+"\x00"+t.Path] = true
			fi, err := root.Lstat(t.Path)
			if err != nil || !fi.Mode().IsRegular() {
				continue // already removed or quarantined
			}
			sum, err := hashIn(root, t.Path)
			if err != nil {
				continue
			}
			t.SHA256, t.Size = sum, fi.Size()
			found = append(found, realtimeThreat{Threat: t, Volume: v})
		}
		root.Close()
	}
	return map[string]any{"threats": found}, nil
}
