package executor

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
)

// Quarantine encryption (AV-5). A quarantined file is stored encrypted with a
// key that never leaves the server, so a copy of the quarantine folder is no
// use on its own and nothing in it can be read or run by mistake. The format
// is "WSQ1" | 16-byte IV | AES-256-CTR ciphertext | HMAC-SHA256 tag over all
// of it (encrypt-then-MAC, streamed, so file size is not limited by memory).
// The tag is checked before a single byte is written back on restore.

var quarantineMagic = []byte("WSQ1")

const quarantineKeyFile = "quarantine.key"

// quarantineKeys returns the encryption and MAC keys, creating the server's
// master key (32 random bytes, mode 0600) the first time.
func (e *Executor) quarantineKeys() (enc, mac []byte, err error) {
	if e.StateDir == "" {
		return nil, nil, errors.New("the agent has no state directory for the quarantine key")
	}
	p := filepath.Join(e.StateDir, quarantineKeyFile)
	master, err := os.ReadFile(p)
	if errors.Is(err, os.ErrNotExist) {
		master = make([]byte, 32)
		if _, err := rand.Read(master); err != nil {
			return nil, nil, err
		}
		f, err := os.OpenFile(p, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0o600)
		if err != nil {
			if !errors.Is(err, os.ErrExist) {
				return nil, nil, err
			}
			// Another command created it first; use theirs.
			if master, err = os.ReadFile(p); err != nil {
				return nil, nil, err
			}
		} else {
			_, werr := f.Write(master)
			if cerr := f.Close(); werr == nil {
				werr = cerr
			}
			if werr != nil {
				return nil, nil, werr
			}
		}
	} else if err != nil {
		return nil, nil, err
	}
	if len(master) != 32 {
		return nil, nil, fmt.Errorf("%s is damaged", quarantineKeyFile)
	}
	derive := func(label string) []byte {
		h := hmac.New(sha256.New, master)
		h.Write([]byte(label))
		return h.Sum(nil)
	}
	return derive("wsq-enc"), derive("wsq-mac"), nil
}

func encryptQuarantine(dst io.Writer, src io.Reader, encKey, macKey []byte) error {
	iv := make([]byte, aes.BlockSize)
	if _, err := rand.Read(iv); err != nil {
		return err
	}
	block, err := aes.NewCipher(encKey)
	if err != nil {
		return err
	}
	mac := hmac.New(sha256.New, macKey)
	out := io.MultiWriter(dst, mac)
	if _, err := out.Write(append(append([]byte{}, quarantineMagic...), iv...)); err != nil {
		return err
	}
	if _, err := io.Copy(cipher.StreamWriter{S: cipher.NewCTR(block, iv), W: out}, src); err != nil {
		return err
	}
	_, err = dst.Write(mac.Sum(nil))
	return err
}

// decryptQuarantine checks the whole file's tag, then decrypts it into dst.
func decryptQuarantine(dst io.Writer, f *os.File, encKey, macKey []byte) error {
	fi, err := f.Stat()
	if err != nil {
		return err
	}
	head := len(quarantineMagic) + aes.BlockSize
	body := fi.Size() - int64(head) - sha256.Size
	if body < 0 {
		return errors.New("the quarantined file is truncated")
	}
	mac := hmac.New(sha256.New, macKey)
	if _, err := io.Copy(mac, io.NewSectionReader(f, 0, int64(head)+body)); err != nil {
		return err
	}
	tag := make([]byte, sha256.Size)
	if _, err := f.ReadAt(tag, int64(head)+body); err != nil {
		return err
	}
	if !hmac.Equal(tag, mac.Sum(nil)) {
		return errors.New("the quarantined file failed its integrity check and was not restored")
	}
	hdr := make([]byte, head)
	if _, err := f.ReadAt(hdr, 0); err != nil {
		return err
	}
	if string(hdr[:len(quarantineMagic)]) != string(quarantineMagic) {
		return errors.New("not an encrypted quarantine file")
	}
	block, err := aes.NewCipher(encKey)
	if err != nil {
		return err
	}
	_, err = io.Copy(dst, cipher.StreamReader{S: cipher.NewCTR(block, hdr[len(quarantineMagic):]), R: io.NewSectionReader(f, int64(head), body)})
	return err
}
