# Demande d'actes en ligne avec paiement mobile simulé

- `backend/` : application Laravel 13 (demande d'actes, paiement, webhook signé), voir [backend/README.md](backend/README.md)
- `payement/` : simulateur de paiement Go/Gin (réponse différée signée Ed25519), voir [payement/README.md](payement/README.md)

## Prérequis

Docker et Docker Compose. Ports libres : 8000, 8080, 5433.

## Démarrage

```bash
cp .env.example .env
docker compose up -d --build
```

Ouvrir http://localhost:8000 : choisir un acte et une quantité, cliquer sur « Demander », puis payer.

## Numéros de test

| Numéro (10 chiffres, commence par 01) | Résultat après 3 à 10 s |
|---|---|
| finissant par 0, par exemple `0102030400` | paiement refusé, nouvel essai possible |
| tout autre, par exemple `0102030405` | paiement confirmé, demande payée |

## Tests

```bash
docker compose --profile test run --rm backend-test     # PHPUnit sur PostgreSQL
docker build --target test ./payement                   # Go, avec -race
```
