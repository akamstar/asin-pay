package payment

import (
	"context"
	"errors"
	"log/slog"
	"math/rand/v2"
	"sync"
	"time"
)

// Notifier transmet au marchand le résultat d'un paiement (webhook signé).
type Notifier interface {
	Notify(ctx context.Context, p Payment) error
}

// DelayFunc renvoie le délai de traitement d'un paiement.
type DelayFunc func() time.Duration

// RandomDelay renvoie un délai aléatoire uniforme dans [minDelay, maxDelay].
func RandomDelay(minDelay, maxDelay time.Duration) DelayFunc {
	return func() time.Duration {
		if maxDelay <= minDelay {
			return minDelay
		}
		return minDelay + rand.N(maxDelay-minDelay+1)
	}
}

// Service accepte les requêtes de débit et les traite de manière asynchrone :
// le paiement est créé en PENDING, puis, après un délai, son résultat est
// enregistré et notifié.
type Service struct {
	store    *MemoryStore
	notifier Notifier
	delay    DelayFunc
	now      func() time.Time
	logger   *slog.Logger

	ctx    context.Context
	cancel context.CancelFunc
	wg     sync.WaitGroup
}

// Option personnalise le Service (utile pour les tests).
type Option func(*Service)

// WithClock remplace l'horloge du service.
func WithClock(now func() time.Time) Option {
	return func(s *Service) { s.now = now }
}

// NewService crée un service prêt à traiter des paiements.
func NewService(store *MemoryStore, notifier Notifier, delay DelayFunc, logger *slog.Logger, opts ...Option) *Service {
	ctx, cancel := context.WithCancel(context.Background())
	s := &Service{
		store:    store,
		notifier: notifier,
		delay:    delay,
		now:      time.Now,
		logger:   logger,
		ctx:      ctx,
		cancel:   cancel,
	}
	for _, opt := range opts {
		opt(s)
	}
	return s
}

// Initiate enregistre une requête de débit et lance son traitement.
// created vaut false quand la requête est un rejeu idempotent.
func (s *Service) Initiate(idempotencyKey string, req DebitRequest) (p Payment, created bool, err error) {
	if err := req.Validate(); err != nil {
		return Payment{}, false, err
	}

	p = Payment{
		ID:                newID(),
		MerchantReference: req.MerchantReference,
		Amount:            req.Amount,
		Currency:          req.Currency,
		Phone:             req.Phone,
		Operator:          req.Operator,
		Status:            StatusPending,
		CreatedAt:         s.now().UTC(),
	}

	p, created, err = s.store.CreateOrGet(idempotencyKey, req.fingerprint(), p)
	if err != nil {
		return Payment{}, false, err
	}
	if created {
		s.logger.Info("paiement reçu", "payment_id", p.ID, "merchant_reference", p.MerchantReference, "amount", p.Amount)
		s.wg.Go(func() { s.process(p.ID) })
	}
	return p, created, nil
}

// Get renvoie l'état courant d'un paiement.
func (s *Service) Get(id string) (Payment, error) {
	return s.store.Get(id)
}

// Shutdown annule les traitements en cours et attend leur fin (ou l'expiration de ctx).
// Les paiements interrompus restent en PENDING.
func (s *Service) Shutdown(ctx context.Context) error {
	s.cancel()
	done := make(chan struct{})
	go func() {
		s.wg.Wait()
		close(done)
	}()
	select {
	case <-done:
		return nil
	case <-ctx.Done():
		return ctx.Err()
	}
}

func (s *Service) process(id string) {
	delay := s.delay()
	timer := time.NewTimer(delay)
	defer timer.Stop()

	select {
	case <-timer.C:
	case <-s.ctx.Done():
		s.logger.Warn("traitement interrompu par l'arrêt du service", "payment_id", id)
		return
	}

	current, err := s.store.Get(id)
	if err != nil {
		s.logger.Error("paiement introuvable pendant le traitement", "payment_id", id, "error", err)
		return
	}
	outcome := Decide(current.Phone)
	processedAt := s.now().UTC()

	p, err := s.store.Update(id, func(p *Payment) {
		p.Status = outcome.Status
		p.FailureReason = outcome.FailureReason
		p.ProcessedAt = &processedAt
	})
	if err != nil {
		s.logger.Error("mise à jour du paiement impossible", "payment_id", id, "error", err)
		return
	}
	s.logger.Info("paiement traité", "payment_id", id, "status", p.Status, "delay", delay.String())

	if err := s.notifier.Notify(s.ctx, p); err != nil && !errors.Is(err, context.Canceled) {
		s.logger.Error("notification du marchand en échec", "payment_id", id, "error", err)
	}
}
