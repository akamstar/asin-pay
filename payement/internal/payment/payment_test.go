package payment

import (
	"errors"
	"testing"
	"time"
)

func validRequest() DebitRequest {
	return DebitRequest{MerchantReference: "DEM-001", Amount: 7600, Currency: CurrencyXOF, Phone: "0102030405"}
}

func TestDebitRequestValidate(t *testing.T) {
	tests := []struct {
		name      string
		mutate    func(*DebitRequest)
		wantField string
	}{
		{"requête valide", func(*DebitRequest) {}, ""},
		{"référence vide", func(r *DebitRequest) { r.MerchantReference = "  " }, "merchant_reference"},
		{"référence trop longue", func(r *DebitRequest) { r.MerchantReference = string(make([]byte, 65)) }, "merchant_reference"},
		{"montant nul", func(r *DebitRequest) { r.Amount = 0 }, "amount"},
		{"montant négatif", func(r *DebitRequest) { r.Amount = -100 }, "amount"},
		{"devise autre que XOF", func(r *DebitRequest) { r.Currency = "EUR" }, "currency"},
		{"téléphone ne commençant pas par 01", func(r *DebitRequest) { r.Phone = "0702030405" }, "phone"},
		{"téléphone trop court", func(r *DebitRequest) { r.Phone = "010203040" }, "phone"},
		{"téléphone trop long", func(r *DebitRequest) { r.Phone = "01020304050" }, "phone"},
		{"téléphone non numérique", func(r *DebitRequest) { r.Phone = "01020304ab" }, "phone"},
		{"téléphone avec indicatif", func(r *DebitRequest) { r.Phone = "+2250102030405" }, "phone"},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			req := validRequest()
			tt.mutate(&req)
			err := req.Validate()

			if tt.wantField == "" {
				if err != nil {
					t.Fatalf("erreur inattendue : %v", err)
				}
				return
			}
			var verr *ValidationError
			if !errors.As(err, &verr) {
				t.Fatalf("ValidationError attendue, obtenu %v", err)
			}
			if _, ok := verr.Fields[tt.wantField]; !ok {
				t.Fatalf("champ %q attendu en erreur, obtenu %v", tt.wantField, verr.Fields)
			}
		})
	}
}

func TestDecideUsesLastDigit(t *testing.T) {
	tests := []struct {
		phone string
		want  Status
	}{
		{"0102030400", StatusFailed},
		{"0100000000", StatusFailed},
		{"0102030401", StatusSuccess},
		{"0102030409", StatusSuccess},
	}
	for _, tt := range tests {
		got := Decide(tt.phone)
		if got.Status != tt.want {
			t.Errorf("Decide(%s) = %s, attendu %s", tt.phone, got.Status, tt.want)
		}
		if got.Status == StatusFailed && got.FailureReason == "" {
			t.Errorf("Decide(%s) : raison d'échec manquante", tt.phone)
		}
	}
}

func TestRandomDelayStaysWithinBounds(t *testing.T) {
	minDelay, maxDelay := 2*time.Second, 5*time.Second
	delay := RandomDelay(minDelay, maxDelay)
	for range 1000 {
		if d := delay(); d < minDelay || d > maxDelay {
			t.Fatalf("délai %s hors de [%s, %s]", d, minDelay, maxDelay)
		}
	}
}

func TestRandomDelayWithEqualBounds(t *testing.T) {
	if d := RandomDelay(time.Second, time.Second)(); d != time.Second {
		t.Fatalf("délai = %s, attendu 1s", d)
	}
}

func TestFingerprintDependsOnContent(t *testing.T) {
	a, b := validRequest(), validRequest()
	if a.fingerprint() != b.fingerprint() {
		t.Fatal("deux requêtes identiques doivent avoir la même empreinte")
	}
	b.Amount++
	if a.fingerprint() == b.fingerprint() {
		t.Fatal("deux requêtes différentes doivent avoir des empreintes différentes")
	}
}
