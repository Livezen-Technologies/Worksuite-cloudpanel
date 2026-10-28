// Package signing implements the canonical JSON encoding and HMAC-SHA256
// command signatures defined in docs/API.md ("Agent protocol").
package signing

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"sort"
)

// Canonical re-encodes raw JSON with object keys sorted recursively and no
// insignificant whitespace. Numbers are kept as their original literal text;
// strings are encoded without HTML escaping. An empty input is treated as {}.
func Canonical(raw []byte) ([]byte, error) {
	raw = bytes.TrimSpace(raw)
	if len(raw) == 0 {
		return []byte("{}"), nil
	}
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.UseNumber()
	var v any
	if err := dec.Decode(&v); err != nil {
		return nil, fmt.Errorf("canonical json: %w", err)
	}
	if dec.More() {
		return nil, errors.New("canonical json: trailing data")
	}
	var buf bytes.Buffer
	if err := write(&buf, v); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}

func write(buf *bytes.Buffer, v any) error {
	switch t := v.(type) {
	case nil:
		buf.WriteString("null")
	case bool:
		if t {
			buf.WriteString("true")
		} else {
			buf.WriteString("false")
		}
	case json.Number:
		buf.WriteString(t.String())
	case string:
		writeString(buf, t)
	case []any:
		buf.WriteByte('[')
		for i, e := range t {
			if i > 0 {
				buf.WriteByte(',')
			}
			if err := write(buf, e); err != nil {
				return err
			}
		}
		buf.WriteByte(']')
	case map[string]any:
		keys := make([]string, 0, len(t))
		for k := range t {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		buf.WriteByte('{')
		for i, k := range keys {
			if i > 0 {
				buf.WriteByte(',')
			}
			writeString(buf, k)
			buf.WriteByte(':')
			if err := write(buf, t[k]); err != nil {
				return err
			}
		}
		buf.WriteByte('}')
	default:
		return fmt.Errorf("canonical json: unsupported type %T", v)
	}
	return nil
}

func writeString(buf *bytes.Buffer, s string) {
	var b bytes.Buffer
	enc := json.NewEncoder(&b)
	enc.SetEscapeHTML(false)
	_ = enc.Encode(s)
	buf.Write(bytes.TrimRight(b.Bytes(), "\n"))
}

// Message builds the signed string: id + "." + type + "." + canonical(payload) + "." + issued_at.
func Message(id, typ string, payload []byte, issuedAt string) ([]byte, error) {
	c, err := Canonical(payload)
	if err != nil {
		return nil, err
	}
	m := make([]byte, 0, len(id)+len(typ)+len(c)+len(issuedAt)+3)
	m = append(m, id...)
	m = append(m, '.')
	m = append(m, typ...)
	m = append(m, '.')
	m = append(m, c...)
	m = append(m, '.')
	m = append(m, issuedAt...)
	return m, nil
}

// Sign returns hex(HMAC-SHA256(key, Message(...))).
func Sign(key []byte, id, typ string, payload []byte, issuedAt string) (string, error) {
	m, err := Message(id, typ, payload, issuedAt)
	if err != nil {
		return "", err
	}
	h := hmac.New(sha256.New, key)
	h.Write(m)
	return hex.EncodeToString(h.Sum(nil)), nil
}

// Verify checks a hex signature in constant time.
func Verify(key []byte, id, typ string, payload []byte, issuedAt, sigHex string) error {
	if len(key) == 0 {
		return errors.New("no hmac key configured")
	}
	want, err := Sign(key, id, typ, payload, issuedAt)
	if err != nil {
		return err
	}
	got, err := hex.DecodeString(sigHex)
	if err != nil {
		return errors.New("signature is not hex")
	}
	w, _ := hex.DecodeString(want)
	if !hmac.Equal(w, got) {
		return errors.New("bad signature")
	}
	return nil
}
