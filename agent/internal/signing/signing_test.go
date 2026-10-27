package signing

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"testing"
)

func TestCanonical(t *testing.T) {
	cases := map[string]string{
		`{"b":1,"a":"x"}`: `{"a":"x","b":1}`,
		` { "z" : { "y":[3, {"d":1,"c":2}], "x":null } , "a":true }`: `{"a":true,"z":{"x":null,"y":[3,{"c":2,"d":1}]}}`,
		``:                         `{}`,
		`{"u":"<a&b>/é","n":1.50}`: `{"n":1.50,"u":"<a&b>/é"}`,
	}
	for in, want := range cases {
		got, err := Canonical([]byte(in))
		if err != nil {
			t.Fatal(err)
		}
		if string(got) != want {
			t.Errorf("Canonical(%q) = %s, want %s", in, got, want)
		}
	}
}

// Fixed vector from the task: key "k", id "1", type "container.start",
// payload {"b":1,"a":"x"}, issued_at "2026-01-01T00:00:00Z".
const vectorHex = "b013b8a8ddc4621ea9b7b0cbe6d76235de16cab53337892b0bb475561dd09596"

func TestFixedVector(t *testing.T) {
	m, err := Message("1", "container.start", []byte(`{"b":1,"a":"x"}`), "2026-01-01T00:00:00Z")
	if err != nil {
		t.Fatal(err)
	}
	if string(m) != `1.container.start.{"a":"x","b":1}.2026-01-01T00:00:00Z` {
		t.Fatalf("message = %s", m)
	}
	h := hmac.New(sha256.New, []byte("k"))
	h.Write(m)
	independent := hex.EncodeToString(h.Sum(nil))

	sig, err := Sign([]byte("k"), "1", "container.start", []byte(`{"b":1,"a":"x"}`), "2026-01-01T00:00:00Z")
	if err != nil {
		t.Fatal(err)
	}
	t.Logf("HMAC vector hex: %s", sig)
	if sig != independent || sig != vectorHex {
		t.Fatalf("sig %s independent %s const %s", sig, independent, vectorHex)
	}
	if err := Verify([]byte("k"), "1", "container.start", []byte(`{"a":"x", "b":1}`), "2026-01-01T00:00:00Z", sig); err != nil {
		t.Fatal(err)
	}
	if Verify([]byte("k"), "1", "container.stop", []byte(`{"a":"x","b":1}`), "2026-01-01T00:00:00Z", sig) == nil {
		t.Fatal("type change must invalidate")
	}
	if Verify([]byte("k2"), "1", "container.start", []byte(`{"a":"x","b":1}`), "2026-01-01T00:00:00Z", sig) == nil {
		t.Fatal("key change must invalidate")
	}
}
