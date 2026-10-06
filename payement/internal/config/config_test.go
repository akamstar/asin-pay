package config

import (
	"strings"
	"testing"
	"time"
)

// Graine Ed25519 de test (32 octets à zéro), à ne jamais utiliser ailleurs.
const testSeed = "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="

func env(vars map[string]string) func(string) string {
	return func(k string) string { return vars[k] }
}

func validEnv() map[string]string {
	return map[string]string{
		"API_KEY":             "secret",
		"WEBHOOK_URL":         "http://backend/webhook",
		"SIGNING_PRIVATE_KEY": testSeed,
	}
}

func TestLoadAppliesDefaults(t *testing.T) {
	cfg, err := Load(env(validEnv()))
	if err != nil {
		t.Fatalf("Load : %v", err)
	}
	if cfg.Port != "8080" || cfg.MinDelay != 3*time.Second || cfg.MaxDelay != 10*time.Second || cfg.WebhookMaxAttempts != 3 {
		t.Fatalf("valeurs par défaut inattendues : %+v", cfg)
	}
	if len(cfg.SigningKey) == 0 {
		t.Fatal("clé de signature non chargée")
	}
}

func TestLoadReadsOverrides(t *testing.T) {
	vars := validEnv()
	vars["PORT"] = "9000"
	vars["MIN_DELAY"] = "500ms"
	vars["MAX_DELAY"] = "2s"

	cfg, err := Load(env(vars))
	if err != nil {
		t.Fatalf("Load : %v", err)
	}
	if cfg.Port != "9000" || cfg.MinDelay != 500*time.Millisecond || cfg.MaxDelay != 2*time.Second {
		t.Fatalf("surcharges non prises en compte : %+v", cfg)
	}
}

func TestLoadReportsAllErrors(t *testing.T) {
	_, err := Load(env(map[string]string{"MIN_DELAY": "abc"}))
	if err == nil {
		t.Fatal("une erreur est attendue")
	}
	for _, want := range []string{"API_KEY", "WEBHOOK_URL", "SIGNING_PRIVATE_KEY", "MIN_DELAY"} {
		if !strings.Contains(err.Error(), want) {
			t.Errorf("l'erreur devrait mentionner %s : %v", want, err)
		}
	}
}

func TestLoadRejectsInvalidValues(t *testing.T) {
	tests := map[string]map[string]string{
		"MAX_DELAY":            {"MIN_DELAY": "5s", "MAX_DELAY": "1s"},
		"SIGNING_PRIVATE_KEY":  {"SIGNING_PRIVATE_KEY": "trop-court"},
		"WEBHOOK_MAX_ATTEMPTS": {"WEBHOOK_MAX_ATTEMPTS": "0"},
	}
	for field, overrides := range tests {
		t.Run(field, func(t *testing.T) {
			vars := validEnv()
			for k, v := range overrides {
				vars[k] = v
			}
			_, err := Load(env(vars))
			if err == nil || !strings.Contains(err.Error(), field) {
				t.Fatalf("erreur sur %s attendue, obtenu %v", field, err)
			}
		})
	}
}
