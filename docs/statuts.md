# Statuts et transitions

Chaque statut métier est un enum de `App\Enums`. Les valeurs sont exactement
celles stockées en base et lues par le frontend : les enums ont donné un nom aux
littéraux, ils n'ont rien renommé.

`App\Support\TransitionStatut` fait respecter les transitions et répond **409**
avec la liste des passages possibles. Avant, `Rule::in` validait la valeur sans
jamais regarder d'où l'on venait.

## Pièce justificative — `RequisDocStatut`

```mermaid
stateDiagram-v2
    [*] --> en_attente
    en_attente --> depose : le client dépose
    depose --> verification : le staff prend en charge
    depose --> accepte
    depose --> refuse
    depose --> a_remplacer
    verification --> accepte
    verification --> refuse
    verification --> a_remplacer
    refuse --> depose : nouveau dépôt
    refuse --> verification : le staff revient sur son refus
    a_remplacer --> depose
    a_remplacer --> verification
    accepte --> depose : nouveau dépôt
    accepte --> verification : acceptation erronée
```

Interdit notamment : mettre en vérification ou accepter une pièce **jamais
déposée** ; passer d'un refus à une acceptation **sans réexamen**.

## Document CPI — `CpiDocStatut`

```mermaid
stateDiagram-v2
    [*] --> brouillon
    brouillon --> disponible : publication, sans signature requise
    brouillon --> a_signer : publication, signature requise
    brouillon --> archive
    disponible --> a_signer
    disponible --> archive
    a_signer --> signe
    a_signer --> archive
    signe --> archive
    archive --> disponible
    archive --> a_signer
```

Interdit notamment : signer un **brouillon** — il n'a jamais été transmis au
client, il n'y a rien à signer.

## Orientation bancaire — `BankAssignmentStatut`

```mermaid
stateDiagram-v2
    [*] --> en_attente
    en_attente --> accord
    en_attente --> refus
    accord --> [*]
    refus --> [*]
```

Accord et refus sont **terminaux** : ce sont des décisions de l'établissement,
elles ne se révisent pas silencieusement côté CPI. Revenir en arrière suppose de
retirer l'orientation puis de la recréer — geste qui laisse une trace.

## Chantier — `ChantierStatut`

```mermaid
stateDiagram-v2
    [*] --> non_demarre
    non_demarre --> en_cours
    en_cours --> suspendu
    en_cours --> en_retard
    en_cours --> termine
    suspendu --> en_cours
    suspendu --> en_retard
    en_retard --> en_cours
    en_retard --> suspendu
    en_retard --> termine
    termine --> livre
    termine --> en_cours : reprise de travaux
    livre --> [*]
```

Interdit notamment : sauter de « non démarré » à « livré » ; revenir en arrière
après livraison.

## Parcours du dossier — `Client.dossier_etape`

Ce n'est pas un enum : c'est un entier 0-5 piloté à la main par le personnel via
`POST /staff/clients/{client}/dossier-etape`. Il reste le moteur d'état réel du
parcours, et `Demande` n'a **aucune** colonne de statut propre.

| Étape | Sens | Effet |
|---|---|---|
| 0 | Inscription | — |
| 1 | Demande | — |
| 2 | Pièces | — |
| **3** | **Analyse** | **Verrouille la demande côté client** |
| 4 | Accord bancaire | — |
| 5 | Signature | — |

`Client.statut` est un **texte libre décoratif**, validé nulle part et lu par
aucune logique métier. Ne rien y accrocher.
