// Package signature signe les messages envoyés au marchand avec Ed25519.
//
// Le contenu signé est `timestamp + "." + corps`, ce qui lie la signature à
// l'instant d'émission : le destinataire peut ainsi rejeter les messages rejoués.
package signature

import (
	"crypto/ed25519"
	"encoding/base64"
	"errors"
	"fmt"
	"net/http"
	"strconv"
	"time"
)

// En-têtes HTTP portant la signature.
const (
	HeaderSignature = "X-Signature"
	HeaderTimestamp = "X-Signature-Timestamp"
	HeaderKeyID     = "X-Signature-Key-Id"
)

// ErrInvalidSignature est renvoyée quand la signature ne correspond pas au message.
var ErrInvalidSignature = errors.New("signature invalide")

// Signer signe des messages avec une clé privée Ed25519.
type Signer struct {
	key   ed25519.PrivateKey
	keyID string
	now   func() time.Time
}

// NewSigner crée un Signer. keyID est transmis tel quel dans l'en-tête X-Signature-Key-Id.
func NewSigner(key ed25519.PrivateKey, keyID string) *Signer {
	return &Signer{key: key, keyID: keyID, now: time.Now}
}

// Headers contient les valeurs des en-têtes de signature d'un message.
type Headers struct {
	Signature string
	Timestamp string
	KeyID     string
}

// Apply écrit les en-têtes de signature dans h.
func (s Headers) Apply(h http.Header) {
	h.Set(HeaderSignature, s.Signature)
	h.Set(HeaderTimestamp, s.Timestamp)
	h.Set(HeaderKeyID, s.KeyID)
}

// Sign signe body à l'instant courant.
func (s *Signer) Sign(body []byte) Headers {
	ts := strconv.FormatInt(s.now().Unix(), 10)
	sig := ed25519.Sign(s.key, message(ts, body))
	return Headers{
		Signature: base64.StdEncoding.EncodeToString(sig),
		Timestamp: ts,
		KeyID:     s.keyID,
	}
}

// Verify vérifie la signature base64 sig de body émise à timestamp.
// Le contrôle de fraîcheur du timestamp reste à la charge du destinataire.
func Verify(pub ed25519.PublicKey, timestamp string, body []byte, sig string) error {
	raw, err := base64.StdEncoding.DecodeString(sig)
	if err != nil {
		return fmt.Errorf("%w : encodage base64 incorrect", ErrInvalidSignature)
	}
	if !ed25519.Verify(pub, message(timestamp, body), raw) {
		return ErrInvalidSignature
	}
	return nil
}

func message(timestamp string, body []byte) []byte {
	msg := make([]byte, 0, len(timestamp)+1+len(body))
	msg = append(msg, timestamp...)
	msg = append(msg, '.')
	return append(msg, body...)
}

// ParsePrivateKey décode une clé privée fournie sous forme de graine (seed) Ed25519
// de 32 octets encodée en base64.
func ParsePrivateKey(b64 string) (ed25519.PrivateKey, error) {
	seed, err := base64.StdEncoding.DecodeString(b64)
	if err != nil {
		return nil, fmt.Errorf("clé privée : base64 incorrect : %w", err)
	}
	if len(seed) != ed25519.SeedSize {
		return nil, fmt.Errorf("clé privée : %d octets attendus, %d reçus", ed25519.SeedSize, len(seed))
	}
	return ed25519.NewKeyFromSeed(seed), nil
}

// EncodePublicKey encode une clé publique en base64.
func EncodePublicKey(pub ed25519.PublicKey) string {
	return base64.StdEncoding.EncodeToString(pub)
}

// EncodePrivateKey encode la graine d'une clé privée en base64.
func EncodePrivateKey(key ed25519.PrivateKey) string {
	return base64.StdEncoding.EncodeToString(key.Seed())
}
