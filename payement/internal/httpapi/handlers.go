package httpapi

import (
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"strings"

	"github.com/gin-gonic/gin"

	"github.com/paiement-asin/payement/internal/payment"
	"github.com/paiement-asin/payement/internal/signature"
)

const (
	headerIdempotencyKey = "Idempotency-Key"
	maxIdempotencyKeyLen = 255
	maxRequestBodyBytes  = 64 << 10
)

type paymentHandler struct {
	service *payment.Service
	signer  *signature.Signer
}

// create accepte une requête de débit : 202 à la création, 200 pour un rejeu idempotent.
func (h *paymentHandler) create(c *gin.Context) {
	key := strings.TrimSpace(c.GetHeader(headerIdempotencyKey))
	if key == "" || len(key) > maxIdempotencyKeyLen {
		writeError(c, http.StatusBadRequest, "INVALID_IDEMPOTENCY_KEY",
			"l'en-tête Idempotency-Key est obligatoire (255 caractères maximum)", nil)
		return
	}

	var req payment.DebitRequest
	if !decodeJSON(c, &req) {
		return
	}

	p, created, err := h.service.Initiate(key, req)
	var verr *payment.ValidationError
	switch {
	case errors.As(err, &verr):
		writeError(c, http.StatusUnprocessableEntity, "VALIDATION_FAILED", verr.Error(), verr.Fields)
		return
	case errors.Is(err, payment.ErrIdempotencyConflict):
		writeError(c, http.StatusConflict, "IDEMPOTENCY_CONFLICT", err.Error(), nil)
		return
	case err != nil:
		_ = c.Error(err)
		writeError(c, http.StatusInternalServerError, "INTERNAL_ERROR", "erreur interne", nil)
		return
	}

	status := http.StatusOK
	if created {
		status = http.StatusAccepted
	}
	h.writeSigned(c, status, p)
}

// show renvoie l'état d'un paiement, signé, pour la réconciliation côté marchand.
func (h *paymentHandler) show(c *gin.Context) {
	p, err := h.service.Get(c.Param("id"))
	if errors.Is(err, payment.ErrNotFound) {
		writeError(c, http.StatusNotFound, "PAYMENT_NOT_FOUND", err.Error(), nil)
		return
	}
	if err != nil {
		_ = c.Error(err)
		writeError(c, http.StatusInternalServerError, "INTERNAL_ERROR", "erreur interne", nil)
		return
	}
	h.writeSigned(c, http.StatusOK, p)
}

// writeSigned écrit p en JSON et signe exactement les octets envoyés.
func (h *paymentHandler) writeSigned(c *gin.Context, status int, p payment.Payment) {
	body, err := json.Marshal(p)
	if err != nil {
		_ = c.Error(err)
		writeError(c, http.StatusInternalServerError, "INTERNAL_ERROR", "erreur interne", nil)
		return
	}
	h.signer.Sign(body).Apply(c.Writer.Header())
	c.Data(status, "application/json; charset=utf-8", body)
}

// decodeJSON lit le corps de la requête. En cas d'erreur, il écrit la réponse et renvoie false.
func decodeJSON(c *gin.Context, dst any) bool {
	c.Request.Body = http.MaxBytesReader(c.Writer, c.Request.Body, maxRequestBodyBytes)
	err := json.NewDecoder(c.Request.Body).Decode(dst)

	var typeErr *json.UnmarshalTypeError
	var sizeErr *http.MaxBytesError
	switch {
	case err == nil:
		return true
	case errors.As(err, &typeErr):
		writeError(c, http.StatusUnprocessableEntity, "VALIDATION_FAILED", "requête de débit invalide",
			map[string]string{typeErr.Field: "type invalide, " + typeErr.Type.String() + " attendu"})
	case errors.As(err, &sizeErr):
		writeError(c, http.StatusRequestEntityTooLarge, "PAYLOAD_TOO_LARGE", "corps de requête trop volumineux", nil)
	case errors.Is(err, io.EOF):
		writeError(c, http.StatusBadRequest, "INVALID_JSON", "corps de requête vide", nil)
	default:
		writeError(c, http.StatusBadRequest, "INVALID_JSON", "JSON mal formé", nil)
	}
	return false
}

type errorBody struct {
	Error errorPayload `json:"error"`
}

type errorPayload struct {
	Code    string            `json:"code"`
	Message string            `json:"message"`
	Details map[string]string `json:"details,omitempty"`
}

func writeError(c *gin.Context, status int, code, message string, details map[string]string) {
	c.AbortWithStatusJSON(status, errorBody{Error: errorPayload{Code: code, Message: message, Details: details}})
}
