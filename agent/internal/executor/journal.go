package executor

import (
	"encoding/json"
	"os"
	"path/filepath"
	"sync"
	"time"
)

// Journal persists seen operation IDs so a command is never executed twice,
// even across agent restarts. IDs are recorded before execution starts.
type Journal struct {
	Path      string
	Retention time.Duration // default 7 days (well beyond the 10 min command lifetime)
	mu        sync.Mutex
	seen      map[string]int64
	loaded    bool
}

func (j *Journal) load() error {
	if j.loaded {
		return nil
	}
	j.seen = map[string]int64{}
	b, err := os.ReadFile(j.Path)
	if err != nil && !os.IsNotExist(err) {
		return err
	}
	if len(b) > 0 {
		if err := json.Unmarshal(b, &j.seen); err != nil {
			return err
		}
	}
	j.loaded = true
	return nil
}

// MarkIfNew records id and returns true when it had not been seen before.
// It returns an error (and false) if the journal cannot be persisted, so a
// command is never run without a durable record.
func (j *Journal) MarkIfNew(id string, now time.Time) (bool, error) {
	j.mu.Lock()
	defer j.mu.Unlock()
	if err := j.load(); err != nil {
		return false, err
	}
	if _, ok := j.seen[id]; ok {
		return false, nil
	}
	ret := j.Retention
	if ret == 0 {
		ret = 7 * 24 * time.Hour
	}
	cut := now.Add(-ret).Unix()
	for k, t := range j.seen {
		if t < cut {
			delete(j.seen, k)
		}
	}
	j.seen[id] = now.Unix()
	if err := j.save(); err != nil {
		delete(j.seen, id)
		return false, err
	}
	return true, nil
}

func (j *Journal) save() error {
	if err := os.MkdirAll(filepath.Dir(j.Path), 0o700); err != nil {
		return err
	}
	b, _ := json.Marshal(j.seen)
	tmp := j.Path + ".tmp"
	f, err := os.OpenFile(tmp, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0o600)
	if err != nil {
		return err
	}
	if _, err := f.Write(b); err != nil {
		f.Close()
		return err
	}
	if err := f.Sync(); err != nil {
		f.Close()
		return err
	}
	f.Close()
	return os.Rename(tmp, j.Path)
}
