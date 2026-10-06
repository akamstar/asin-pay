// Package httpapi expose le simulateur de paiement en HTTP (Gin).
package httpapi

import (
	"log/slog"
	"net/http"

	"github.com/gin-gonic/gin"

	"github.com/paiement-asin/payement/internal/payment"
	"github.com/paiement-asin/payement/internal/signature"
)

// Deps regroupe les dépendances de l'API.
type Deps struct {
	Service *payment.Service
	Signer  *signature.Signer
	APIKey  string
	Logger  *slog.Logger
}

// NewRouter construit le routeur HTTP.
func NewRouter(d Deps) *gin.Engine {
	r := gin.New()
	r.HandleMethodNotAllowed = true
	r.Use(requestID(), accessLog(d.Logger), recovery(d.Logger))

	r.NoRoute(func(c *gin.Context) {
		writeError(c, http.StatusNotFound, "NOT_FOUND", "route inconnue", nil)
	})
	r.NoMethod(func(c *gin.Context) {
		writeError(c, http.StatusMethodNotAllowed, "METHOD_NOT_ALLOWED", "méthode non autorisée", nil)
	})

	r.GET("/health", func(c *gin.Context) {
		c.JSON(http.StatusOK, gin.H{"status": "ok"})
	})

	h := &paymentHandler{service: d.Service, signer: d.Signer}
	v1 := r.Group("/api/v1", apiKeyAuth(d.APIKey))
	v1.POST("/payments", h.create)
	v1.GET("/payments/:id", h.show)

	return r
}
