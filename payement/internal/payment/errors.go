package payment

import "errors"

var (
	// ErrNotFound est renvoyée quand aucun paiement ne correspond à l'identifiant.
	ErrNotFound = errors.New("paiement introuvable")
	// ErrIdempotencyConflict est renvoyée quand une clé d'idempotence est réutilisée
	// avec un contenu différent.
	ErrIdempotencyConflict = errors.New("clé d'idempotence déjà utilisée avec une requête différente")
)

// ValidationError liste les champs invalides d'une requête de débit.
type ValidationError struct {
	Fields map[string]string
}

func (e *ValidationError) Error() string {
	return "requête de débit invalide"
}
