package executor

import (
	"context"
	"fmt"
	"strings"
	"time"
)

// CleanupSpec is the server.cleanup payload (CLN-1..3).
//
//	safe:       stopped unmanaged containers older than 24h, dangling images, build cache older than 24h
//	standard:   + unused images older than 7 days, all unused build cache
//	aggressive: + every unused image
//
// Images named in Protect (tags, digests or IDs — the panel sends the last
// good releases of each app) are never removed, and volumes are never touched.
type CleanupSpec struct {
	Level   string   `json:"level"`
	Protect []string `json:"protect"`
	DryRun  bool     `json:"dry_run"`
}

var cleanupAge = map[string]time.Duration{"standard": 7 * 24 * time.Hour, "aggressive": 0}

func reportBytes(r map[string]any, key string) int64 {
	if v, ok := r[key].(float64); ok {
		return int64(v)
	}
	return 0
}

func reportCount(r map[string]any, key string) int {
	if v, ok := r[key].([]any); ok {
		return len(v)
	}
	return 0
}

func (e *Executor) cleanup(ctx context.Context, raw []byte, progress func(int, string)) (any, error) {
	var s CleanupSpec
	if err := decode(raw, &s); err != nil {
		return nil, err
	}
	if s.Level == "" {
		s.Level = "safe"
	}
	if s.Level != "safe" && s.Level != "standard" && s.Level != "aggressive" {
		return nil, fmt.Errorf("invalid level %q", s.Level)
	}
	protect := map[string]bool{}
	for _, p := range s.Protect {
		protect[p] = true
	}
	d := e.Docker
	out := map[string]any{"level": s.Level, "dry_run": s.DryRun}
	var freed int64

	// Images in use by any container (running or stopped) are never candidates.
	ctrs, err := d.ListContainers(ctx)
	if err != nil {
		return nil, err
	}
	used := map[string]bool{}
	stopped := 0
	for _, c := range ctrs {
		used[c.ImageID] = true
		if c.State != "running" && c.Labels["io.dockermgr.managed"] != "true" && time.Since(time.Unix(c.Created, 0)) > 24*time.Hour {
			stopped++
		}
	}

	if !s.DryRun {
		progress(10, "removing stopped containers")
		r, err := d.PruneContainers(ctx, map[string][]string{"until": {"24h"}, "label!": {"io.dockermgr.managed=true"}})
		if err != nil {
			return out, err
		}
		freed += reportBytes(r, "SpaceReclaimed")
		out["containers"] = reportCount(r, "ContainersDeleted")
		progress(30, "removing dangling images")
		r, err = d.PruneImages(ctx, map[string][]string{"dangling": {"true"}})
		if err != nil {
			return out, err
		}
		freed += reportBytes(r, "SpaceReclaimed")
		out["dangling_images"] = reportCount(r, "ImagesDeleted")
	} else {
		out["containers"] = stopped
	}

	// Unused tagged images beyond the level's age, minus protected ones.
	removed, skipped := []string{}, []string{}
	if age, ok := cleanupAge[s.Level]; ok {
		imgs, err := d.ListImages(ctx)
		if err != nil {
			return out, err
		}
		for _, img := range imgs {
			if used[img.ID] || len(img.RepoTags) == 0 || time.Since(time.Unix(img.Created, 0)) < age {
				continue
			}
			name := img.RepoTags[0]
			if isProtected(protect, img.ID, img.RepoTags, img.RepoDigests) {
				skipped = append(skipped, name)
				continue
			}
			if !s.DryRun {
				if _, err := d.RemoveImage(ctx, img.ID, false); err != nil {
					progress(60, "skip "+name+": "+err.Error())
					continue
				}
			}
			freed += img.Size
			removed = append(removed, name)
		}
	}
	out["images"], out["protected"] = removed, skipped

	if !s.DryRun {
		progress(80, "pruning build cache")
		until := "24h"
		if s.Level != "safe" {
			until = ""
		}
		if r, err := d.PruneBuildCache(ctx, s.Level == "aggressive", until); err == nil {
			out["build_cache_bytes"] = reportBytes(r, "SpaceReclaimed")
			freed += reportBytes(r, "SpaceReclaimed")
		} else {
			progress(85, "build cache: "+err.Error())
		}
	}
	out["freed_bytes"] = freed
	return out, nil
}

func isProtected(protect map[string]bool, id string, tags, digests []string) bool {
	if protect[id] {
		return true
	}
	for _, t := range tags {
		if protect[t] {
			return true
		}
	}
	for _, dg := range digests {
		if protect[dg] {
			return true
		}
		// repo@sha256:… also matches a protected "sha256:…" digest
		if i := strings.Index(dg, "@"); i >= 0 && protect[dg[i+1:]] {
			return true
		}
	}
	return false
}
