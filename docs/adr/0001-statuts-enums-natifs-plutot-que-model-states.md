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

Le paquet interdit le trait d'union dans le nom sérialisé en base. Or **toutes**
les valeurs existantes en contiennent : `en-attente`, `a-remplacer`,
`non-demarre`, `en-cours`, `en-retard`, `a-signer`.

L'adopter imposait donc, sur des données financières en production : une colonne
supplémentaire par table, un backfill traduisant `-` en `_`, une double écriture
le temps de la bascule, et une méthode de compatibilité pour que les DTO
continuent d'exposer les valeurs historiques au frontend. Le tout pour un
résultat fonctionnellement identique à ce que donnent des enums natifs.

## Conséquences

- Aucune migration, aucun backfill, aucune double écriture. Les valeurs en base
  et les réponses JSON sont strictement inchangées.
- Les types TypeScript générés passent de `string` à des unions de littéraux —
  le frontend gagne l'exhaustivité au compilateur.
- Ce que l'on perd : les états ne portent pas de comportement (pas de classe par
  état, pas de hooks de transition). Le jour où une transition devra déclencher
  des effets — envoyer un courriel, écrire une écriture comptable —, il faudra
  soit des événements Laravel, soit revenir à `model-states`. Le paquet reste
  installé pour cette raison.
- `Client.dossier_etape` n'est pas couvert : c'est un entier piloté à la main,
  et `Demande` n'a toujours aucune colonne de statut. Le vrai moteur d'état du
  parcours reste hors machine à états.
