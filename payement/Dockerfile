# syntax=docker/dockerfile:1

# --- Étape de test (docker build --target test .) ---
FROM golang:1.26 AS test
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN go vet ./... && go test -race -count=1 ./...

# --- Étape de compilation ---
FROM golang:1.26-alpine AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/payement ./cmd/server

# --- Image finale : binaire statique, sans shell, utilisateur non-root ---
FROM gcr.io/distroless/static-debian12:nonroot
COPY --from=build /out/payement /payement
USER nonroot:nonroot
EXPOSE 8080
HEALTHCHECK --interval=10s --timeout=3s --start-period=5s --retries=3 CMD ["/payement", "healthcheck"]
ENTRYPOINT ["/payement"]
