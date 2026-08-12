# CPI Grand Public — API

Backend Laravel 13 d'une plateforme de financement immobilier au Sénégal.
Frontend React dans le dépôt voisin `cpi-grand-public-frontend`.

## Avant de toucher au code

Lire `docs/glossaire.md`. Le vocabulaire métier est en français et n'est pas
devinable : `demande`, `chantier`, `tranche`, `décaissement`, `requis`,
`foncier`, `dossier_etape`. Ne pas traduire un terme métier en anglais dans une
nouvelle table ou un nouveau champ — le code mélange délibérément français
(métier) et anglais (technique).

`docs/roles-et-permissions.md` et `docs/statuts.md` décrivent le contrôle
d'accès et les machines à états. Les modifier sans modifier respectivement
`RoleAndPermissionSeeder` et `App\Enums` ne change rien : ce sont des reflets,
pas des sources.

## Vérification

```
composer check     # pint + phpstan + tests, dans cet ordre
```

À lancer avant chaque commit. `composer analyse` seul suffit rarement : les
tests attrapent des choses que l'analyse statique ne voit pas, et
réciproquement. `php artisan test` boote l'application entière — c'est ce qui
détecte une erreur dans `bootstrap/app.php` qu'aucun test ne cible.

## Pièges connus de ce projet

- **`config()` n'est pas disponible dans `bootstrap/app.php`.** Le conteneur n'y
  a pas encore lié la configuration. Et `env()` n'y répond plus après
  `php artisan optimize`, que l'entrypoint Docker exécute au démarrage. Tout ce
  qui a besoin de configuration va dans un service provider.
- **Le cache des permissions Spatie.** `RoleAndPermissionSeeder` le vide avant
  ET après écriture. Une permission ajoutée sans vider après reste invisible
  d'un `hasPermissionTo` déjà servi par un cache antérieur — le symptôme est un
  403 sur un compte qui détient pourtant la permission en base.
- **Le garde d'authentification mémorise l'utilisateur.** Dans un test qui
  enchaîne deux requêtes avec des jetons différents, la seconde est vue comme le
  premier utilisateur. `Tests\TestCase::withToken` purge les gardes ; ne pas
  contourner cette surcharge.
- **Les contraintes d'unicité comptent les lignes en corbeille.** Les
  suppressions sont douces : toute recréation d'une ligne `hasOne` doit passer
  par `withTrashed()` et restaurer, sinon elle échoue sur une violation
  d'unicité.
- **Les types TypeScript du frontend sont générés depuis les DTO** et écrits
  dans le dépôt voisin (`config/typescript.php`). Après tout changement de DTO :
  `php artisan typescript:transform`, puis committer **des deux côtés**.

## Ce qui n'est pas encore fait

- `Demande` n'a aucune colonne de statut ; le moteur d'état réel est
  `Client.dossier_etape`, un entier piloté à la main.
- `Client.statut` est un texte libre décoratif, lu par aucune logique métier.
  Ne rien y accrocher.
- `Decaissement.tranches` (JSON, sans montant par tranche) et `chantier_tranches`
  (table relationnelle) décrivent le même découpage sans être reliés.
- Aucune colonne de devise : XOF est une hypothèse implicite partout.
- Aucun dossier `lang/` malgré une application entièrement française.
