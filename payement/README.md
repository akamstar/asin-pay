# payement : simulateur de paiement mobile (Go + Gin)

Ce service simule un agrégateur de paiement mobile. Il accepte une requête de débit tout de suite
(`202 PENDING`), la traite, puis, après un délai aléatoire, envoie le résultat au backend
sous forme de **message signé Ed25519** (webhook).

```
Backend                                   payement
   │  POST /api/v1/payments  ──────────────▶ │  validation, enregistrement
   │  ◀────────────── 202 PENDING (signé)    │
   │                                         │  délai aléatoire [MIN_DELAY, MAX_DELAY]
   │                                         │  décision (dernier chiffre du téléphone)
   │  ◀──── POST WEBHOOK_URL (signé) ─────── │  payment.succeeded | payment.failed
   │  GET /api/v1/payments/{id} ───────────▶ │  réconciliation (réponse signée)
```

## Règles de simulation

| Téléphone | Résultat |
|---|---|
| dernier chiffre `0` (ex. `0102030400`) | `FAILED` (`failure_reason` : `TRANSACTION_REFUSEE`) |
| tout autre dernier chiffre (ex. `0102030405`) | `SUCCESS` |

Le délai de traitement est tiré au hasard entre `MIN_DELAY` et `MAX_DELAY`.

## Démarrage

Depuis la racine du dépôt :

```bash
cp .env.example .env          # clés de développement déjà fournies
docker compose up -d --build payement
curl http://localhost:8080/health
```

## API

Toutes les routes `/api/v1/*` exigent l'en-tête `X-Api-Key`.

### `POST /api/v1/payments` : requête de débit

L'en-tête `Idempotency-Key` est obligatoire.

```bash
curl -i -X POST http://localhost:8080/api/v1/payments \
  -H "X-Api-Key: dev-api-key-a-changer" \
  -H "Idempotency-Key: DEM-001-1" \
  -H "Content-Type: application/json" \
  -d '{"merchant_reference":"DEM-001","amount":7600,"currency":"XOF","phone":"0102030405"}'
```

| Code | Cas |
|---|---|
| `202` | Paiement créé (`PENDING`) |
| `200` | Rejeu : même clé et même contenu, le paiement existant est renvoyé |
| `400` | `Idempotency-Key` absente, JSON mal formé |
| `401` | Clé d'API absente ou invalide |
| `409` | Même clé d'idempotence avec un contenu différent |
| `422` | Validation : `amount` entier > 0, `currency` = `XOF`, `phone` = `^01\d{8}$`, `merchant_reference` obligatoire (64 caractères max.) |

### `GET /api/v1/payments/{id}` : réconciliation

Renvoie l'état courant (`PENDING`, `SUCCESS` ou `FAILED`), avec les en-têtes de signature.
Le backend s'en sert si un webhook n'est pas arrivé.

### Format d'erreur

```json
{ "error": { "code": "VALIDATION_FAILED", "message": "requête de débit invalide",
             "details": { "phone": "doit contenir 10 chiffres et commencer par 01" } } }
```

## Webhook et signature

Le service envoie `POST WEBHOOK_URL` avec le corps suivant :

```json
{ "id": "evt_…", "event": "payment.succeeded", "created_at": "…", "data": { "id": "pay_…", "status": "SUCCESS", … } }
```

- `event` vaut `payment.succeeded` ou `payment.failed`. `id` permet au destinataire d'ignorer les doublons.
- Si la réponse n'est pas 2xx, l'envoi est retenté jusqu'à `WEBHOOK_MAX_ATTEMPTS` fois, avec un délai qui double à chaque tentative.
- L'URL vient de la configuration et non de la requête, ce qui évite le SSRF.

Les webhooks et les réponses `POST`/`GET` portent les mêmes en-têtes de signature :

| En-tête | Contenu |
|---|---|
| `X-Signature-Timestamp` | Epoch en secondes |
| `X-Signature` | `base64(Ed25519(clé_privée, timestamp + "." + corps_brut))` |
| `X-Signature-Key-Id` | Identifiant de la clé (prévu pour la rotation) |

Pour vérifier un message, le destinataire :
1. reconstruit `timestamp + "." + corps_brut` avec les octets reçus, sans re-sérialiser le JSON ;
2. vérifie la signature avec la clé publique ;
3. rejette le message si le timestamp est trop ancien (anti-rejeu, par exemple 5 minutes).

Ed25519 est une signature asymétrique : le backend ne détient que la clé publique, il ne peut donc pas
fabriquer de faux messages.

Pour générer une nouvelle paire de clés :

```bash
go run ./cmd/keygen
```

## Configuration

| Variable | Défaut | Rôle |
|---|---|---|
| `PORT` | `8080` | Port HTTP |
| `API_KEY` | obligatoire | Valeur attendue dans `X-Api-Key` |
| `WEBHOOK_URL` | obligatoire | URL de notification du backend |
| `SIGNING_PRIVATE_KEY` | obligatoire | Graine Ed25519 (32 octets en base64) |
| `SIGNING_KEY_ID` | `payement-dev` | Valeur de `X-Signature-Key-Id` |
| `MIN_DELAY` / `MAX_DELAY` | `3s` / `10s` | Fenêtre du délai de traitement |
| `WEBHOOK_TIMEOUT` | `5s` | Timeout d'un appel webhook |
| `WEBHOOK_MAX_ATTEMPTS` | `3` | Nombre maximal de tentatives |
| `WEBHOOK_BACKOFF` | `1s` | Délai avant la 2e tentative (doublé ensuite) |

Avec Docker Compose, ces variables sont lues dans le `.env` racine, avec le préfixe `PAYMENT_`.

## Tests

```bash
go test ./...                              # en local
docker build --target test ./payement      # avec le détecteur de concurrence (-race), depuis la racine
```

## Structure

```
cmd/server     point d'entrée, arrêt propre, sous-commande healthcheck
cmd/keygen     génération d'une paire de clés Ed25519
internal/config      lecture et validation de l'environnement
internal/payment     domaine : validation, règle de décision, store mémoire, traitement asynchrone
internal/signature   signature et vérification Ed25519
internal/webhook     envoi signé avec nouvelles tentatives
internal/httpapi     routeur Gin, handlers, middlewares (clé d'API, request id, logs, recovery)
```

## Limites voulues

- Stockage en mémoire : un redémarrage efface les paiements.
- Les traitements en cours au moment de l'arrêt sont annulés et restent en `PENDING`.
  Le backend les réconcilie avec `GET /api/v1/payments/{id}`.
