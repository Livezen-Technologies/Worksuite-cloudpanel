package mtls

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"io"
	"math/big"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"testing"
	"time"
)

type testCA struct {
	cert *x509.Certificate
	key  *ecdsa.PrivateKey
	pem  []byte
}

func newCA(t *testing.T) *testCA {
	key, _ := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	tmpl := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "test CA"}, IsCA: true, BasicConstraintsValid: true,
		KeyUsage: x509.KeyUsageCertSign, NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(24 * time.Hour)}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, tmpl, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	c, _ := x509.ParseCertificate(der)
	return &testCA{c, key, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})}
}

// sign mimics the panel: signs a CSR with the given validity window.
func (ca *testCA) sign(t *testing.T, csrPEM []byte, nb, na time.Time) []byte {
	b, _ := pem.Decode(csrPEM)
	csr, err := x509.ParseCertificateRequest(b.Bytes)
	if err != nil || csr.CheckSignature() != nil {
		t.Fatal("bad csr", err)
	}
	tmpl := &x509.Certificate{SerialNumber: big.NewInt(time.Now().UnixNano()), Subject: csr.Subject, NotBefore: nb, NotAfter: na,
		ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth}, KeyUsage: x509.KeyUsageDigitalSignature}
	der, err := x509.CreateCertificate(rand.Reader, tmpl, ca.cert, csr.PublicKey, ca.key)
	if err != nil {
		t.Fatal(err)
	}
	return pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})
}

func TestCSRSignStoreAndPresentClientCert(t *testing.T) {
	ca := newCA(t)
	keyPEM, csrPEM, err := NewKeyAndCSR("node-1")
	if err != nil {
		t.Fatal(err)
	}
	dir := t.TempDir()
	s := &Store{CertFile: filepath.Join(dir, "agent.crt"), KeyFile: filepath.Join(dir, "agent.key")}
	if s.Configured() {
		t.Fatal("configured before save")
	}
	now := time.Now()
	if err := s.Save(ca.sign(t, csrPEM, now.Add(-time.Minute), now.Add(90*24*time.Hour)), keyPEM); err != nil {
		t.Fatal(err)
	}
	// A mismatched key is refused.
	otherKey, _, _ := NewKeyAndCSR("x")
	if err := s.Save(ca.sign(t, csrPEM, now, now.Add(time.Hour)), otherKey); err == nil {
		t.Fatal("mismatched key accepted")
	}

	pool := x509.NewCertPool()
	pool.AppendCertsFromPEM(ca.pem)
	srv := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		io.WriteString(w, r.TLS.PeerCertificates[0].Subject.CommonName)
	}))
	srv.TLS = &tls.Config{ClientAuth: tls.RequireAndVerifyClientCert, ClientCAs: pool}
	srv.StartTLS()
	defer srv.Close()

	cfg, err := TLSConfig("", s)
	if err != nil {
		t.Fatal(err)
	}
	cfg.RootCAs = x509.NewCertPool()
	cfg.RootCAs.AddCert(srv.Certificate())
	hc := &http.Client{Transport: &http.Transport{TLSClientConfig: cfg}}
	resp, err := hc.Get(srv.URL)
	if err != nil {
		t.Fatal(err)
	}
	body, _ := io.ReadAll(resp.Body)
	if string(body) != "node-1" {
		t.Fatalf("got %q", body)
	}

	// Without a certificate the server refuses the handshake.
	cfg2, _ := TLSConfig("", &Store{})
	cfg2.RootCAs = cfg.RootCAs
	if _, err := (&http.Client{Transport: &http.Transport{TLSClientConfig: cfg2}}).Get(srv.URL); err == nil {
		t.Fatal("request without client cert succeeded")
	}
}

func TestRenewalAtTwoThirds(t *testing.T) {
	ca := newCA(t)
	keyPEM, csrPEM, _ := NewKeyAndCSR("n")
	dir := t.TempDir()
	s := &Store{CertFile: filepath.Join(dir, "c.pem"), KeyFile: filepath.Join(dir, "k.pem")}
	nb := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)
	if err := s.Save(ca.sign(t, csrPEM, nb, nb.Add(90*24*time.Hour)), keyPEM); err != nil {
		t.Fatal(err)
	}
	if s.NeedsRenewal(nb.Add(59 * 24 * time.Hour)) {
		t.Fatal("renewal before 2/3")
	}
	if !s.NeedsRenewal(nb.Add(60 * 24 * time.Hour)) {
		t.Fatal("no renewal at 2/3")
	}
	if (&Store{}).NeedsRenewal(time.Now()) {
		t.Fatal("unconfigured store wants renewal")
	}
}
