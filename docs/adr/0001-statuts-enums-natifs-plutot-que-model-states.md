# 0001 — Statuts en enums natifs plutôt que `spatie/laravel-model-states`

**Date** : 12 août 2026
**Statut** : acceptée

## Contexte

Les statuts métier (pièces justificatives, documents CPI, orientations
bancaires, chantiers) étaient des chaînes brutes, dispersées en une centaine de
littéraux. `Rule::in` validait les valeurs mais jamais les transitions : un
chantier livré pouvait redevenir « non démarré », un refus bancaire repasser en
accord, un brouillon jamais publié être marqué signé.

`spatie/laravel-model-states` avait été retenu lors du cadrage.

## Décision

Utiliser des **enums PHP natifs backés par des chaînes**, une interface commune
`App\Enums\Statut`, et un point de passage unique `App\Support\TransitionStatut`.

## Pourquoi pas `spatie/laravel-model-states`

**Deux raisons, dont une rédhibitoire découverte après coup.**

D'abord, le paquet interdit le trait d'union dans le nom sérialisé en base. Or
**toutes** les valeurs existantes en contiennent : `en-attente`, `a-remplacer`,
`non-demarre`, `en-cours`, `en-retard`, `a-signer`.

Ensuite — et c'est ce qui a tranché définitivement — **`spatie/laravel-model-states`
2.14 exige PHP ^8.4**, alors que ce projet déclare `"php": "^8.3"` et que
l'image de production comme l'intégration continue tournent en **8.3**. Le
paquet s'était installé sans broncher sur un poste de développement en PHP 8.5,
et `composer install` échouait en CI et aurait échoué au build de l'image
Docker. Le paquet a été retiré.

L'adopter imposait donc, sur des données financières en production : une colonne
supplémentaire par table, un backfill traduisant `-` en `_`, une double écriture
le temps de la bascule, et une méthode de compatibilité pour que les DTO
continuent d'exposer les valeurs historiques au frontend. Le tout pour un
résultat fonctionnellement identique à ce que donnent des enums natifs.

## Garde-fou ajouté

`composer.json` fixe désormais `config.platform.php` à la version réellement
déployée. Sans cela, Composer résout contre le PHP du poste de développement :
c'est exactement ce qui a laissé entrer un paquet incompatible avec la cible.

## Conséquences

- Aucune migration, aucun backfill, aucune double écriture. Les valeurs en base
  et les réponses JSON sont strictement inchangées.
- Les types TypeScript générés passent de `string` à des unions de littéraux —
  le frontend gagne l'exhaustivité au compilateur.
- Ce que l'on perd : les états ne portent pas de comportement (pas de classe par
  état, pas de hooks de transition). Le jour où une transition devra déclencher
  des effets — envoyer un courriel, écrire une écriture comptable —, il faudra
  soit des événements Laravel, soit `model-states` — ce qui supposera d'abord de
  passer le projet en PHP 8.4.
- `Client.dossier_etape` n'est pas couvert : c'est un entier piloté à la main,
  et `Demande` n'a toujours aucune colonne de statut. Le vrai moteur d'état du
  parcours reste hors machine à états.
