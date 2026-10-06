// Package config lit et valide la configuration depuis les variables d'environnement.
package config

import (
	"crypto/ed25519"
	"errors"
	"fmt"
	"strconv"
	"time"

	"github.com/paiement-asin/payement/internal/signature"
)

// Config contient la configuration du service.
type Config struct {
	Port               string
	APIKey             string
	WebhookURL         string
	WebhookTimeout     time.Duration
	WebhookMaxAttempts int
	WebhookBackoff     time.Duration
	SigningKey         ed25519.PrivateKey
	SigningKeyID       string
	MinDelay           time.Duration
	MaxDelay           time.Duration
}

// Load construit la configuration à partir de getenv (os.Getenv en production).
// Toutes les erreurs sont renvoyées ensemble pour faciliter le diagnostic.
func Load(getenv func(string) string) (Config, error) {
	l := loader{getenv: getenv}
	cfg := Config{
		Port:               l.str("PORT", "8080"),
		APIKey:             l.required("API_KEY"),
		WebhookURL:         l.required("WEBHOOK_URL"),
		WebhookTimeout:     l.duration("WEBHOOK_TIMEOUT", 5*time.Second),
		WebhookMaxAttempts: l.int("WEBHOOK_MAX_ATTEMPTS", 3),
		WebhookBackoff:     l.duration("WEBHOOK_BACKOFF", time.Second),
		SigningKeyID:       l.str("SIGNING_KEY_ID", "payement-dev"),
		MinDelay:           l.duration("MIN_DELAY", 3*time.Second),
		MaxDelay:           l.duration("MAX_DELAY", 10*time.Second),
	}

	if raw := l.required("SIGNING_PRIVATE_KEY"); raw != "" {
		key, err := signature.ParsePrivateKey(raw)
		if err != nil {
			l.fail("SIGNING_PRIVATE_KEY", err.Error())
		}
		cfg.SigningKey = key
	}

	if cfg.MinDelay < 0 {
		l.fail("MIN_DELAY", "doit être positif")
	}
	if cfg.MaxDelay < cfg.MinDelay {
		l.fail("MAX_DELAY", "doit être supérieur ou égal à MIN_DELAY")
	}
	if cfg.WebhookMaxAttempts < 1 {
		l.fail("WEBHOOK_MAX_ATTEMPTS", "doit être au moins 1")
	}

	return cfg, errors.Join(l.errs...)
}

type loader struct {
	getenv func(string) string
	errs   []error
}

func (l *loader) fail(name, msg string) {
	l.errs = append(l.errs, fmt.Errorf("%s : %s", name, msg))
}

func (l *loader) str(name, def string) string {
	if v := l.getenv(name); v != "" {
		return v
	}
	return def
}

func (l *loader) required(name string) string {
	v := l.getenv(name)
	if v == "" {
		l.fail(name, "variable obligatoire")
	}
	return v
}

func (l *loader) duration(name string, def time.Duration) time.Duration {
	v := l.getenv(name)
	if v == "" {
		return def
	}
	d, err := time.ParseDuration(v)
	if err != nil {
		l.fail(name, "durée invalide (exemples : 500ms, 3s, 1m)")
		return def
	}
	return d
}

func (l *loader) int(name string, def int) int {
	v := l.getenv(name)
	if v == "" {
		return def
	}
	n, err := strconv.Atoi(v)
	if err != nil {
		l.fail(name, "entier attendu")
		return def
	}
	return n
}
