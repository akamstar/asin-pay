package httpapi

import (
	"context"
	"crypto/ed25519"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/gin-gonic/gin"

	"github.com/paiement-asin/payement/internal/payment"
	"github.com/paiement-asin/payement/internal/signature"
)

const testAPIKey = "test-api-key"

type noopNotifier struct{}

func (noopNotifier) Notify(context.Context, payment.Payment) error { return nil }

type testAPI struct {
	router http.Handler
	pub    ed25519.PublicKey
}

func newTestAPI(t *testing.T) *testAPI {
	t.Helper()
	gin.SetMode(gin.TestMode)

	pub, priv, err := ed25519.GenerateKey(nil)
	if err != nil {
		t.Fatal(err)
	}
	logger := slog.New(slog.NewTextHandler(io.Discard, nil))
	svc := payment.NewService(payment.NewMemoryStore(), noopNotifier{},
		func() time.Duration { return time.Hour }, logger)
	t.Cleanup(func() { _ = svc.Shutdown(context.Background()) })

	router := NewRouter(Deps{Service: svc, Signer: signature.NewSigner(priv, "test-key"), APIKey: testAPIKey, Logger: logger})
	return &testAPI{router: router, pub: pub}
}

type call struct {
	method, path, body, apiKey, idemKey string
}

func (a *testAPI) do(c call) *httptest.ResponseRecorder {
	req := httptest.NewRequest(c.method, c.path, strings.NewReader(c.body))
	req.Header.Set("Content-Type", "application/json")
	if c.apiKey != "" {
		req.Header.Set(headerAPIKey, c.apiKey)
	}
	if c.idemKey != "" {
		req.Header.Set(headerIdempotencyKey, c.idemKey)
	}
	rec := httptest.NewRecorder()
	a.router.ServeHTTP(rec, req)
	return rec
}

func (a *testAPI) postPayment(body, idemKey string) *httptest.ResponseRecorder {
	return a.do(call{method: http.MethodPost, path: "/api/v1/payments", body: body, apiKey: testAPIKey, idemKey: idemKey})
}

const validBody = `{"merchant_reference":"DEM-001","amount":7600,"currency":"XOF","phone":"0102030405","operator":"MTN"}`

func decodeError(t *testing.T, rec *httptest.ResponseRecorder) errorPayload {
	t.Helper()
	var body errorBody
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatalf("corps d'erreur illisible : %v (%s)", err, rec.Body.String())
	}
	return body.Error
}

func assertSigned(t *testing.T, a *testAPI, rec *httptest.ResponseRecorder) {
	t.Helper()
	h := rec.Header()
	if err := signature.Verify(a.pub, h.Get(signature.HeaderTimestamp), rec.Body.Bytes(), h.Get(signature.HeaderSignature)); err != nil {
		t.Fatalf("réponse non signée correctement : %v", err)
	}
}

func TestHealth(t *testing.T) {
	a := newTestAPI(t)
	rec := a.do(call{method: http.MethodGet, path: "/health"})
	if rec.Code != http.StatusOK {
		t.Fatalf("statut %d, attendu 200", rec.Code)
	}
}

func TestCreatePaymentReturns202Signed(t *testing.T) {
	a := newTestAPI(t)
	rec := a.postPayment(validBody, "key-1")

	if rec.Code != http.StatusAccepted {
		t.Fatalf("statut %d, attendu 202 : %s", rec.Code, rec.Body.String())
	}
	var p payment.Payment
	if err := json.Unmarshal(rec.Body.Bytes(), &p); err != nil {
		t.Fatal(err)
	}
	if p.Status != payment.StatusPending || !strings.HasPrefix(p.ID, "pay_") || p.Amount != 7600 {
		t.Fatalf("paiement inattendu : %+v", p)
	}
	assertSigned(t, a, rec)
	if rec.Header().Get(headerRequestID) == "" {
		t.Fatal("X-Request-Id attendu dans la réponse")
	}
}

func TestCreatePaymentReplayReturns200WithSamePayment(t *testing.T) {
	a := newTestAPI(t)
	first := a.postPayment(validBody, "key-1")
	replay := a.postPayment(validBody, "key-1")

	if replay.Code != http.StatusOK {
		t.Fatalf("statut %d, attendu 200", replay.Code)
	}
	var p1, p2 payment.Payment
	_ = json.Unmarshal(first.Body.Bytes(), &p1)
	_ = json.Unmarshal(replay.Body.Bytes(), &p2)
	if p1.ID != p2.ID {
		t.Fatalf("le rejeu doit renvoyer le même paiement : %s != %s", p1.ID, p2.ID)
	}
}

func TestCreatePaymentErrors(t *testing.T) {
	tests := []struct {
		name       string
		call       call
		wantStatus int
		wantCode   string
	}{
		{"sans clé d'API", call{method: http.MethodPost, path: "/api/v1/payments", body: validBody, idemKey: "k"}, http.StatusUnauthorized, "UNAUTHORIZED"},
		{"mauvaise clé d'API", call{method: http.MethodPost, path: "/api/v1/payments", body: validBody, apiKey: "faux", idemKey: "k"}, http.StatusUnauthorized, "UNAUTHORIZED"},
		{"sans Idempotency-Key", call{method: http.MethodPost, path: "/api/v1/payments", body: validBody, apiKey: testAPIKey}, http.StatusBadRequest, "INVALID_IDEMPOTENCY_KEY"},
		{"JSON mal formé", call{method: http.MethodPost, path: "/api/v1/payments", body: `{"amount":`, apiKey: testAPIKey, idemKey: "k"}, http.StatusBadRequest, "INVALID_JSON"},
		{"corps vide", call{method: http.MethodPost, path: "/api/v1/payments", body: ``, apiKey: testAPIKey, idemKey: "k"}, http.StatusBadRequest, "INVALID_JSON"},
		{"montant décimal", call{method: http.MethodPost, path: "/api/v1/payments", body: `{"merchant_reference":"D","amount":10.5,"currency":"XOF","phone":"0102030405","operator":"MTN"}`, apiKey: testAPIKey, idemKey: "k"}, http.StatusUnprocessableEntity, "VALIDATION_FAILED"},
		{"téléphone invalide", call{method: http.MethodPost, path: "/api/v1/payments", body: `{"merchant_reference":"D","amount":100,"currency":"XOF","phone":"0702030405","operator":"MTN"}`, apiKey: testAPIKey, idemKey: "k"}, http.StatusUnprocessableEntity, "VALIDATION_FAILED"},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			a := newTestAPI(t)
			rec := a.do(tt.call)
			if rec.Code != tt.wantStatus {
				t.Fatalf("statut %d, attendu %d : %s", rec.Code, tt.wantStatus, rec.Body.String())
			}
			if got := decodeError(t, rec); got.Code != tt.wantCode {
				t.Fatalf("code %q, attendu %q", got.Code, tt.wantCode)
			}
		})
	}
}

func TestCreatePaymentValidationDetailsListFields(t *testing.T) {
	a := newTestAPI(t)
	rec := a.postPayment(`{"merchant_reference":"","amount":0,"currency":"EUR","phone":"123","operator":"X"}`, "key-1")

	details := decodeError(t, rec).Details
	for _, field := range []string{"merchant_reference", "amount", "currency", "phone", "operator"} {
		if _, ok := details[field]; !ok {
			t.Errorf("champ %q absent des détails : %v", field, details)
		}
	}
}

func TestCreatePaymentConflictOnReusedKey(t *testing.T) {
	a := newTestAPI(t)
	a.postPayment(validBody, "key-1")
	rec := a.postPayment(strings.Replace(validBody, "7600", "100", 1), "key-1")

	if rec.Code != http.StatusConflict || decodeError(t, rec).Code != "IDEMPOTENCY_CONFLICT" {
		t.Fatalf("409 IDEMPOTENCY_CONFLICT attendu, obtenu %d : %s", rec.Code, rec.Body.String())
	}
}

func TestShowPayment(t *testing.T) {
	a := newTestAPI(t)
	var created payment.Payment
	_ = json.Unmarshal(a.postPayment(validBody, "key-1").Body.Bytes(), &created)

	rec := a.do(call{method: http.MethodGet, path: "/api/v1/payments/" + created.ID, apiKey: testAPIKey})
	if rec.Code != http.StatusOK {
		t.Fatalf("statut %d, attendu 200", rec.Code)
	}
	assertSigned(t, a, rec)
}

func TestShowUnknownPaymentReturns404(t *testing.T) {
	a := newTestAPI(t)
	rec := a.do(call{method: http.MethodGet, path: "/api/v1/payments/pay_inconnu", apiKey: testAPIKey})
	if rec.Code != http.StatusNotFound || decodeError(t, rec).Code != "PAYMENT_NOT_FOUND" {
		t.Fatalf("404 PAYMENT_NOT_FOUND attendu, obtenu %d : %s", rec.Code, rec.Body.String())
	}
}

func TestUnknownRouteAndMethod(t *testing.T) {
	a := newTestAPI(t)
	if rec := a.do(call{method: http.MethodGet, path: "/inconnu"}); rec.Code != http.StatusNotFound {
		t.Fatalf("404 attendu, obtenu %d", rec.Code)
	}
	if rec := a.do(call{method: http.MethodDelete, path: "/health"}); rec.Code != http.StatusMethodNotAllowed {
		t.Fatalf("405 attendu, obtenu %d", rec.Code)
	}
}
