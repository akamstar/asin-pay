# backend : demande d'actes en ligne (Laravel 13)

L'usager choisit un acte et une quantité, voit le total en direct, puis enregistre sa demande.
Il obtient une référence et le montant à payer :

```
montant = prix unitaire × quantité + frais de dossier
```

Le prix et les frais sont **figés dans la demande** au moment de sa création. Un changement de tarif
ultérieur ne modifie donc pas une demande existante.

## Démarrage

Depuis la racine du dépôt :

```bash
cp .env.example .env
docker compose up -d --build
```

| URL | Rôle |
|---|---|
| http://localhost:8000 | Formulaire de demande |
| http://localhost:8000/demandes/{référence} | Récapitulatif et montant à payer |
| http://localhost:8000/up | Sonde de santé |

Au démarrage, le conteneur `backend` applique les migrations et charge le catalogue des actes
(le seeder peut être relancé sans risque).

## Tests

Les tests tournent sur **PostgreSQL** (base `backend_test`), le même moteur qu'en production :
les contraintes `CHECK` et d'unicité sont donc réellement vérifiées.

```bash
docker compose --profile test run --rm backend-test
```

En local, avec PHP 8.3+ et `pdo_pgsql` (PostgreSQL de Docker exposé sur le port 5433) :

```bash
cd backend && composer install && php artisan test
```

## API (`/api/v1`)

| Méthode | Route | Rôle | Codes |
|---|---|---|---|
| GET | `/services` | Actes disponibles, frais et quantité maximale | 200 |
| POST | `/service-requests` | Enregistrer une demande | 201, 422, 429 |
| GET | `/service-requests/{référence}` | Consulter une demande | 200, 404, 429 |

```bash
curl -X POST http://localhost:8000/api/v1/service-requests \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"service_code":"ACTE_NAISSANCE","quantity":2}'
```

```json
{
  "data": {
    "code": "DEM-SXM3WV1N1G",
    "service": { "code": "ACTE_NAISSANCE", "title": "Acte de naissance" },
    "unit_price": 1000, "quantity": 2, "subtotal": 2000, "fees": 100, "amount": 2100,
    "currency": "XOF", "status": "PENDING_PAYMENT", "status_label": "En attente de paiement",
    "links": { "self": "…/api/v1/service-requests/DEM-SXM3WV1N1G", "page": "…/demandes/DEM-SXM3WV1N1G" }
  }
}
```

Le serveur recalcule toujours le montant : un `amount` envoyé par le client est ignoré.

## Catalogue

| Code | Acte | Prix |
|---|---|---|
| `ACTE_NAISSANCE` | Acte de naissance | 1 000 F |
| `CASIER_JUDICIAIRE` | Casier judiciaire | 1 500 F |
| `CERTIFICAT_RESIDENCE` | Certificat de résidence | 500 F |

## Configuration

| Variable | Défaut | Rôle |
|---|---|---|
| `FRAIS_DOSSIER` | `100` | Frais fixes ajoutés à chaque demande (FCFA) |
| `DEMANDE_QUANTITE_MAX` | `10` | Quantité maximale par demande |

## Choix de conception

- **Référence publique non devinable** : il n'y a pas de compte usager, donc la référence est le seul
  moyen d'accéder à une demande. Elle est aléatoire (`DEM-` + 10 caractères Crockford base32, environ 50 bits),
  jamais séquentielle, et les routes de consultation sont limitées en débit. L'identifiant interne n'est jamais exposé.
- **Montants en entiers** (FCFA, sans décimales). Une contrainte `CHECK` en base garantit
  `amount = unit_price × quantity + fees`.
- **Validation front + back** : le formulaire (Alpine.js) valide pour un retour immédiat, et le
  FormRequest reste la référence.
- **Organisation** : `PricingCalculator` (calcul), `CreateServiceRequest` (orchestration),
  `ReferenceGenerator` (référence), contrôleurs minces et API Resources.

## Structure

```
app/Actions/CreateServiceRequest.php      création d'une demande (prix figés, référence unique)
app/Services/PricingCalculator.php        calcul du montant
app/Support/ReferenceGenerator.php        références publiques
app/Support/Money.php                     formatage « 5 000 F »
app/Enums/ServiceRequestStatus.php        statuts de la demande
app/Http/Requests, Resources, Controllers API JSON et pages
resources/views/service-requests          pages Blade
resources/js/service-request-form.js      composant Alpine du formulaire
config/service_requests.php               frais et quantité maximale
```
