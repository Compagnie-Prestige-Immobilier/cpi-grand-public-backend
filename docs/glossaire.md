# Glossaire métier — CPI Grand Public

Vocabulaire du domaine, tel qu'il est réellement utilisé dans le code et par
l'équipe. Ce document existe parce que ces termes n'étaient écrits nulle part :
ils vivaient dans la tête des personnes et dans les messages de commit.

Convention : le code est en français pour le métier (`demande`, `chantier`,
`decaissement`) et en anglais pour la technique (`status`, `client_id`). Cette
cohabitation est assumée ; ne pas traduire un terme métier en anglais dans une
nouvelle table ou un nouveau champ.

## Acteurs

| Terme | Définition |
|---|---|
| **Client** | Un particulier qui monte un dossier de financement. Porte `Client` (le dossier) et `User` (le compte de connexion). Les deux sont distincts : un dossier peut exister avant que le client ne se connecte, créé par le personnel. |
| **Conseiller** | Le membre du personnel CPI responsable d'un dossier (`clients.conseiller_id`). Un agent ne voit que son portefeuille, plus les dossiers non encore attribués. |
| **agent-cpi** | Rôle du personnel opérationnel. Prépare les dossiers, valide les pièces, publie les documents, pilote les chantiers. Ne déclenche PAS les versements. |
| **super-admin** | Rôle d'administration. Voit tous les dossiers, gère les comptes du personnel, déclenche les versements, charge et purge les données de démonstration. |
| **Banque** | Établissement partenaire vers lequel un dossier est orienté. Rend un accord ou un refus. |
| **Validation de compte** | Feu vert donné par un administrateur à un compte nouvellement inscrit. Distincte de la **vérification d'adresse**, qui est automatique et ne prouve que l'existence de l'e-mail. Tant que le compte n'est pas validé, il n'accède à rien. Voir `StatutCompte` dans [statuts.md](statuts.md). |

## Cycle du dossier

| Terme | Définition |
|---|---|
| **Demande** | La demande de financement elle-même : montant, apport, durée, nature du projet, région. Une seule par dossier. |
| **dossier_etape** | Entier 0 à 5 porté par `Client`, piloté à la main par le personnel. C'est le véritable moteur d'état du parcours : 0 inscription, 1 demande, 2 pièces, 3 **analyse (verrouillage)**, 4 accord bancaire, 5 signature. |
| **Verrouillage** | À partir de `dossier_etape >= 3`, le client ne peut plus modifier sa demande : l'instruction a commencé. Seul le personnel peut corriger, via un endpoint dédié qui journalise l'avant/après et notifie le client. |
| **Requis (RequisDoc)** | Une des trois pièces justificatives que le client doit fournir : `identite`, `revenus`, `bancaires`. Créées automatiquement à l'ouverture du dossier, en `en-attente`. |
| **CpiDoc** | Un document produit par CPI à destination du client : convention, contrat, attestation. Peut exiger une signature électronique. |

## Financement et travaux

| Terme | Définition |
|---|---|
| **Foncier** | Le parcours d'acquisition du terrain, en 5 étapes. La première (inscription) est acquise dès l'ouverture du dossier. Les étapes se franchissent dans l'ordre. |
| **Décaissement** | Le versement effectif de l'argent par la banque. Deux phases : le terrain (versement unique) puis la construction (4 tranches). |
| **Tranche** | Une des 4 étapes de financement de la construction : 35 % / 30 % / 30 % / 5 %. Définies une seule fois dans `App\Support\ConstructionTranches`. |
| **Chantier** | Le suivi des TRAVAUX, distinct du suivi de l'ARGENT. Porte son propre statut, un pourcentage d'avancement, un fil de publications, des photos et un calendrier. |
| **ChantierTranche** | Les mêmes 4 tranches, vues côté avancement des travaux. À ne pas confondre avec `Decaissement.tranches`, qui est le versement correspondant. |

> **Point d'attention connu** : `Decaissement.tranches` (colonne JSON, sans
> montant par tranche) et `chantier_tranches` (table relationnelle) décrivent le
> même découpage sans être reliés. Les deux dérivent désormais de
> `ConstructionTranches`, mais leur réconciliation reste à faire. Voir
> `docs/adr/` le jour où elle sera décidée.

## Argent

Tous les montants sont en **francs CFA (XOF)**. Le franc CFA n'a pas de
sous-unité : il n'y a pas de centimes. Aucune colonne de devise n'existe encore
en base — l'hypothèse XOF est implicite partout.

Garde-fous en place : le total engagé (terrain + construction) ne peut pas
dépasser le montant de la demande ; les tranches et les étapes foncières se
valident dans l'ordre ; le terrain exige un montant renseigné ; celui qui
prépare un décaissement ne peut pas le valider lui-même.
