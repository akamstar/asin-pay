package payment

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"sync"
	"testing"
	"time"
)

// recordingNotifier capture les paiements notifiés.
type recordingNotifier struct {
	mu       sync.Mutex
	payments []Payment
	notified chan Payment
}

func newRecordingNotifier() *recordingNotifier {
	return &recordingNotifier{notified: make(chan Payment, 10)}
}

func (n *recordingNotifier) Notify(_ context.Context, p Payment) error {
	n.mu.Lock()
	n.payments = append(n.payments, p)
	n.mu.Unlock()
	n.notified <- p
	return nil
}

func (n *recordingNotifier) count() int {
	n.mu.Lock()
	defer n.mu.Unlock()
	return len(n.payments)
}

func fixedDelay(d time.Duration) DelayFunc {
	return func() time.Duration { return d }
}

func discardLogger() *slog.Logger {
	return slog.New(slog.NewTextHandler(io.Discard, nil))
}

func newTestService(t *testing.T, delay time.Duration) (*Service, *recordingNotifier) {
	t.Helper()
	notifier := newRecordingNotifier()
	svc := NewService(NewMemoryStore(), notifier, fixedDelay(delay), discardLogger())
	t.Cleanup(func() { _ = svc.Shutdown(context.Background()) })
	return svc, notifier
}

func waitNotification(t *testing.T, n *recordingNotifier) Payment {
	t.Helper()
	select {
	case p := <-n.notified:
		return p
	case <-time.After(2 * time.Second):
		t.Fatal("aucune notification reçue")
		return Payment{}
	}
}

func TestInitiateReturnsPendingThenNotifiesResult(t *testing.T) {
	tests := []struct {
		phone      string
		wantStatus Status
	}{
		{"0102030405", StatusSuccess},
		{"0102030400", StatusFailed},
	}
	for _, tt := range tests {
		t.Run(tt.phone, func(t *testing.T) {
			svc, notifier := newTestService(t, 10*time.Millisecond)
			req := validRequest()
			req.Phone = tt.phone

			p, created, err := svc.Initiate("key-1", req)
			if err != nil {
				t.Fatalf("Initiate : %v", err)
			}
			if !created || p.Status != StatusPending {
				t.Fatalf("attendu un paiement créé en PENDING, obtenu created=%v status=%s", created, p.Status)
			}

			notified := waitNotification(t, notifier)
			if notified.ID != p.ID || notified.Status != tt.wantStatus || notified.ProcessedAt == nil {
				t.Fatalf("notification inattendue : %+v", notified)
			}

			stored, err := svc.Get(p.ID)
			if err != nil || stored.Status != tt.wantStatus {
				t.Fatalf("statut stocké = %s (err %v), attendu %s", stored.Status, err, tt.wantStatus)
			}
		})
	}
}

func TestInitiateIsIdempotent(t *testing.T) {
	svc, notifier := newTestService(t, 10*time.Millisecond)

	first, created, err := svc.Initiate("key-1", validRequest())
	if err != nil || !created {
		t.Fatalf("première requête : created=%v err=%v", created, err)
	}
	replay, created, err := svc.Initiate("key-1", validRequest())
	if err != nil || created {
		t.Fatalf("rejeu : created=%v err=%v", created, err)
	}
	if replay.ID != first.ID {
		t.Fatalf("le rejeu doit renvoyer le même paiement (%s != %s)", replay.ID, first.ID)
	}

	waitNotification(t, notifier)
	time.Sleep(50 * time.Millisecond)
	if got := notifier.count(); got != 1 {
		t.Fatalf("un seul traitement attendu, %d notifications", got)
	}
}

func TestInitiateRejectsReusedKeyWithDifferentPayload(t *testing.T) {
	svc, _ := newTestService(t, time.Hour)

	if _, _, err := svc.Initiate("key-1", validRequest()); err != nil {
		t.Fatal(err)
	}
	other := validRequest()
	other.Amount = 1
	if _, _, err := svc.Initiate("key-1", other); !errors.Is(err, ErrIdempotencyConflict) {
		t.Fatalf("ErrIdempotencyConflict attendue, obtenu %v", err)
	}
}

func TestInitiateRejectsInvalidRequest(t *testing.T) {
	svc, _ := newTestService(t, time.Hour)
	req := validRequest()
	req.Phone = "0502030405"

	_, _, err := svc.Initiate("key-1", req)
	var verr *ValidationError
	if !errors.As(err, &verr) {
		t.Fatalf("ValidationError attendue, obtenu %v", err)
	}
}

func TestGetUnknownPayment(t *testing.T) {
	svc, _ := newTestService(t, time.Hour)
	if _, err := svc.Get("pay_inconnu"); !errors.Is(err, ErrNotFound) {
		t.Fatalf("ErrNotFound attendue, obtenu %v", err)
	}
}

func TestShutdownCancelsPendingProcessing(t *testing.T) {
	notifier := newRecordingNotifier()
	svc := NewService(NewMemoryStore(), notifier, fixedDelay(time.Hour), discardLogger())

	p, _, err := svc.Initiate("key-1", validRequest())
	if err != nil {
		t.Fatal(err)
	}

	ctx, cancel := context.WithTimeout(context.Background(), time.Second)
	defer cancel()
	if err := svc.Shutdown(ctx); err != nil {
		t.Fatalf("Shutdown : %v", err)
	}
	if notifier.count() != 0 {
		t.Fatal("aucune notification attendue après l'arrêt")
	}
	if stored, _ := svc.Get(p.ID); stored.Status != StatusPending {
		t.Fatalf("le paiement interrompu doit rester PENDING, obtenu %s", stored.Status)
	}
}

func TestConcurrentInitiateWithSameKeyCreatesOnePayment(t *testing.T) {
	svc, _ := newTestService(t, time.Hour)

	const n = 50
	ids := make(chan string, n)
	var wg sync.WaitGroup
	for range n {
		wg.Go(func() {
			p, _, err := svc.Initiate("key-1", validRequest())
			if err != nil {
				t.Error(err)
				return
			}
			ids <- p.ID
		})
	}
	wg.Wait()
	close(ids)

	first := <-ids
	for id := range ids {
		if id != first {
			t.Fatalf("plusieurs paiements créés pour la même clé : %s et %s", first, id)
		}
	}
}
