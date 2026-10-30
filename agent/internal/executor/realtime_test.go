package executor

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestRealtimeStartsClamonaccWithTheVolumePaths(t *testing.T) {
	e, dir, calls := avExec(t, "", nil)
	if _, err := e.avRealtime(context.Background(), json.RawMessage(`{"enabled":true,"volumes":["site"]}`)); err != nil {
		t.Fatal(err)
	}
	got := strings.Join(*calls, "\n")
	for _, want := range []string{"docker exec clamav pkill -x clamonacc", "printf '%s\\n' \"$@\" > /tmp/ws-watch sh " + dir, "docker exec -d clamav clamonacc --fdpass --log=" + ClamOnAccLog} {
		if !strings.Contains(got, want) {
			t.Fatalf("missing %q in:\n%s", want, got)
		}
	}
	*calls = nil
	e.avRealtime(context.Background(), json.RawMessage(`{"enabled":false}`))
	if len(*calls) != 1 {
		t.Fatalf("turning off must only stop clamonacc: %v", *calls)
	}
}

func TestRealtimeEventsBecomeThreatsInTheirVolume(t *testing.T) {
	e, dir, _ := avExec(t, "{dir}/wp-content/uploads/x.php: Php.Webshell-1 FOUND\n/elsewhere/y.php: Other FOUND\n{dir}/gone.php: Gone FOUND\n", nil)
	os.MkdirAll(filepath.Join(dir, "wp-content/uploads"), 0o755)
	os.WriteFile(filepath.Join(dir, "wp-content/uploads/x.php"), []byte("<?php evil"), 0o644)
	out, err := e.avEvents(context.Background(), json.RawMessage(`{"volumes":["site"]}`))
	if err != nil {
		t.Fatal(err)
	}
	b, _ := json.Marshal(out)
	var m struct {
		Threats []map[string]any `json:"threats"`
	}
	json.Unmarshal(b, &m)
	if len(m.Threats) != 1 || m.Threats[0]["path"] != "wp-content/uploads/x.php" || m.Threats[0]["volume"] != "site" || m.Threats[0]["sha256"] == "" {
		t.Fatalf("threats: %s", b)
	}
}
