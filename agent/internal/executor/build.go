package executor

import (
	"archive/tar"
	"context"
	"encoding/base64"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"

	"github.com/livezen-technologies/cloudpanel/agent/internal/docker"
)

// BuildSpec is the image.build payload.
type BuildSpec struct {
	Repo           string            `json:"repo"`
	Ref            string            `json:"ref"`
	DockerfilePath string            `json:"dockerfile_path"`
	Context        string            `json:"context"`
	BuildArgs      map[string]string `json:"build_args"`
	GitAuth        *struct {
		Username string `json:"username"`
		Token    string `json:"token"`
	} `json:"git_auth"`
	ImageTag string `json:"image_tag"`
	Push     *struct {
		Registry string `json:"registry"`
		Username string `json:"username"`
		Password string `json:"password"`
	} `json:"push"`
	Labels map[string]string `json:"labels"`
}

var (
	reGitRef   = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$`)
	reRegistry = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9.-]{0,252}(:[0-9]{1,5})?$`)
	reLabelKey = regexp.MustCompile(`^[a-z0-9][a-z0-9._-]{0,127}$`)
)

// MaxBuildContext caps the tar context sent to dockerd.
const MaxBuildContext = 1 << 30

func (e *Executor) checkRepo(repo string) error {
	if repo == "" || len(repo) > 512 || strings.HasPrefix(repo, "-") || strings.ContainsAny(repo, " \t\r\n\x00") {
		return fmt.Errorf("invalid repository %q", repo)
	}
	switch {
	case strings.HasPrefix(repo, "https://"), strings.HasPrefix(repo, "http://"):
		return nil
	case strings.HasPrefix(repo, "file://"), strings.HasPrefix(repo, "/"):
		if !e.AllowLocalGit {
			return errors.New("local repositories are disabled on this agent (set DOCKERMGR_ALLOW_LOCAL_GIT=1)")
		}
		return nil
	}
	return fmt.Errorf("repository must be an https:// URL, got %q", repo)
}

// relInside cleans a payload path that must stay inside the checkout.
func relInside(kind, p, def string) (string, error) {
	if p == "" {
		p = def
	}
	c := filepath.Clean(p)
	if filepath.IsAbs(c) || c == ".." || strings.HasPrefix(c, "../") || strings.ContainsRune(c, 0) {
		return "", fmt.Errorf("invalid %s %q (must be relative to the repository)", kind, p)
	}
	return c, nil
}

func (s *BuildSpec) validate(e *Executor) error {
	if err := e.checkRepo(s.Repo); err != nil {
		return err
	}
	if s.Ref == "" {
		s.Ref = "main"
	}
	if !reGitRef.MatchString(s.Ref) || strings.Contains(s.Ref, "..") {
		return fmt.Errorf("invalid ref %q", s.Ref)
	}
	var err error
	if s.DockerfilePath, err = relInside("dockerfile_path", s.DockerfilePath, "Dockerfile"); err != nil {
		return err
	}
	if s.Context, err = relInside("context", s.Context, "."); err != nil {
		return err
	}
	for k := range s.BuildArgs {
		if !reEnvKey.MatchString(k) {
			return fmt.Errorf("invalid build arg %q", k)
		}
	}
	for k := range s.Labels {
		if !reLabelKey.MatchString(k) {
			return fmt.Errorf("invalid label %q", k)
		}
	}
	if err := checkImage(s.ImageTag); err != nil || strings.Contains(s.ImageTag, "@") {
		return fmt.Errorf("invalid image_tag %q", s.ImageTag)
	}
	if s.Push != nil {
		if !reRegistry.MatchString(s.Push.Registry) {
			return fmt.Errorf("invalid push registry %q", s.Push.Registry)
		}
		if !strings.HasPrefix(s.ImageTag, s.Push.Registry+"/") {
			return fmt.Errorf("image_tag %q must start with the push registry %s/", s.ImageTag, s.Push.Registry)
		}
	}
	return nil
}

// splitImageTag splits "host:5000/a/b:tag" into ("host:5000/a/b", "tag").
func splitImageTag(ref string) (string, string) {
	slash := strings.LastIndex(ref, "/")
	if i := strings.LastIndex(ref, ":"); i > slash {
		return ref[:i], ref[i+1:]
	}
	return ref, "latest"
}

// logSink batches build log lines into progress reports (one report per
// second or per 25 lines) and keeps the last 500 lines for the result.
type logSink struct {
	mu       sync.Mutex
	pending  []string
	tail     []string
	last     time.Time
	progress func(int, string)
	pct      int
}

func (l *logSink) line(s string) {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.pending = append(l.pending, s)
	l.tail = append(l.tail, s)
	if len(l.tail) > 500 {
		l.tail = l.tail[len(l.tail)-500:]
	}
	if len(l.pending) >= 25 || time.Since(l.last) >= time.Second {
		l.flushLocked()
	}
}

func (l *logSink) flushLocked() {
	if len(l.pending) == 0 {
		return
	}
	l.progress(l.pct, strings.Join(l.pending, "\n"))
	l.pending = nil
	l.last = time.Now()
}

func (l *logSink) stage(pct int, s string) {
	l.mu.Lock()
	l.pct = pct
	l.mu.Unlock()
	l.line(s)
}

func (l *logSink) flush() {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.flushLocked()
}

func (l *logSink) lines() []string {
	l.mu.Lock()
	defer l.mu.Unlock()
	return append([]string(nil), l.tail...)
}

func (e *Executor) buildImage(ctx context.Context, raw []byte, progress func(int, string)) (any, error) {
	var s BuildSpec
	if err := decode(raw, &s); err != nil {
		return nil, err
	}
	if err := s.validate(e); err != nil {
		return nil, err
	}
	sink := &logSink{progress: progress}
	out := map[string]any{"image": s.ImageTag}
	fail := func(err error) (any, error) {
		sink.flush()
		out["log"] = sink.lines()
		return out, err
	}

	root := filepath.Join(e.StateDir, "builds")
	if err := os.MkdirAll(root, 0o700); err != nil {
		return nil, err
	}
	dir, err := os.MkdirTemp(root, "build-")
	if err != nil {
		return nil, err
	}
	defer os.RemoveAll(dir)
	src := filepath.Join(dir, "src")

	// 1. checkout: fixed git arguments, no shell, credentials only via env.
	env := append(baseEnv()[:1:1], "HOME="+dir, "LANG=C.UTF-8", "GIT_TERMINAL_PROMPT=0", "GIT_CONFIG_NOSYSTEM=1", "GIT_ASKPASS=/bin/false")
	if s.GitAuth != nil && s.GitAuth.Token != "" {
		user := firstNonEmpty(s.GitAuth.Username, "x-access-token")
		basic := base64.StdEncoding.EncodeToString([]byte(user + ":" + s.GitAuth.Token))
		env = append(env, "GIT_CONFIG_COUNT=1", "GIT_CONFIG_KEY_0=http.extraHeader", "GIT_CONFIG_VALUE_0=Authorization: Basic "+basic)
	}
	repo := s.Repo
	if strings.HasPrefix(repo, "/") {
		repo = "file://" + repo // shallow clones need the file:// transport
	}
	sink.stage(5, fmt.Sprintf("$ git clone --depth 1 --branch %s %s", s.Ref, s.Repo))
	gctx, cancel := context.WithTimeout(ctx, 5*time.Minute)
	o, err := e.run(gctx, dir, env, "git", "-c", "core.hooksPath=/dev/null", "-c", "protocol.ext.allow=never",
		"clone", "--depth", "1", "--single-branch", "--no-tags", "--branch", s.Ref, "--", repo, src)
	cancel()
	for _, l := range strings.Split(strings.TrimSpace(string(o)), "\n") {
		if l != "" {
			sink.line(l)
		}
	}
	if err != nil {
		return fail(fmt.Errorf("git clone failed: %v", err))
	}
	if o, err := e.run(ctx, src, env, "git", "rev-parse", "HEAD"); err == nil {
		out["commit"] = strings.TrimSpace(string(o))
		sink.line("checked out " + strings.TrimSpace(string(o)))
	}

	// 2. context + Dockerfile must resolve inside the checkout (no symlink escape).
	realSrc, err := filepath.EvalSymlinks(src)
	if err != nil {
		return fail(err)
	}
	ctxDir, err := filepath.EvalSymlinks(filepath.Join(realSrc, s.Context))
	if err != nil || !within(realSrc, ctxDir) {
		return fail(fmt.Errorf("build context %q not found in the repository", s.Context))
	}
	df := filepath.Join(realSrc, s.DockerfilePath)
	if st, err := os.Lstat(df); err != nil || !st.Mode().IsRegular() {
		return fail(fmt.Errorf("dockerfile %q not found in the repository", s.DockerfilePath))
	}
	dfRel, err := filepath.Rel(ctxDir, df)
	if err != nil || dfRel == ".." || strings.HasPrefix(dfRel, "../") {
		return fail(fmt.Errorf("dockerfile %q must be inside the build context %q", s.DockerfilePath, s.Context))
	}

	// 3. build through the Engine API with a tar context created here.
	sink.stage(20, "building "+s.ImageTag)
	pr, pw := io.Pipe()
	go func() { pw.CloseWithError(writeTarContext(pw, ctxDir)) }()
	labels := map[string]string{}
	for k, v := range s.Labels {
		labels[k] = v
	}
	labels["io.dockermgr.managed"] = "true"
	if c, ok := out["commit"].(string); ok {
		labels["io.dockermgr.commit"] = c
	}
	id, err := e.Docker.BuildImage(ctx, pr, docker.BuildOptions{Tag: s.ImageTag, Dockerfile: filepath.ToSlash(dfRel), BuildArgs: s.BuildArgs, Labels: labels}, sink.line)
	pr.Close()
	if err != nil {
		return fail(err)
	}
	out["image_id"] = id

	// 4. optional push by digest.
	if s.Push != nil {
		sink.stage(80, "pushing "+s.ImageTag)
		name, tag := splitImageTag(s.ImageTag)
		auth := &docker.RegistryAuth{Username: s.Push.Username, Password: s.Push.Password, ServerAddress: s.Push.Registry}
		digest, err := e.Docker.PushImage(ctx, name, tag, auth, sink.line)
		if err != nil {
			return fail(err)
		}
		out["digest"] = digest
		out["repo_digest"] = name + "@" + digest
		sink.line("pushed " + name + "@" + digest)
	} else {
		out["digest"] = id
	}
	sink.line("built " + s.ImageTag + " (" + id + ")")
	sink.flush()
	out["log"] = sink.lines()
	return out, nil
}

func within(root, p string) bool {
	return p == root || strings.HasPrefix(p, root+string(filepath.Separator))
}

// writeTarContext writes dir as a tar stream, skipping .git. Symlinks are
// stored as links (never followed); only regular files, dirs and links.
func writeTarContext(w io.Writer, dir string) error {
	tw := tar.NewWriter(w)
	var total int64
	err := filepath.WalkDir(dir, func(p string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, err := filepath.Rel(dir, p)
		if err != nil || rel == "." {
			return err
		}
		if d.IsDir() && d.Name() == ".git" {
			return filepath.SkipDir
		}
		info, err := d.Info()
		if err != nil {
			return err
		}
		link := ""
		switch {
		case info.Mode()&fs.ModeSymlink != 0:
			if link, err = os.Readlink(p); err != nil {
				return err
			}
		case info.IsDir(), info.Mode().IsRegular():
		default:
			return nil // sockets, devices, fifos are skipped
		}
		hdr, err := tar.FileInfoHeader(info, link)
		if err != nil {
			return err
		}
		hdr.Name = filepath.ToSlash(rel)
		if info.IsDir() {
			hdr.Name += "/"
		}
		hdr.Uid, hdr.Gid, hdr.Uname, hdr.Gname = 0, 0, "", ""
		if err := tw.WriteHeader(hdr); err != nil {
			return err
		}
		if info.Mode().IsRegular() {
			total += info.Size()
			if total > MaxBuildContext {
				return errors.New("build context exceeds 1 GiB")
			}
			f, err := os.Open(p)
			if err != nil {
				return err
			}
			_, err = io.Copy(tw, f)
			f.Close()
			return err
		}
		return nil
	})
	if err != nil {
		return err
	}
	return tw.Close()
}
