package executor

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
)

// Adopting hand-written Traefik routes: the panel reads the operator's own files in
// the file-provider directory (never its dmp-* ones), recreates each route as a managed
// domain, and then retires the old file by renaming it to <name>.adopted, which
// Traefik ignores. proxy.restore puts it back if the managed route does not answer.

const adoptedSuffix = ".adopted"

func (e *Executor) proxyAdopt(typ string, raw []byte) (any, error) {
	var p struct {
		File string `json:"file"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	dir := firstNonEmpty(e.ProxyDir, DefaultProxyDir)
	if typ == "proxy.scan" {
		entries, err := os.ReadDir(dir)
		if err != nil {
			return nil, err
		}
		type file struct {
			Name    string `json:"name"`
			Content string `json:"content"`
		}
		files := []file{}
		adopted := []string{}
		for _, ent := range entries {
			n := ent.Name()
			if strings.HasSuffix(n, adoptedSuffix) {
				adopted = append(adopted, strings.TrimSuffix(n, adoptedSuffix))
				continue
			}
			if !ent.Type().IsRegular() || !reYAMLName.MatchString(n) || strings.HasPrefix(n, "dmp-") {
				continue
			}
			b, err := os.ReadFile(filepath.Join(dir, n))
			if err != nil || len(b) > 1<<20 {
				continue
			}
			files = append(files, file{n, string(b)})
		}
		sort.Slice(files, func(i, j int) bool { return files[i].Name < files[j].Name })
		sort.Strings(adopted)
		return map[string]any{"dir": dir, "files": files, "adopted": adopted}, nil
	}
	if !reYAMLName.MatchString(p.File) || strings.HasPrefix(p.File, "dmp-") {
		return nil, fmt.Errorf("invalid file %q", p.File)
	}
	live, parked := filepath.Join(dir, p.File), filepath.Join(dir, p.File+adoptedSuffix)
	switch typ {
	case "proxy.retire":
		if _, err := os.Stat(live); err != nil {
			if _, perr := os.Stat(parked); perr == nil {
				return map[string]any{"file": p.File, "retired": true, "already": true}, nil
			}
			return nil, fmt.Errorf("%s is not in %s", p.File, dir)
		}
		return map[string]any{"file": p.File, "retired": true}, os.Rename(live, parked)
	case "proxy.restore":
		if _, err := os.Stat(parked); err != nil {
			return nil, errors.New("there is no retired copy to restore")
		}
		if _, err := os.Stat(live); err == nil {
			return nil, fmt.Errorf("%s exists again; not overwriting it", p.File)
		}
		return map[string]any{"file": p.File, "restored": true}, os.Rename(parked, live)
	}
	return nil, fmt.Errorf("unknown command %s", typ)
}
