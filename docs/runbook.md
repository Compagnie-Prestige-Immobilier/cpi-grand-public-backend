# Runbook — API CPI Grand Public

## Déployer

L'image contient nginx + php-fpm + supervisor. Le conteneur `app` de
`docker-compose.yml` est le seul à migrer (`RUN_MIGRATIONS=true`).

```
docker compose up -d --build
```

Au démarrage, l'entrypoint : attend Postgres, applique les migrations, sème
**uniquement** les rôles et permissions, puis met en cache configuration, routes
et vues (`php artisan optimize`).

### Premier déploiement

Aucun compte n'existe. En créer un :

```
docker compose exec app php artisan cpi:create-admin dsi@cpi.sn
```

Le mot de passe s'affiche **une seule fois**.

### Variables qui doivent être justes

| Variable | Piège |
|---|---|
| `FRONTEND_URL` | Seule origine autorisée par CORS. Une valeur erronée fait rejeter **toutes** les requêtes du frontend. Doit valoir exactement le domaine servi par le dépôt frontend. |
| `GOOGLE_REDIRECT_URI` | Doit pointer vers le même domaine, sinon le retour OAuth tombe dans le vide. |
| `APP_DEBUG` | `false`. À `true`, les traces d'exception partent au client. |
| `TRUSTED_PROXIES` | `*` derrière le proxy TLS de l'hôte. Sans lui, tous les limiteurs de débit partagent le même seau et l'application se croit en HTTP. |
| `RUN_SEEDERS` | `false`. À `true` en production, le seeder recrée les comptes de démonstration. |

## Avant une migration sur des données réelles

```
php artisan cpi:audit-doublons
```

Lecture seule. Recense les dossiers portant plusieurs demandes, décaissements ou
chantiers. **La migration d'unicité refuse de s'exécuter tant qu'il en reste** :
choisir laquelle conserver est un arbitrage métier, pas une décision de code.

Puis, sur une copie de la base de production :

```
php artisan migrate
# vérifier les comptages avant/après
php artisan migrate:rollback
# vérifier que l'état initial est exactement restauré
```

## Vérifier la santé

- `GET /up` —healthcheck Laravel, utilisé par Docker et le compose.
- `GET /docs/api` — documentation OpenAPI générée par Scramble. **Non protégée
  par défaut** : décider si elle doit rester exposée en production.

## Contrôles avant chaque livraison

```
composer check     # pint + phpstan + tests
```

## Suppressions

Les dossiers, demandes, pièces, documents CPI, décaissements et chantiers sont
en **suppression douce**. Un dossier supprimé par erreur se restaure :

```php
App\Models\Client::withTrashed()->find($id)->restore();
```

La restauration ramène la demande, les pièces, le décaissement et le chantier —
sinon le dossier reviendrait en coquille vide.

Les données de démonstration, elles, sont purgées définitivement
(`DELETE /staff/demo/clear`).

## Validation des comptes et attribution

`GET /staff/comptes/en-attente` liste les comptes vérifiés en attente d'une
décision (`super-admin` uniquement, permission `validate-accounts`). Valider
déclenche l'attribution automatique à l'agent-cpi le moins chargé
(`App\Support\AttributionConseiller`) — cherchez `conseiller-attribue` dans le
journal pour voir qui a reçu quoi, `conseiller-non-attribue` pour les dossiers
restés sans conseiller.

**Aucun agent-cpi n'existe encore** (déploiement neuf) : la validation réussit
quand même, le dossier reste sans conseiller. Créez le premier agent
(`POST /staff/staff/create`), puis attribuez à la main via
`PUT /staff/clients/{client}` (`conseiller_id` **et** `conseiller` — les deux
colonnes, voir [glossaire.md](glossaire.md)) ; les validations suivantes
s'équilibreront normalement.

**Un agent est supprimé** (`DELETE /staff/staff/{user}`) : `conseiller_id` est
`nullOnDelete`, tout son portefeuille repasse silencieusement à « non attribué »
— aucune notification, aucune entrée de journal dédiée. Avant de supprimer un
compte agent, vérifiez s'il porte des dossiers actifs et réattribuez-les.

## Journal d'activité

Tout passe par `spatie/laravel-activitylog`, consultable par le personnel via
`GET /staff/historique`. Sont journalisés notamment : correction d'une demande
verrouillée (avec l'avant/après), validation et refus de pièce, publication et
signature de document, versements, prise en main d'un compte client, création et
suppression d'un compte du personnel.
