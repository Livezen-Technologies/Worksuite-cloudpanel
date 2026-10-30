package executor

import (
	"context"
	"encoding/json"
	"io"
	"io/fs"
	"os"
	"path"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
)

// Security scanner (FM-017): a read-only pass over a volume that flags what
// usually means a compromised or misconfigured site. It walks through
// os.Root, never into symlinks, and reads the content of script files only.

const (
	MaxScanFindings  = 1000
	maxScanFileBytes = 1 << 20
	maxScanReadTotal = 512 << 20
)

// ScanFinding is one flagged file or folder.
type ScanFinding struct {
	Path     string `json:"path"`
	Rule     string `json:"rule"`
	Severity string `json:"severity"` // critical|high|medium|info
	Detail   string `json:"detail"`
	Type     string `json:"type"`
	Size     int64  `json:"size"`
	Mode     string `json:"mode"`
	Modified string `json:"modified"`
}

var (
	scriptExt = regexp.MustCompile(`(?i)\.(php\d?|phtml|phar|pht|cgi|pl|py|sh|asp|aspx|jsp)$`)
	// A script extension after another extension: logo.jpg.php, shell.php.png is harmless.
	doubleExt   = regexp.MustCompile(`(?i)\.(jpe?g|png|gif|webp|svg|pdf|txt|zip|ico)\.(php\d?|phtml|phar|pht)$`)
	uploadDirs  = map[string]bool{"uploads": true, "upload": true, "cache": true, "tmp": true, "temp": true, "files": true, "images": true, "media": true}
	sensitive   = map[string]bool{"wp-config.php": true, ".env": true, "config.php": true, "configuration.php": true, "settings.php": true, ".htpasswd": true, "database.php": true}
	malwareSigs = []struct {
		re       *regexp.Regexp
		severity string
		detail   string
	}{
		{regexp.MustCompile(`(?i)eval\s*\(\s*(base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|strrev)\s*\(`), "critical", "evaluates encoded code (eval of base64/gzinflate/rot13)"},
		{regexp.MustCompile(`(?i)(assert|eval|system|exec|shell_exec|passthru|popen|proc_open)\s*\(\s*\$_(GET|POST|REQUEST|COOKIE|SERVER)`), "critical", "runs code or commands straight from a request"},
		{regexp.MustCompile(`(?i)preg_replace\s*\(\s*['"][^'"]*/e['"]`), "critical", "preg_replace with the /e (execute) modifier"},
		{regexp.MustCompile(`(?i)(FilesMan|c99shell|r57shell|b374k|WSO\s+\d|IndoXploit|alfa\s*shell|webshell)`), "critical", "matches the name of a known web shell"},
		{regexp.MustCompile(`(?i)create_function\s*\(\s*['"]{2}\s*,\s*\$`), "high", "create_function with request-built code"},
		{regexp.MustCompile(`(?i)\$\w+\s*=\s*\$_(GET|POST|REQUEST|COOKIE)\[[^\]]+\]\s*;\s*@?\$\w+\s*\(`), "high", "calls a function whose name comes from a request"},
		{regexp.MustCompile(`(?i)\\x[0-9a-f]{2}(\\x[0-9a-f]{2}){60,}`), "high", "contains a long hex-escaped string (often obfuscated code)"},
		{regexp.MustCompile(`(?i)(base64_decode|gzinflate|str_rot13)\s*\(.{0,80}(base64_decode|gzinflate|str_rot13)\s*\(`), "high", "nested decoding functions (layered obfuscation)"},
	}
)

var scanSeverityRank = map[string]int{"critical": 0, "high": 1, "medium": 2, "info": 3}

type scanPayload struct {
	Volume      string `json:"volume"`
	Path        string `json:"path"`
	RecentDays  int    `json:"recent_days"`
	SkipContent bool   `json:"skip_content"`
}

func (e *Executor) scanFiles(ctx context.Context, raw json.RawMessage) (any, error) {
	var p scanPayload
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if p.RecentDays <= 0 || p.RecentDays > 90 {
		p.RecentDays = 7
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

	recent := e.now().Add(-time.Duration(p.RecentDays) * 24 * time.Hour)
	findings := []ScanFinding{}
	var files, dirs, readBytes int64
	truncated := false
	add := func(rel string, fi fs.FileInfo, rule, sev, detail string) {
		if len(findings) >= MaxScanFindings {
			truncated = true
			return
		}
		en := entryOf(fi.Name(), fi)
		findings = append(findings, ScanFinding{Path: rel, Rule: rule, Severity: sev, Detail: detail, Type: en.Type, Size: fi.Size(), Mode: en.Mode, Modified: en.Modified})
	}

	type item struct {
		rel      string
		inUpload bool
	}
	queue := []item{{start, false}}
	for len(queue) > 0 {
		if ctx.Err() != nil {
			return nil, ctx.Err()
		}
		cur := queue[0]
		queue = queue[1:]
		d, err := root.Open(cur.rel)
		if err != nil {
			continue
		}
		des, _ := d.ReadDir(-1)
		d.Close()
		for _, de := range des {
			if files+dirs >= MaxSearchVisited {
				truncated = true
				break
			}
			fi, err := de.Info()
			if err != nil || fi.Mode()&fs.ModeSymlink != 0 {
				continue
			}
			name := de.Name()
			rel := path.Join(cur.rel, name)
			perm := fi.Mode().Perm()
			if fi.IsDir() {
				dirs++
				if perm&0o002 != 0 {
					add(rel, fi, "world_writable", "medium", "folder anyone on the server can write to")
				}
				queue = append(queue, item{rel, cur.inUpload || uploadDirs[strings.ToLower(name)]})
				continue
			}
			if !fi.Mode().IsRegular() {
				continue
			}
			files++
			isScript := scriptExt.MatchString(name)
			switch {
			case doubleExt.MatchString(name):
				add(rel, fi, "double_extension", "high", "script disguised with a second extension")
			case isScript && cur.inUpload:
				add(rel, fi, "script_in_uploads", "high", "script inside an uploads or cache folder, where only media belongs")
			case isScript && strings.HasPrefix(name, "."):
				add(rel, fi, "hidden_script", "high", "hidden script file")
			}
			if perm&0o002 != 0 {
				add(rel, fi, "world_writable", "medium", "file anyone on the server can change")
			}
			if sensitive[strings.ToLower(name)] && perm&0o004 != 0 {
				add(rel, fi, "sensitive_readable", "medium", "configuration with secrets that anyone on the server can read")
			}
			if isScript && fi.ModTime().After(recent) {
				add(rel, fi, "recently_changed", "info", "code changed in the last "+strconv.Itoa(p.RecentDays)+" days")
			}
			if isScript && !p.SkipContent && fi.Size() > 0 && readBytes < maxScanReadTotal {
				sig, sev, n := scanContent(root, rel)
				readBytes += n
				if sig != "" {
					add(rel, fi, "malware_pattern", sev, sig)
				}
			}
		}
	}
	sort.SliceStable(findings, func(i, j int) bool {
		return scanSeverityRank[findings[i].Severity] < scanSeverityRank[findings[j].Severity]
	})
	counts := map[string]int{"critical": 0, "high": 0, "medium": 0, "info": 0}
	for _, f := range findings {
		counts[f.Severity]++
	}
	return map[string]any{
		"path": start, "findings": findings, "counts": counts, "files": files, "folders": dirs,
		"recent_days": p.RecentDays, "truncated": truncated, "content_checked": !p.SkipContent && readBytes < maxScanReadTotal,
		"scanned_at": e.now().UTC().Format(time.RFC3339),
	}, nil
}

// scanContent checks the first MiB of a script against the signatures and
// returns the worst match, its severity and how many bytes it read.
func scanContent(root *os.Root, rel string) (string, string, int64) {
	f, err := root.Open(rel)
	if err != nil {
		return "", "", 0
	}
	defer f.Close()
	b, _ := io.ReadAll(io.LimitReader(f, maxScanFileBytes))
	best, bestSev := "", ""
	for _, s := range malwareSigs {
		if s.re.Match(b) && (bestSev == "" || scanSeverityRank[s.severity] < scanSeverityRank[bestSev]) {
			best, bestSev = s.detail, s.severity
		}
	}
	if bestSev == "" && longestBase64Run(b) >= 2000 {
		best, bestSev = "contains a very long base64 blob (often packed malware)", "high"
	}
	return best, bestSev, int64(len(b))
}

func longestBase64Run(b []byte) int {
	best, run := 0, 0
	for _, c := range b {
		if c >= 'A' && c <= 'Z' || c >= 'a' && c <= 'z' || c >= '0' && c <= '9' || c == '+' || c == '/' {
			if run++; run > best {
				best = run
			}
		} else {
			run = 0
		}
	}
	return best
}
