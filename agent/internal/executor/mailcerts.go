package executor

import (
	"bytes"
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/base64"
	"encoding/json"
	"encoding/pem"
	"errors"
	"io"
	"math/big"
	"os"
	"regexp"
	"strings"
	"time"
)

// MAIL-005: the mail server's TLS certificate comes from the certificates Traefik
// already manages (acme.json) and is copied into the mail config volume as
// ssl/cert.pem and ssl/key.pem. Until Traefik holds one for the hostname, a
// self-signed certificate is created so the mail server can start.

type mailCertPayload struct {
	Hostname string `json:"hostname"`
	Volume   string `json:"volume"`
	Fallback bool   `json:"fallback"`
}

func (e *Executor) mailCerts(ctx context.Context, raw json.RawMessage) (any, error) {
	var p mailCertPayload
	if err := decode(raw, &p); err != nil {
		return nil, err
	}
	if !reHostname.MatchString(p.Hostname) {
		return nil, errors.New("invalid hostname")
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
	cert, key, notAfter, err := e.acmeCertFor(p.Hostname)
	source := "acme"
	if err != nil {
		return nil, err
	}
	if cert == nil {
		if _, err := root.Stat("ssl/cert.pem"); err == nil || !p.Fallback {
			return map[string]any{"found": false, "changed": false}, nil
		}
		if cert, key, notAfter, err = selfSigned(p.Hostname); err != nil {
			return nil, err
		}
		source = "self-signed"
	}
	if err := mkdirAll(root, "ssl"); err != nil {
		return nil, err
	}
	old, _ := readAll(root, "ssl/cert.pem")
	changed := !bytes.Equal(old, cert)
	if changed {
		for name, data := range map[string][]byte{"ssl/key.pem": key, "ssl/cert.pem": cert} {
			f, err := root.OpenFile(name, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, 0o600)
			if err != nil {
				return nil, err
			}
			_, err = f.Write(data)
			if cerr := f.Close(); err == nil {
				err = cerr
			}
			if err != nil {
				return nil, err
			}
		}
	}
	return map[string]any{"found": source == "acme", "source": source, "changed": changed, "not_after": notAfter.UTC().Format(time.RFC3339)}, nil
}

func readAll(root *os.Root, name string) ([]byte, error) {
	f, err := root.Open(name)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	return io.ReadAll(f)
}

// acmeCertFor returns the PEM chain and key Traefik holds for the hostname, or
// a wildcard covering it; nil when there is none.
func (e *Executor) acmeCertFor(host string) ([]byte, []byte, time.Time, error) {
	b, err := os.ReadFile(firstNonEmpty(e.AcmeJSON, DefaultAcmeJSON))
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil, time.Time{}, nil
	}
	if err != nil {
		return nil, nil, time.Time{}, err
	}
	var store map[string]struct {
		Certificates []struct {
			Domain struct {
				Main string   `json:"main"`
				SANs []string `json:"sans"`
			} `json:"domain"`
			Certificate string `json:"certificate"`
			Key         string `json:"key"`
		} `json:"Certificates"`
	}
	if err := json.Unmarshal(b, &store); err != nil {
		return nil, nil, time.Time{}, err
	}
	wild := ""
	if i := strings.IndexByte(host, '.'); i > 0 {
		wild = "*" + host[i:]
	}
	var best []byte
	var bestKey []byte
	var bestExp time.Time
	for _, res := range store {
		for _, c := range res.Certificates {
			names := append([]string{c.Domain.Main}, c.Domain.SANs...)
			match := false
			for _, n := range names {
				if strings.EqualFold(n, host) || (wild != "" && strings.EqualFold(n, wild)) {
					match = true
				}
			}
			if !match {
				continue
			}
			cert, err1 := base64.StdEncoding.DecodeString(c.Certificate)
			key, err2 := base64.StdEncoding.DecodeString(c.Key)
			if err1 != nil || err2 != nil {
				continue
			}
			blk, _ := pem.Decode(cert)
			if blk == nil {
				continue
			}
			x, err := x509.ParseCertificate(blk.Bytes)
			if err != nil || x.NotAfter.Before(bestExp) {
				continue
			}
			best, bestKey, bestExp = cert, key, x.NotAfter
		}
	}
	return best, bestKey, bestExp, nil
}

func selfSigned(host string) ([]byte, []byte, time.Time, error) {
	k, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, nil, time.Time{}, err
	}
	serial, _ := rand.Int(rand.Reader, big.NewInt(1<<62))
	exp := time.Now().Add(2 * 365 * 24 * time.Hour)
	tpl := &x509.Certificate{SerialNumber: serial, Subject: pkix.Name{CommonName: host}, DNSNames: []string{host},
		NotBefore: time.Now().Add(-time.Hour), NotAfter: exp, KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth}}
	der, err := x509.CreateCertificate(rand.Reader, tpl, tpl, &k.PublicKey, k)
	if err != nil {
		return nil, nil, time.Time{}, err
	}
	kb, err := x509.MarshalECPrivateKey(k)
	if err != nil {
		return nil, nil, time.Time{}, err
	}
	return pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: kb}), exp, nil
}

var reHostname = regexp.MustCompile(`^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$`)
