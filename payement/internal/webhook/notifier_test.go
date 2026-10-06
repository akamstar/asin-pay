package webhook

import (
	"context"
	"crypto/ed25519"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"

	"github.com/paiement-asin/payement/internal/payment"
	"github.com/paiement-asin/payement/internal/signature"
)

func newTestNotifier(t *testing.T, url string, maxAttempts int) (*Notifier, ed25519.PublicKey) {
	t.Helper()
	pub, priv, err := ed25519.GenerateKey(nil)
	if err != nil {
		t.Fatal(err)
	}
	n := NewNotifier(Config{URL: url, Timeout: time.Second, MaxAttempts: maxAttempts, Backoff: time.Millisecond},
		signature.NewSigner(priv, "test-key"), slog.New(slog.NewTextHandler(io.Discard, nil)))
	return n, pub
}

func samplePayment(status payment.Status) payment.Payment {
	return payment.Payment{ID: "pay_1", MerchantReference: "DEM-001", Amount: 7600,
		Currency: payment.CurrencyXOF, Phone: "0102030405", Status: status}
}

func TestNotifySendsSignedEvent(t *testing.T) {
	var pub ed25519.PublicKey
	received := make(chan Event, 1)

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		if err := signature.Verify(pub, r.Header.Get(signature.HeaderTimestamp), body, r.Header.Get(signature.HeaderSignature)); err != nil {
			t.Errorf("signature invalide : %v", err)
			w.WriteHeader(http.StatusUnauthorized)
			return
		}
		if r.Header.Get(signature.HeaderKeyID) != "test-key" {
			t.Errorf("key id inattendu : %q", r.Header.Get(signature.HeaderKeyID))
		}
		var evt Event
		if err := json.Unmarshal(body, &evt); err != nil {
			t.Errorf("corps illisible : %v", err)
		}
		received <- evt
		w.WriteHeader(http.StatusNoContent)
	}))
	defer srv.Close()

	n, p := newTestNotifier(t, srv.URL, 1)
	pub = p

	if err := n.Notify(context.Background(), samplePayment(payment.StatusFailed)); err != nil {
		t.Fatalf("Notify : %v", err)
	}
	evt := <-received
	if evt.Type != EventPaymentFailed || evt.Data.ID != "pay_1" || evt.ID == "" {
		t.Fatalf("événement inattendu : %+v", evt)
	}
}

func TestNotifyRetriesUntilSuccess(t *testing.T) {
	var calls atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		if calls.Add(1) < 3 {
			w.WriteHeader(http.StatusServiceUnavailable)
			return
		}
		w.WriteHeader(http.StatusOK)
	}))
	defer srv.Close()

	n, _ := newTestNotifier(t, srv.URL, 3)
	if err := n.Notify(context.Background(), samplePayment(payment.StatusSuccess)); err != nil {
		t.Fatalf("Notify : %v", err)
	}
	if got := calls.Load(); got != 3 {
		t.Fatalf("3 tentatives attendues, %d effectuées", got)
	}
}

func TestNotifyGivesUpAfterMaxAttempts(t *testing.T) {
	var calls atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		calls.Add(1)
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer srv.Close()

	n, _ := newTestNotifier(t, srv.URL, 3)
	if err := n.Notify(context.Background(), samplePayment(payment.StatusSuccess)); err == nil {
		t.Fatal("une erreur est attendue")
	}
	if got := calls.Load(); got != 3 {
		t.Fatalf("3 tentatives attendues, %d effectuées", got)
	}
}

func TestNotifyStopsWhenContextCanceled(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer srv.Close()

	n, _ := newTestNotifier(t, srv.URL, 5)
	n.backoff = time.Hour
	ctx, cancel := context.WithTimeout(context.Background(), 50*time.Millisecond)
	defer cancel()

	start := time.Now()
	if err := n.Notify(ctx, samplePayment(payment.StatusSuccess)); err == nil {
		t.Fatal("une erreur est attendue")
	}
	if time.Since(start) > time.Second {
		t.Fatal("Notify doit s'arrêter dès l'annulation du contexte")
	}
}
