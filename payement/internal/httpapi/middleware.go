package httpapi

import (
	"crypto/rand"
	"crypto/subtle"
	"encoding/hex"
	"log/slog"
	"net/http"
	"regexp"
	"time"

	"github.com/gin-gonic/gin"
)

const (
	headerAPIKey    = "X-Api-Key"
	headerRequestID = "X-Request-Id"
	ctxRequestID    = "request_id"
)

var validRequestID = regexp.MustCompile(`^[A-Za-z0-9._-]{1,64}$`)

// requestID réutilise l'identifiant fourni par l'appelant (s'il est sain) ou en génère un.
func requestID() gin.HandlerFunc {
	return func(c *gin.Context) {
		id := c.GetHeader(headerRequestID)
		if !validRequestID.MatchString(id) {
			b := make([]byte, 8)
			_, _ = rand.Read(b)
			id = hex.EncodeToString(b)
		}
		c.Set(ctxRequestID, id)
		c.Header(headerRequestID, id)
		c.Next()
	}
}

// accessLog journalise chaque requête en JSON structuré.
func accessLog(logger *slog.Logger) gin.HandlerFunc {
	return func(c *gin.Context) {
		start := time.Now()
		c.Next()

		attrs := []any{
			"request_id", c.GetString(ctxRequestID),
			"method", c.Request.Method,
			"path", c.FullPath(),
			"status", c.Writer.Status(),
			"duration_ms", time.Since(start).Milliseconds(),
		}
		if len(c.Errors) > 0 {
			attrs = append(attrs, "error", c.Errors.String())
		}
		level := slog.LevelInfo
		if c.Writer.Status() >= http.StatusInternalServerError {
			level = slog.LevelError
		}
		logger.Log(c.Request.Context(), level, "requête HTTP", attrs...)
	}
}

// recovery transforme une panique en réponse 500 au format d'erreur de l'API.
func recovery(logger *slog.Logger) gin.HandlerFunc {
	return gin.CustomRecoveryWithWriter(nil, func(c *gin.Context, err any) {
		logger.Error("panique interceptée", "request_id", c.GetString(ctxRequestID), "panic", err)
		writeError(c, http.StatusInternalServerError, "INTERNAL_ERROR", "erreur interne", nil)
	})
}

// apiKeyAuth exige l'en-tête X-Api-Key, comparé en temps constant.
func apiKeyAuth(expected string) gin.HandlerFunc {
	want := []byte(expected)
	return func(c *gin.Context) {
		got := []byte(c.GetHeader(headerAPIKey))
		if subtle.ConstantTimeCompare(got, want) != 1 {
			writeError(c, http.StatusUnauthorized, "UNAUTHORIZED", "clé d'API absente ou invalide", nil)
			return
		}
		c.Next()
	}
}
