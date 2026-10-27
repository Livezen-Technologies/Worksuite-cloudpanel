package executor

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

// MaxReportedVulns caps the vulnerability list returned to the panel.
const MaxReportedVulns = 1000

var severityRank = map[string]int{"CRITICAL": 0, "HIGH": 1, "MEDIUM": 2, "LOW": 3, "UNKNOWN": 4}

type trivyReport struct {
	Results []struct {
		Target          string `json:"Target"`
		Vulnerabilities []struct {
			VulnerabilityID  string `json:"VulnerabilityID"`
			PkgName          string `json:"PkgName"`
			InstalledVersion string `json:"InstalledVersion"`
			FixedVersion     string `json:"FixedVersion"`
			Severity         string `json:"Severity"`
			Title            string `json:"Title"`
		} `json:"Vulnerabilities"`
	} `json:"Results"`
}

// scanImage runs `trivy image --format json --quiet --output <file> <ref>`
// (fixed arguments, no shell) and returns the vulnerabilities found.
func (e *Executor) scanImage(ctx context.Context, raw []byte, progress func(int, string)) (any, error) {
	var p struct {
		Image string `json:"image"`
	}
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if err := checkImage(p.Image); err != nil {
		return nil, err
	}
	look := e.LookPath
	if look == nil {
		look = execLookPath
	}
	bin, err := look("trivy")
	if err != nil {
		return nil, errors.New("trivy not installed")
	}
	root := filepath.Join(e.StateDir, "scans")
	if err := os.MkdirAll(root, 0o700); err != nil {
		return nil, err
	}
	dir, err := os.MkdirTemp(root, "scan-")
	if err != nil {
		return nil, err
	}
	defer os.RemoveAll(dir)
	report := filepath.Join(dir, "report.json")
	progress(10, "scanning "+p.Image+" with trivy")
	sctx, cancel := context.WithTimeout(ctx, 15*time.Minute)
	defer cancel()
	env := append(baseEnv(), "TRIVY_NO_PROGRESS=true", "TRIVY_CACHE_DIR="+filepath.Join(e.StateDir, "trivy-cache"))
	out, err := e.run(sctx, dir, env, bin, "image", "--format", "json", "--quiet", "--output", report, p.Image)
	if err != nil {
		return map[string]any{"output": tailString(string(out), 4000)}, fmt.Errorf("trivy failed: %v: %s", err, strings.TrimSpace(tailString(string(out), 500)))
	}
	b, err := os.ReadFile(report)
	if err != nil {
		return nil, fmt.Errorf("trivy wrote no report: %w", err)
	}
	var r trivyReport
	if err := json.Unmarshal(b, &r); err != nil {
		return nil, fmt.Errorf("unreadable trivy report: %w", err)
	}
	type vuln struct {
		ID        string `json:"id"`
		Package   string `json:"package"`
		Installed string `json:"installed"`
		Fixed     string `json:"fixed,omitempty"`
		Severity  string `json:"severity"`
		Title     string `json:"title,omitempty"`
		Target    string `json:"target"`
	}
	vulns := []vuln{}
	for _, res := range r.Results {
		for _, v := range res.Vulnerabilities {
			vulns = append(vulns, vuln{v.VulnerabilityID, v.PkgName, v.InstalledVersion, v.FixedVersion, strings.ToUpper(v.Severity), v.Title, res.Target})
		}
	}
	rank := func(s string) int {
		if r, ok := severityRank[s]; ok {
			return r
		}
		return 5
	}
	sort.SliceStable(vulns, func(i, j int) bool { return rank(vulns[i].Severity) < rank(vulns[j].Severity) })
	total := len(vulns)
	counts := map[string]int{}
	for _, v := range vulns {
		counts[strings.ToLower(v.Severity)]++
	}
	if len(vulns) > MaxReportedVulns {
		vulns = vulns[:MaxReportedVulns]
	}
	return map[string]any{"image": p.Image, "scanner": "trivy", "total": total, "counts": counts, "vulnerabilities": vulns}, nil
}
