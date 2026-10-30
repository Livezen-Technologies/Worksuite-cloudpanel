package executor

import (
	"context"
	"encoding/json"
	"errors"
	"io/fs"
	"os"
	"path"
	"strings"
	"time"
)

// Search (FM-011): walk the volume through os.Root, never into symlinks,
// matching by name, extension, size and modified date.

// MaxSearchResults and MaxSearchVisited keep one search bounded on large sites.
const (
	MaxSearchResults = 500
	MaxSearchVisited = 200000
)

type searchPayload struct {
	Volume         string   `json:"volume"`
	Path           string   `json:"path"`
	Name           string   `json:"name"`
	Extensions     []string `json:"extensions"`
	MinSize        *int64   `json:"min_size"`
	MaxSize        *int64   `json:"max_size"`
	ModifiedAfter  string   `json:"modified_after"`
	ModifiedBefore string   `json:"modified_before"`
	Type           string   `json:"type"` // ""|file|dir
}

// SearchHit is one match, with its path from the volume's top level.
type SearchHit struct {
	FileEntry
	Path string `json:"path"`
}

type matcher struct {
	name          string
	exts          map[string]bool
	minSize       int64
	maxSize       int64
	after, before time.Time
	typ           string
}

func (m *matcher) match(name string, e FileEntry, mod time.Time) bool {
	if m.typ != "" && e.Type != m.typ {
		return false
	}
	if m.name != "" && !strings.Contains(strings.ToLower(name), m.name) {
		return false
	}
	if len(m.exts) > 0 {
		lower := strings.ToLower(name)
		ok := false
		for ext := range m.exts {
			if strings.HasSuffix(lower, "."+ext) {
				ok = true
				break
			}
		}
		if !ok || e.Type == "dir" {
			return false
		}
	}
	if (m.minSize > 0 || m.maxSize > 0) && e.Type != "file" {
		return false
	}
	if m.minSize > 0 && e.Size < m.minSize {
		return false
	}
	if m.maxSize > 0 && e.Size > m.maxSize {
		return false
	}
	if !m.after.IsZero() && mod.Before(m.after) {
		return false
	}
	if !m.before.IsZero() && mod.After(m.before) {
		return false
	}
	return true
}

func parseDay(s string, endOfDay bool) (time.Time, error) {
	if s == "" {
		return time.Time{}, nil
	}
	if t, err := time.Parse(time.RFC3339, s); err == nil {
		return t, nil
	}
	t, err := time.Parse("2006-01-02", s)
	if err != nil {
		return t, errors.New("dates must look like 2026-10-29")
	}
	if endOfDay {
		t = t.Add(24*time.Hour - time.Nanosecond)
	}
	return t, nil
}

func (e *Executor) search(ctx context.Context, raw json.RawMessage) (any, error) {
	var p searchPayload
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	m := &matcher{name: strings.ToLower(strings.TrimSpace(p.Name)), exts: map[string]bool{}, typ: p.Type}
	if m.typ != "" && m.typ != "file" && m.typ != "dir" {
		return nil, errors.New("type must be file or dir")
	}
	for _, x := range p.Extensions {
		x = strings.ToLower(strings.TrimPrefix(strings.TrimSpace(x), "."))
		if x != "" {
			m.exts[x] = true
		}
	}
	if p.MinSize != nil {
		m.minSize = *p.MinSize
	}
	if p.MaxSize != nil {
		m.maxSize = *p.MaxSize
	}
	var err error
	if m.after, err = parseDay(p.ModifiedAfter, false); err != nil {
		return nil, err
	}
	if m.before, err = parseDay(p.ModifiedBefore, true); err != nil {
		return nil, err
	}
	if m.name == "" && len(m.exts) == 0 && m.minSize == 0 && m.maxSize == 0 && m.after.IsZero() && m.before.IsZero() && m.typ == "" {
		return nil, errors.New("give at least one search condition")
	}
	start, err := cleanRel(p.Path)
	if err != nil {
		return nil, err
	}
	dir, err := e.volumeRoot(ctx, p.Volume)
	if err != nil {
		return nil, err
	}
	root, err := os.OpenRoot(dir)
	if err != nil {
		return nil, err
	}
	defer root.Close()

	hits := []SearchHit{}
	visited := 0
	stopped := ""
	queue := []string{start}
	for len(queue) > 0 && stopped == "" {
		if ctx.Err() != nil {
			return nil, ctx.Err()
		}
		cur := queue[0]
		queue = queue[1:]
		d, err := root.Open(cur)
		if err != nil {
			continue // removed or unreadable while searching
		}
		des, _ := d.ReadDir(-1)
		d.Close()
		for _, de := range des {
			if visited++; visited > MaxSearchVisited {
				stopped = "visited"
				break
			}
			fi, err := de.Info()
			if err != nil {
				continue
			}
			rel := path.Join(cur, de.Name())
			en := entryOf(de.Name(), fi)
			if m.match(de.Name(), en, fi.ModTime()) {
				hits = append(hits, SearchHit{FileEntry: en, Path: rel})
				if len(hits) >= MaxSearchResults {
					stopped = "results"
					break
				}
			}
			if fi.IsDir() && fi.Mode()&fs.ModeSymlink == 0 {
				queue = append(queue, rel)
			}
		}
	}
	return map[string]any{"path": start, "hits": hits, "visited": visited, "truncated": stopped != "", "stopped_by": stopped}, nil
}
