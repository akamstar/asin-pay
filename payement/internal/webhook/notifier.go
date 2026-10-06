// Package webhook envoie au marchand le résultat des paiements sous forme de
// message signé, avec plusieurs tentatives en cas d'échec.
package webhook

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"time"

	"github.com/paiement-asin/payement/internal/payment"
	"github.com/paiement-asin/payement/internal/signature"
)

// Types d'événements envoyés au marchand.
const (
	EventPaymentSucceeded = "payment.succeeded"
	EventPaymentFailed    = "payment.failed"
)

// Event est le corps JSON du webhook. Le marchand utilise ID pour ignorer les doublons.
type Event struct {
	ID        string          `json:"id"`
	Type      string          `json:"event"`
	CreatedAt time.Time       `json:"created_at"`
	Data      payment.Payment `json:"data"`
}

// Notifier poste les événements signés vers l'URL du marchand.
type Notifier struct {
	url         string
	client      *http.Client
	signer      *signature.Signer
	maxAttempts int
	backoff     time.Duration
	logger      *slog.Logger
}

// Config regroupe les paramètres du Notifier.
type Config struct {
	URL         string
	Timeout     time.Duration
	MaxAttempts int
	// Backoff est le délai avant la 2e tentative. Il double à chaque nouvelle tentative.
	Backoff time.Duration
}

// NewNotifier crée un Notifier.
func NewNotifier(cfg Config, signer *signature.Signer, logger *slog.Logger) *Notifier {
	return &Notifier{
		url:         cfg.URL,
		client:      &http.Client{Timeout: cfg.Timeout},
		signer:      signer,
		maxAttempts: max(cfg.MaxAttempts, 1),
		backoff:     cfg.Backoff,
		logger:      logger,
	}
}

// Notify envoie l'événement correspondant au statut de p. Chaque tentative est
// signée de nouveau pour que le timestamp reste frais.
func (n *Notifier) Notify(ctx context.Context, p payment.Payment) error {
	body, err := json.Marshal(Event{
		ID:        "evt_" + randomHex(16),
		Type:      eventType(p.Status),
		CreatedAt: time.Now().UTC(),
		Data:      p,
	})
	if err != nil {
		return fmt.Errorf("encodage de l'événement : %w", err)
	}

	wait := n.backoff
	var lastErr error
	for attempt := 1; attempt <= n.maxAttempts; attempt++ {
		if attempt > 1 {
			select {
			case <-time.After(wait):
				wait *= 2
			case <-ctx.Done():
				return ctx.Err()
			}
		}

		lastErr = n.send(ctx, body)
		if lastErr == nil {
			n.logger.Info("webhook livré", "payment_id", p.ID, "attempt", attempt)
			return nil
		}
		n.logger.Warn("échec de livraison du webhook", "payment_id", p.ID, "attempt", attempt, "max_attempts", n.maxAttempts, "error", lastErr)
	}
	return fmt.Errorf("webhook non livré après %d tentatives : %w", n.maxAttempts, lastErr)
}

func (n *Notifier) send(ctx context.Context, body []byte) error {
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, n.url, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	n.signer.Sign(body).Apply(req.Header)

	resp, err := n.client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	_, _ = io.Copy(io.Discard, resp.Body)

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return fmt.Errorf("réponse HTTP %d", resp.StatusCode)
	}
	return nil
}

func eventType(s payment.Status) string {
	if s == payment.StatusSuccess {
		return EventPaymentSucceeded
	}
	return EventPaymentFailed
}

func randomHex(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}
