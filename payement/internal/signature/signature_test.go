package signature

import (
	"crypto/ed25519"
	"errors"
	"net/http"
	"strconv"
	"testing"
	"time"
)

func newTestSigner(t *testing.T) (*Signer, ed25519.PublicKey) {
	t.Helper()
	pub, priv, err := ed25519.GenerateKey(nil)
	if err != nil {
		t.Fatal(err)
	}
	s := NewSigner(priv, "test-key")
	s.now = func() time.Time { return time.Unix(1_700_000_000, 0) }
	return s, pub
}

func TestSignThenVerify(t *testing.T) {
	signer, pub := newTestSigner(t)
	body := []byte(`{"id":"pay_1","status":"SUCCESS"}`)

	h := signer.Sign(body)
	if h.Timestamp != strconv.Itoa(1_700_000_000) || h.KeyID != "test-key" {
		t.Fatalf("en-têtes inattendus : %+v", h)
	}
	if err := Verify(pub, h.Timestamp, body, h.Signature); err != nil {
		t.Fatalf("la signature devrait être valide : %v", err)
	}
}

func TestVerifyRejectsTampering(t *testing.T) {
	signer, pub := newTestSigner(t)
	body := []byte(`{"id":"pay_1","status":"FAILED"}`)
	h := signer.Sign(body)

	otherPub, _, _ := ed25519.GenerateKey(nil)

	tests := []struct {
		name string
		pub  ed25519.PublicKey
		ts   string
		body []byte
		sig  string
	}{
		{"corps modifié", pub, h.Timestamp, []byte(`{"id":"pay_1","status":"SUCCESS"}`), h.Signature},
		{"timestamp modifié", pub, "1700000001", body, h.Signature},
		{"autre clé publique", otherPub, h.Timestamp, body, h.Signature},
		{"signature non base64", pub, h.Timestamp, body, "%%%"},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if err := Verify(tt.pub, tt.ts, tt.body, tt.sig); !errors.Is(err, ErrInvalidSignature) {
				t.Fatalf("ErrInvalidSignature attendue, obtenu %v", err)
			}
		})
	}
}

func TestHeadersApply(t *testing.T) {
	h := http.Header{}
	Headers{Signature: "sig", Timestamp: "123", KeyID: "kid"}.Apply(h)
	if h.Get(HeaderSignature) != "sig" || h.Get(HeaderTimestamp) != "123" || h.Get(HeaderKeyID) != "kid" {
		t.Fatalf("en-têtes mal appliqués : %v", h)
	}
}

func TestPrivateKeyRoundTrip(t *testing.T) {
	_, priv, _ := ed25519.GenerateKey(nil)
	parsed, err := ParsePrivateKey(EncodePrivateKey(priv))
	if err != nil {
		t.Fatal(err)
	}
	if !parsed.Equal(priv) {
		t.Fatal("la clé relue doit être identique")
	}
}

func TestParsePrivateKeyErrors(t *testing.T) {
	for _, in := range []string{"pas du base64 !", "c2hvcnQ="} {
		if _, err := ParsePrivateKey(in); err == nil {
			t.Errorf("ParsePrivateKey(%q) devrait échouer", in)
		}
	}
}
