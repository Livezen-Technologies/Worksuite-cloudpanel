// Package mtls manages the agent's client certificate (SRS SEC-003): an
// ECDSA P-256 key and CSR generated locally, the certificate signed by the
// panel's agent CA, presented on every /agent/v1 and relay connection, and
// renewed once two thirds of its lifetime have passed.
package mtls

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sync"
	"time"
)

// NewKeyAndCSR returns a PEM EC private key and a PEM CSR with CN=cn.
func NewKeyAndCSR(cn string) (keyPEM, csrPEM []byte, err error) {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, nil, err
	}
	der, err := x509.MarshalECPrivateKey(key)
	if err != nil {
		return nil, nil, err
	}
	keyPEM = pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: der})
	tmpl := &x509.CertificateRequest{
		Subject:            pkix.Name{CommonName: cn, Organization: []string{"Docker Manager Portal agent"}},
		SignatureAlgorithm: x509.ECDSAWithSHA256,
	}
	csr, err := x509.CreateCertificateRequest(rand.Reader, tmpl, key)
	if err != nil {
		return nil, nil, err
	}
	return keyPEM, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE REQUEST", Bytes: csr}), nil
}

// Store keeps the key pair on disk (0600) and caches the parsed certificate.
type Store struct {
	CertFile, KeyFile string

	mu     sync.Mutex
	cert   *tls.Certificate
	leaf   *x509.Certificate
	loaded time.Time // mtime of CertFile when cached
}

// Configured reports whether both paths are set and the cert file exists.
func (s *Store) Configured() bool {
	if s == nil || s.CertFile == "" || s.KeyFile == "" {
		return false
	}
	_, err := os.Stat(s.CertFile)
	return err == nil
}

func (s *Store) load() (*tls.Certificate, *x509.Certificate, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	st, err := os.Stat(s.CertFile)
	if err != nil {
		return nil, nil, err
	}
	if s.cert != nil && st.ModTime().Equal(s.loaded) {
		return s.cert, s.leaf, nil
	}
	pair, err := tls.LoadX509KeyPair(s.CertFile, s.KeyFile)
	if err != nil {
		return nil, nil, err
	}
	leaf, err := x509.ParseCertificate(pair.Certificate[0])
	if err != nil {
		return nil, nil, err
	}
	pair.Leaf = leaf
	s.cert, s.leaf, s.loaded = &pair, leaf, st.ModTime()
	return s.cert, s.leaf, nil
}

// Leaf returns the current certificate.
func (s *Store) Leaf() (*x509.Certificate, error) {
	_, leaf, err := s.load()
	return leaf, err
}

// GetClientCertificate is a tls.Config hook. Without a certificate it sends
// none (the panel decides whether that is acceptable: AGENT_MTLS).
func (s *Store) GetClientCertificate(*tls.CertificateRequestInfo) (*tls.Certificate, error) {
	if !s.Configured() {
		return &tls.Certificate{}, nil
	}
	c, _, err := s.load()
	if err != nil {
		return &tls.Certificate{}, nil
	}
	return c, nil
}

// RenewAt is NotBefore + 2/3 of the lifetime.
func RenewAt(leaf *x509.Certificate) time.Time {
	life := leaf.NotAfter.Sub(leaf.NotBefore)
	return leaf.NotBefore.Add(life * 2 / 3)
}

// NeedsRenewal reports whether the stored certificate is past 2/3 of its life.
func (s *Store) NeedsRenewal(now time.Time) bool {
	if !s.Configured() {
		return false
	}
	leaf, err := s.Leaf()
	if err != nil {
		return true
	}
	return !now.Before(RenewAt(leaf))
}

// Save validates that certPEM matches keyPEM and writes both atomically (0600).
func (s *Store) Save(certPEM, keyPEM []byte) error {
	if s.CertFile == "" || s.KeyFile == "" {
		return errors.New("mtls: cert_file/key_file not configured")
	}
	if _, err := tls.X509KeyPair(certPEM, keyPEM); err != nil {
		return fmt.Errorf("mtls: certificate does not match key: %w", err)
	}
	if err := writeAtomic(s.KeyFile, keyPEM); err != nil {
		return err
	}
	if err := writeAtomic(s.CertFile, certPEM); err != nil {
		return err
	}
	s.mu.Lock()
	s.cert = nil
	s.mu.Unlock()
	return nil
}

func writeAtomic(path string, b []byte) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(path), ".tmp-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if err := tmp.Chmod(0o600); err != nil {
		tmp.Close()
		return err
	}
	if _, err := tmp.Write(b); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	return os.Rename(tmp.Name(), path)
}

// TLSConfig builds the client TLS config: optional extra CA file for the
// panel's server certificate plus the client certificate hook.
func TLSConfig(caFile string, store *Store) (*tls.Config, error) {
	cfg := &tls.Config{MinVersion: tls.VersionTLS12}
	if caFile != "" {
		b, err := os.ReadFile(caFile)
		if err != nil {
			return nil, err
		}
		pool, err := x509.SystemCertPool()
		if err != nil || pool == nil {
			pool = x509.NewCertPool()
		}
		if !pool.AppendCertsFromPEM(b) {
			return nil, fmt.Errorf("no certificates in %s", caFile)
		}
		cfg.RootCAs = pool
	}
	if store != nil {
		cfg.GetClientCertificate = store.GetClientCertificate
	}
	return cfg, nil
}
