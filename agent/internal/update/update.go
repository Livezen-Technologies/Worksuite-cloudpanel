// Package update implements signed, staged, reversible agent self-updates.
//
// Flow: download -> verify sha256 (optional) and ed25519 signature over the
// binary bytes -> stage as <bin>.new (0755) -> health-check "<bin>.new version"
// -> rename <bin> to <bin>.old -> rename <bin>.new to <bin>. Both renames are
// in the same directory, so each is atomic; Rollback swaps .old back.
package update

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"strings"
	"time"
)

// MaxBinarySize bounds downloads.
const MaxBinarySize = 256 << 20

// Updater applies updates to BinaryPath.
type Updater struct {
	PublicKey  ed25519.PublicKey
	BinaryPath string
	HTTP       *http.Client
	// HealthCheck runs the staged binary; default executes "<path> version".
	HealthCheck func(ctx context.Context, path string) error
}

// ParsePublicKey decodes a base64 (std or URL) or hex ed25519 public key.
func ParsePublicKey(s string) (ed25519.PublicKey, error) {
	s = strings.TrimSpace(s)
	if s == "" {
		return nil, errors.New("no update public key configured")
	}
	for _, dec := range []func(string) ([]byte, error){base64.StdEncoding.DecodeString, base64.RawURLEncoding.DecodeString, hex.DecodeString} {
		if b, err := dec(s); err == nil && len(b) == ed25519.PublicKeySize {
			return ed25519.PublicKey(b), nil
		}
	}
	return nil, errors.New("invalid ed25519 public key")
}

func decodeSig(s string) ([]byte, error) {
	s = strings.TrimSpace(s)
	for _, dec := range []func(string) ([]byte, error){base64.StdEncoding.DecodeString, base64.RawURLEncoding.DecodeString, hex.DecodeString} {
		if b, err := dec(s); err == nil && len(b) == ed25519.SignatureSize {
			return b, nil
		}
	}
	return nil, errors.New("invalid ed25519 signature encoding")
}

// Apply downloads, verifies, stages and swaps in a new binary.
func (u *Updater) Apply(ctx context.Context, url, signature, sha256Hex string) error {
	if len(u.PublicKey) != ed25519.PublicKeySize {
		return errors.New("no update public key configured; refusing unsigned update")
	}
	if !strings.HasPrefix(url, "https://") {
		return errors.New("update url must be https")
	}
	sig, err := decodeSig(signature)
	if err != nil {
		return err
	}
	data, err := u.download(ctx, url)
	if err != nil {
		return err
	}
	if sha256Hex != "" {
		sum := sha256.Sum256(data)
		if !strings.EqualFold(hex.EncodeToString(sum[:]), sha256Hex) {
			return errors.New("sha256 mismatch")
		}
	}
	if !ed25519.Verify(u.PublicKey, data, sig) {
		return errors.New("ed25519 signature verification failed")
	}
	return u.Install(ctx, data)
}

// Install stages already-verified bytes and swaps them in.
func (u *Updater) Install(ctx context.Context, data []byte) error {
	staged := u.BinaryPath + ".new"
	_ = os.Remove(staged)
	if err := writeFileSync(staged, data, 0o755); err != nil {
		return fmt.Errorf("stage: %w", err)
	}
	hc := u.HealthCheck
	if hc == nil {
		hc = defaultHealthCheck
	}
	if err := hc(ctx, staged); err != nil {
		_ = os.Remove(staged)
		return fmt.Errorf("staged binary failed health check: %w", err)
	}
	old := u.BinaryPath + ".old"
	if _, err := os.Stat(u.BinaryPath); err == nil {
		_ = os.Remove(old)
		if err := os.Link(u.BinaryPath, old); err != nil {
			// fall back to copy (e.g. different fs semantics)
			b, rerr := os.ReadFile(u.BinaryPath)
			if rerr != nil {
				return fmt.Errorf("keep old binary: %w", err)
			}
			if werr := writeFileSync(old, b, 0o755); werr != nil {
				return fmt.Errorf("keep old binary: %w", werr)
			}
		}
	}
	// Single atomic rename: the binary path never disappears.
	if err := os.Rename(staged, u.BinaryPath); err != nil {
		return fmt.Errorf("swap: %w", err)
	}
	return nil
}

// Rollback restores <bin>.old.
func (u *Updater) Rollback() error {
	old := u.BinaryPath + ".old"
	if _, err := os.Stat(old); err != nil {
		return fmt.Errorf("no previous binary to roll back to: %w", err)
	}
	return os.Rename(old, u.BinaryPath)
}

func (u *Updater) download(ctx context.Context, url string) ([]byte, error) {
	hc := u.HTTP
	if hc == nil {
		hc = &http.Client{Timeout: 10 * time.Minute}
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}
	resp, err := hc.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return nil, fmt.Errorf("download: http %d", resp.StatusCode)
	}
	var buf bytes.Buffer
	n, err := io.Copy(&buf, io.LimitReader(resp.Body, MaxBinarySize+1))
	if err != nil {
		return nil, err
	}
	if n > MaxBinarySize {
		return nil, errors.New("download too large")
	}
	return buf.Bytes(), nil
}

func writeFileSync(p string, b []byte, mode os.FileMode) error {
	f, err := os.OpenFile(p, os.O_CREATE|os.O_EXCL|os.O_WRONLY, mode)
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
	if err := f.Close(); err != nil {
		return err
	}
	return os.Chmod(p, mode)
}

func defaultHealthCheck(ctx context.Context, p string) error {
	ctx, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()
	out, err := exec.CommandContext(ctx, p, "version").CombinedOutput()
	if err != nil {
		return fmt.Errorf("%v: %s", err, bytes.TrimSpace(out))
	}
	return nil
}
