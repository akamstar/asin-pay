// Commande server : démarre le simulateur de paiement.
//
// `server healthcheck` interroge /health et sert de sonde Docker (l'image finale
// ne contient ni shell ni curl).
package main

import (
	"context"
	"crypto/ed25519"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/gin-gonic/gin"

	"github.com/paiement-asin/payement/internal/config"
	"github.com/paiement-asin/payement/internal/httpapi"
	"github.com/paiement-asin/payement/internal/payment"
	"github.com/paiement-asin/payement/internal/signature"
	"github.com/paiement-asin/payement/internal/webhook"
)

const shutdownTimeout = 10 * time.Second

func main() {
	if len(os.Args) > 1 && os.Args[1] == "healthcheck" {
		os.Exit(healthcheck())
	}

	logger := slog.New(slog.NewJSONHandler(os.Stdout, nil))
	if err := run(logger); err != nil {
		logger.Error("arrêt du service sur erreur", "error", err)
		os.Exit(1)
	}
}

func run(logger *slog.Logger) error {
	cfg, err := config.Load(os.Getenv)
	if err != nil {
		return fmt.Errorf("configuration invalide : %w", err)
	}

	gin.SetMode(gin.ReleaseMode)
	if mode := os.Getenv("GIN_MODE"); mode != "" {
		gin.SetMode(mode)
	}

	signer := signature.NewSigner(cfg.SigningKey, cfg.SigningKeyID)
	notifier := webhook.NewNotifier(webhook.Config{
		URL:         cfg.WebhookURL,
		Timeout:     cfg.WebhookTimeout,
		MaxAttempts: cfg.WebhookMaxAttempts,
		Backoff:     cfg.WebhookBackoff,
	}, signer, logger)
	service := payment.NewService(payment.NewMemoryStore(), notifier,
		payment.RandomDelay(cfg.MinDelay, cfg.MaxDelay), logger)

	srv := &http.Server{
		Addr: ":" + cfg.Port,
		Handler: httpapi.NewRouter(httpapi.Deps{
			Service: service,
			Signer:  signer,
			APIKey:  cfg.APIKey,
			Logger:  logger,
		}),
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       10 * time.Second,
		WriteTimeout:      10 * time.Second,
		IdleTimeout:       60 * time.Second,
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	serveErr := make(chan error, 1)
	go func() {
		logger.Info("service de paiement démarré", "port", cfg.Port,
			"min_delay", cfg.MinDelay.String(), "max_delay", cfg.MaxDelay.String(),
			"webhook_url", cfg.WebhookURL, "signing_key_id", cfg.SigningKeyID,
			"signing_public_key", signature.EncodePublicKey(cfg.SigningKey.Public().(ed25519.PublicKey)))
		if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			serveErr <- err
		}
		close(serveErr)
	}()

	select {
	case err := <-serveErr:
		return err
	case <-ctx.Done():
	}

	logger.Info("arrêt en cours")
	shutdownCtx, cancel := context.WithTimeout(context.Background(), shutdownTimeout)
	defer cancel()

	httpErr := srv.Shutdown(shutdownCtx)
	svcErr := service.Shutdown(shutdownCtx)
	if err := errors.Join(httpErr, svcErr); err != nil {
		return fmt.Errorf("arrêt incomplet : %w", err)
	}
	logger.Info("service arrêté proprement")
	return nil
}

func healthcheck() int {
	port := os.Getenv("PORT")
	if port == "" {
		port = "8080"
	}
	client := &http.Client{Timeout: 2 * time.Second}
	resp, err := client.Get("http://127.0.0.1:" + port + "/health")
	if err != nil {
		return 1
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return 1
	}
	return 0
}
