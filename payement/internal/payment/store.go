package payment

import "sync"

// MemoryStore conserve les paiements en mémoire. Il est sûr en accès concurrent.
// Un redémarrage efface les données, ce qui est voulu pour un simulateur.
type MemoryStore struct {
	mu          sync.RWMutex
	payments    map[string]Payment
	idempotency map[string]idempotencyEntry
}

type idempotencyEntry struct {
	paymentID   string
	fingerprint string
}

// NewMemoryStore crée un store vide.
func NewMemoryStore() *MemoryStore {
	return &MemoryStore{
		payments:    map[string]Payment{},
		idempotency: map[string]idempotencyEntry{},
	}
}

// CreateOrGet enregistre p sous la clé d'idempotence key, de manière atomique.
// Si la clé existe déjà avec la même empreinte, le paiement existant est renvoyé
// avec created = false. Avec une empreinte différente, ErrIdempotencyConflict est renvoyée.
func (s *MemoryStore) CreateOrGet(key, fingerprint string, p Payment) (Payment, bool, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	if entry, ok := s.idempotency[key]; ok {
		if entry.fingerprint != fingerprint {
			return Payment{}, false, ErrIdempotencyConflict
		}
		return s.payments[entry.paymentID], false, nil
	}

	s.payments[p.ID] = p
	s.idempotency[key] = idempotencyEntry{paymentID: p.ID, fingerprint: fingerprint}
	return p, true, nil
}

// Get renvoie une copie du paiement.
func (s *MemoryStore) Get(id string) (Payment, error) {
	s.mu.RLock()
	defer s.mu.RUnlock()

	p, ok := s.payments[id]
	if !ok {
		return Payment{}, ErrNotFound
	}
	return p, nil
}

// Update applique fn au paiement sous verrou et renvoie la nouvelle version.
func (s *MemoryStore) Update(id string, fn func(*Payment)) (Payment, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	p, ok := s.payments[id]
	if !ok {
		return Payment{}, ErrNotFound
	}
	fn(&p)
	s.payments[id] = p
	return p, nil
}
