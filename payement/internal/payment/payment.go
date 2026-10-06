// Package payment contient le domaine du simulateur : modèle, validation,
// stockage en mémoire et traitement asynchrone des débits.
package payment

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"regexp"
	"strings"
	"time"
)

// Status représente l'état d'un paiement.
type Status string

const (
	StatusPending Status = "PENDING"
	StatusSuccess Status = "SUCCESS"
	StatusFailed  Status = "FAILED"
)

// CurrencyXOF est la seule devise acceptée (franc CFA, sans décimales).
const CurrencyXOF = "XOF"

// FailureReasonRefused est la raison d'échec renvoyée par le simulateur.
const FailureReasonRefused = "TRANSACTION_REFUSEE"

const maxReferenceLength = 64

var phonePattern = regexp.MustCompile(`^01\d{8}$`)

// DebitRequest est une requête de débit envoyée par le marchand.
type DebitRequest struct {
	MerchantReference string `json:"merchant_reference"`
	Amount            int64  `json:"amount"`
	Currency          string `json:"currency"`
	Phone             string `json:"phone"`
}

// Validate vérifie la requête et renvoie une *ValidationError listant chaque champ invalide.
func (r DebitRequest) Validate() error {
	errs := map[string]string{}

	ref := strings.TrimSpace(r.MerchantReference)
	switch {
	case ref == "":
		errs["merchant_reference"] = "obligatoire"
	case len(ref) > maxReferenceLength:
		errs["merchant_reference"] = fmt.Sprintf("%d caractères maximum", maxReferenceLength)
	}
	if r.Amount <= 0 {
		errs["amount"] = "doit être un entier strictement positif"
	}
	if r.Currency != CurrencyXOF {
		errs["currency"] = "seule la devise XOF est acceptée"
	}
	if !phonePattern.MatchString(r.Phone) {
		errs["phone"] = "doit contenir 10 chiffres et commencer par 01"
	}

	if len(errs) > 0 {
		return &ValidationError{Fields: errs}
	}
	return nil
}

// fingerprint identifie le contenu de la requête pour contrôler l'idempotence.
func (r DebitRequest) fingerprint() string {
	sum := sha256.Sum256(fmt.Appendf(nil, "%s|%d|%s|%s",
		strings.TrimSpace(r.MerchantReference), r.Amount, r.Currency, r.Phone))
	return hex.EncodeToString(sum[:])
}

// Payment est l'état d'un paiement exposé par l'API et envoyé par webhook.
type Payment struct {
	ID                string     `json:"id"`
	MerchantReference string     `json:"merchant_reference"`
	Amount            int64      `json:"amount"`
	Currency          string     `json:"currency"`
	Phone             string     `json:"phone"`
	Status            Status     `json:"status"`
	FailureReason     string     `json:"failure_reason,omitempty"`
	CreatedAt         time.Time  `json:"created_at"`
	ProcessedAt       *time.Time `json:"processed_at,omitempty"`
}

// Outcome est la décision du simulateur pour un paiement.
type Outcome struct {
	Status        Status
	FailureReason string
}

// Decide applique la règle du simulateur : un téléphone dont le dernier chiffre
// est 0 donne un échec, tout autre numéro donne un succès.
func Decide(phone string) Outcome {
	if strings.HasSuffix(phone, "0") {
		return Outcome{Status: StatusFailed, FailureReason: FailureReasonRefused}
	}
	return Outcome{Status: StatusSuccess}
}

func newID() string {
	return "pay_" + randomHex(16)
}

func randomHex(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b) // crypto/rand.Read ne renvoie jamais d'erreur (Go >= 1.24)
	return hex.EncodeToString(b)
}
