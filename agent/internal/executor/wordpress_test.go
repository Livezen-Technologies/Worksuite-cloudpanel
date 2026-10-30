package executor

import (
	"context"
	"encoding/json"
	"errors"
	"strings"
	"testing"
)

func TestWPCLIRunsBesideTheSiteWithoutAShell(t *testing.T) {
	var got []string
	e := &Executor{Run: func(_ context.Context, _ string, _ []string, name string, args ...string) ([]byte, error) {
		got = append([]string{name}, args...)
		return []byte("Success"), nil
	}}
	if _, err := e.wpCLI(context.Background(), json.RawMessage(`{"container":"app-blog-1","args":["plugin","deactivate","akismet"]}`)); err != nil {
		t.Fatal(err)
	}
	want := "docker run --rm --volumes-from app-blog-1 --network container:app-blog-1 --user 33:33 -e HOME=/tmp wordpress:cli wp --path=/var/www/html --no-color plugin deactivate akismet"
	if strings.Join(got, " ") != want {
		t.Fatalf("got  %s\nwant %s", strings.Join(got, " "), want)
	}
	for _, bad := range []string{`["eval","phpinfo();"]`, `["db","drop","--yes"]`, `["plugin","list","--exec=system('id');"]`, `["plugin","list","--require=/tmp/x.php"]`, `["core","version","--ssh=host"]`, `[]`} {
		if _, err := e.wpCLI(context.Background(), json.RawMessage(`{"container":"app-blog-1","args":`+bad+`}`)); err == nil {
			t.Fatalf("%s must be refused", bad)
		}
	}
	if _, err := e.wpCLI(context.Background(), json.RawMessage(`{"container":"x;rm -rf /","args":["core","version"]}`)); err == nil {
		t.Fatal("a bad container name must be refused")
	}
}

func TestWPInventoryCollectsWhatItCan(t *testing.T) {
	e := &Executor{Run: func(_ context.Context, _ string, _ []string, _ string, args ...string) ([]byte, error) {
		cmd := strings.Join(args[len(args)-3:], " ")
		switch {
		case strings.HasSuffix(cmd, "core version"):
			return []byte("6.8.3\n"), nil
		case strings.Contains(cmd, "plugin list") || strings.Contains(strings.Join(args, " "), "plugin list"):
			return []byte(`[{"name":"akismet","status":"active","version":"5.3","update":"available","update_version":"5.4"}]`), nil
		case strings.HasSuffix(cmd, "get blog_public"):
			return []byte("1"), nil
		case strings.HasSuffix(cmd, "maintenance-mode is-active"):
			return nil, errors.New("exit status 1")
		}
		return nil, errors.New("exit status 1")
	}}
	out, err := e.wpInventory(context.Background(), json.RawMessage(`{"container":"app-blog-1"}`))
	if err != nil {
		t.Fatal(err)
	}
	inv := out.(map[string]any)
	if inv["version"] != "6.8.3" || inv["blog_public"] != "1" || inv["maintenance"] != false || inv["plugins"] == nil {
		t.Fatalf("inventory: %v", inv)
	}
	if _, ok := inv["wp_debug"]; ok {
		t.Fatal("a failed probe must leave its field out")
	}
}

func TestWPDBIsLimitedToRestorePoints(t *testing.T) {
	for _, ok := range [][]string{{"db", "export", "wp-content/ws-restore/rp-1.sql"}, {"db", "import", "wp-content/ws-restore/abc.sql"}} {
		if err := checkWPArgs(ok); err != nil {
			t.Errorf("%v: %v", ok, err)
		}
	}
	for _, bad := range [][]string{{"db", "query", "DROP TABLE x"}, {"db", "export", "/etc/passwd"}, {"db", "import", "wp-content/ws-restore/../x.sql"}, {"db", "export"}, {"db", "export", "wp-content/ws-restore/a.sql", "--extra"}} {
		if checkWPArgs(bad) == nil {
			t.Errorf("%v should be refused", bad)
		}
	}
}
