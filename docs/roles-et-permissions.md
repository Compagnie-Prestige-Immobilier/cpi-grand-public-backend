# Rôles et permissions

Chaque compte porte **exactement un** rôle Spatie. `/auth/me` renvoie
`getRoleNames()->first()` et la liste complète des permissions : c'est la source
unique du contrôle d'accès côté frontend.

Source de vérité : `database/seeders/RoleAndPermissionSeeder.php`. Ce document
en est le reflet lisible — le modifier sans modifier le seeder ne change rien.

## Matrice

| Permission | client | agent-cpi | super-admin |
|---|:---:|:---:|:---:|
| `view-clients` | | ✔ | ✔ |
| `create-client` | | ✔ | ✔ |
| `edit-client` | | ✔ | ✔ |
| `delete-client` | | | ✔ |
| `view-documents` | ✔ | ✔ | ✔ |
| `upload-documents` | ✔ | | ✔ |
| `validate-documents` | | ✔ | ✔ |
| `manage-documents` | | ✔ | ✔ |
| `sign-documents` | ✔ | | ✔ |
| `view-cpi-docs` | ✔ | ✔ | ✔ |
| `create-cpi-docs` | | ✔ | ✔ |
| `publish-cpi-docs` | | ✔ | ✔ |
| `archive-cpi-docs` | | ✔ | ✔ |
| `sign-cpi-docs` | ✔ | ✔ | ✔ |
| `view-banks` | ✔ | ✔ | ✔ |
| `create-bank` / `edit-bank` / `delete-bank` | | | ✔ |
| `assign-bank` | | ✔ | ✔ |
| `view-decaissements` | | ✔ | ✔ |
| `manage-decaissements` | | ✔ | ✔ |
| **`validate-decaissements`** | | | **✔** |
| `view-chantier` | ✔ | ✔ | ✔ |
| `manage-chantier` | | ✔ | ✔ |
| `send-notifications` | | ✔ | ✔ |
| `view-notifications` | ✔ | ✔ | ✔ |
| `manage-staff` | | | ✔ |
| `view-stats` | | | ✔ |
| `validate-accounts` | | | ✔ |
| `manage-demo-data` | | | ✔ |
| `view-own-profile` / `edit-own-profile` | ✔ | ✔ | ✔ |

## Règles qui ne sont PAS des permissions

Une permission dit ce qu'un rôle peut faire ; elle ne dit pas sur quoi. Ces
règles-là vivent dans les policies :

- **Un client ne voit que son propre dossier.** Aucune route client ne porte
  l'identifiant d'un autre dossier ; le scoping est structurel.
- **Un agent ne voit que son portefeuille** — les dossiers dont il est le
  conseiller, plus ceux sans conseiller attribué (sinon les nouvelles demandes
  resteraient invisibles jusqu'à leur attribution). `App\Support\PortefeuilleConseiller`.
- **Quatre yeux sur les versements.** `validate-decaissements` n'est pas
  accordée à `agent-cpi` : l'agent prépare, un administrateur déclenche. Et
  celui qui a préparé ne peut pas valider lui-même, quel que soit son rôle
  (`decaissements.prepared_by`).
- **Verrouillage du dossier.** À partir de `dossier_etape >= 3`, le client perd
  la main sur sa demande, indépendamment de ses permissions.

## Créer le premier compte en production

Le seeder ne crée AUCUN compte en production. Le compte d'amorçage se crée hors
du code :

```
php artisan cpi:create-admin dsi@cpi.sn
```

Le mot de passe est tiré au hasard et affiché une seule fois.
