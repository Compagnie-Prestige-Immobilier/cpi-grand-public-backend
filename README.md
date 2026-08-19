# CPI Immobilier — API

API REST de la plateforme de financement immobilier CPI (Sénégal). Elle est la **source de vérité** de toutes les données métier : dossiers clients, demandes de financement, pièces justificatives, documents CPI, partenaires bancaires, décaissements, chantiers, notifications, journal d'activité et statistiques.

Le client de cette API est l'application React [`cpi-chues-frontend`](../frontend). Les deux dépôts évoluent ensemble : les types TypeScript du front sont **générés depuis les DTO de ce projet**.

---

## Stack

| Domaine | Choix |
| --- | --- |
| Framework | Laravel 13 · PHP 8.3 |
| Base de données | PostgreSQL (tests sur SQLite en mémoire) |
| Authentification | Laravel Sanctum (jetons) + Socialite (Google) |
| Rôles & permissions | spatie/laravel-permission |
| Journal d'activité | spatie/laravel-activitylog |
| DTO & types | spatie/laravel-data + spatie/laravel-typescript-transformer |
| Filtres | spatie/laravel-query-builder |
| Stockage de fichiers | Cloudflare R2 (S3), **bucket privé** |
| Documentation | dedoc/scramble → `/docs/api` |

---

## Démarrage

```bash
composer install
cp .env.example .env
php artisan key:generate

# Renseigner DB_* et R2_* dans .env, puis :
php artisan migrate:fresh --seed
php artisan serve
```

### Comptes créés par le seeder

| Rôle | Identifiant | Mot de passe |
| --- | --- | --- |
| Agent CPI | `agent@cpi.sn` | `agent1234` |
| Administrateur | `admin@cpi.sn` | `admin1234` |

Les comptes du personnel sont **créés par l'administrateur** (`POST /staff/staff/create`), jamais par inscription publique. Les clients, eux, s'inscrivent librement.

---

## Architecture

### Deux espaces, un seul garde

Toutes les routes passent par `auth:sanctum`. La séparation se fait ensuite en deux étages :

1. un middleware de route — `client` sur `/api/client/*`, `staff` sur `/api/staff/*` ;
2. une **policy** par ressource, qui décide au cas par cas.

Un client ne peut donc jamais atteindre `/staff/*` (403 « Accès personnel CPI uniquement. »), un membre du personnel jamais `/client/*`, et **un client ne voit jamais le dossier d'un autre** : la propriété se vérifie sur `$user->id === $client->user_id`.

### Provisionnement automatique

Tout dossier créé reçoit immédiatement, via `Client::booted()` :

- ses **trois pièces requises** (identité, revenus, relevés bancaires) en « en-attente » ;
- son **état de décaissement** ;
- son **chantier** (« non démarré », 0 %) et ses quatre tranches.

Le personnel peut ainsi traiter un dossier dès son ouverture, sans attendre que le client se connecte. Les méthodes `ensureRequiredDocs()`, `ensureDecaissement()` et `ensureChantier()` sont idempotentes et servent aussi de rattrapage.

### Fichiers : bucket privé, URLs signées

Tout passe par `app/Services/StorageService.php`. Les pièces déposées sont des documents d'identité, des bulletins de salaire et des relevés bancaires : **le bucket est privé** et les fichiers ne sont servis que par des URLs signées de courte durée, régénérées à chaque lecture (`fileUrl` dans les DTO, jamais stockée). Une panne de stockage devient un 503 en français, sans fuite de chemin interne.

> ⚠️ Une URL signée ne protège que si le bucket refuse l'accès anonyme. Vérifiez que l'**URL publique de développement R2 est désactivée** : sans cela, la signature et son expiration ne servent à rien.

### Journal d'activité

Chaque mutation écrit son entrée via Spatie Activity Log (`causedBy`, `performedOn`, `withProperties`, description en français). Le front ne journalise plus rien lui-même : il lit `/staff/historique`.

---

## Développement

```bash
php artisan test                  # 345 tests
vendor/bin/phpstan analyse        # niveau 5
vendor/bin/pint                   # formatage (--test pour vérifier seulement)
php artisan route:list --path=api # routes /api
```

### Régénérer les types du frontend

```bash
php artisan typescript:transform
```

Écrit `../frontend/src/app/api/types/generated.d.ts`. **Ce fichier ne se modifie jamais à la main** : il décrit la forme réelle des réponses, et le front compile contre lui en mode strict. À relancer après toute modification d'un DTO.

### Jeu de démonstration

`POST /staff/demo/seed` (administrateur uniquement) crée 30 dossiers répartis sur tout le parcours, préfixés `demo-`, avec des comptes utilisables (mot de passe `demo1234`). `DELETE /staff/demo/clear` les supprime — et **uniquement** eux : les dossiers réels ne sont pas touchés, ce qu'un test vérifie explicitement. Un second chargement est refusé (409) tant que le jeu existe.

---

## Intégration continue

`.github/workflows/ci.yml` — quatre contrôles bloquants sur `main` :

| Contrôle | Détail |
| --- | --- |
| Tests | PHPUnit sur SQLite en mémoire, disque R2 simulé — **aucun secret requis** |
| Analyse statique | PHPStan (larastan) niveau 5 |
| Migrations | `migrate:fresh --seed` sur un vrai PostgreSQL 16 — attrape ce que SQLite ne voit pas (colonnes uuid, contraintes) |
| Style | Laravel Pint |

---

## Documentation de l'API

Une fois le serveur lancé : **`/docs/api`** (Scramble, généré depuis les routes et les DTO).
