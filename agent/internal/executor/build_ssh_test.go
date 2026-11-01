package executor

import (
	"context"
	"os"
	"strings"
	"testing"
)

// A placeholder, assembled at run time so secret scanners don't mistake it for a real key.
var testDeployKey = "-----BEGIN " + "OPENSSH PRIVATE KEY-----\n" + strings.Repeat("A", 40) + "\n-----END " + "OPENSSH PRIVATE KEY-----"

func TestImageBuildClonesOverSSHWithADeployKey(t *testing.T) {
	e, _, _ := buildSetup(t)
	g := &fakeGit{}
	var keyMode os.FileMode
	var keyBody string
	e.Run = func(ctx context.Context, dir string, env []string, name string, args ...string) ([]byte, error) {
		for _, kv := range env {
			if v, ok := strings.CutPrefix(kv, "GIT_SSH_COMMAND="); ok {
				f := strings.Fields(v)
				for i, a := range f {
					if a == "-i" && i+1 < len(f) {
						if st, err := os.Stat(f[i+1]); err == nil {
							keyMode = st.Mode().Perm()
							b, _ := os.ReadFile(f[i+1])
							keyBody = string(b)
						}
					}
				}
			}
		}
		return g.run(ctx, dir, env, name, args...)
	}
	payload := `{"repo":"git@github.com:acme/shop.git","ref":"main","dockerfile_path":"svc/Dockerfile","context":"svc","git_ssh_key":` + quote(testDeployKey) + `,"image_tag":"dmp-build/acme-shop:v1"}`
	r, err := e.Handle(context.Background(), cmd("bs1", "image.build", payload, now))
	if err != nil || r.Status != "succeeded" {
		t.Fatalf("build over ssh: %v %+v", err, r)
	}
	if keyMode != 0o600 || !strings.Contains(keyBody, "BEGIN OPENSSH PRIVATE KEY") {
		t.Fatalf("deploy key file mode %v", keyMode)
	}
	envs := strings.Join(g.envs[0], "\n")
	if !strings.Contains(envs, "IdentitiesOnly=yes") || !strings.Contains(envs, "StrictHostKeyChecking=accept-new") || !strings.Contains(envs, "-F /dev/null") {
		t.Fatalf("ssh command %v", envs)
	}
	if strings.Contains(strings.Join(g.calls[0], " "), "PRIVATE KEY") {
		t.Fatal("the key must never be on the command line")
	}
}

func TestBuildSpecSSHRules(t *testing.T) {
	e, _, _ := buildSetup(t)
	cases := []struct {
		repo, key string
		ok        bool
	}{
		{"git@github.com:acme/shop.git", testDeployKey, true},
		{"ssh://git@gitlab.example.com:2222/acme/shop.git", testDeployKey, true},
		{"git@github.com:acme/shop.git", "", false},                     // needs a key
		{"https://github.com/acme/shop.git", testDeployKey, false},      // key only with ssh
		{"git@-oProxyCommand=evil:acme/shop.git", testDeployKey, false}, // option smuggling
		{"ssh://git@host/acme;rm -rf /", testDeployKey, false},
		{"git@github.com:acme/shop.git", "not a key", false},
	}
	for _, c := range cases {
		s := BuildSpec{Repo: c.repo, GitSSHKey: c.key, ImageTag: "dmp-build/x:1"}
		if err := s.validate(e); (err == nil) != c.ok {
			t.Errorf("%s (key %t): err=%v", c.repo, c.key != "", err)
		}
	}
}

func quote(s string) string { return `"` + strings.ReplaceAll(s, "\n", `\n`) + `"` }
