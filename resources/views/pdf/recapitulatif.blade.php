{{--
    Récapitulatif de demande de financement — rendu par dompdf.

    Contraintes de dompdf, à garder en tête avant toute retouche :
      · pas de flexbox ni de grid — la mise en page passe par des <table> ;
      · aucune image matricielle : l'extension gd n'est pas installée dans
        l'image de production (dompdf ne l'exige qu'en dépendance de dev).
        L'en-tête est donc typographique. Ajouter un logo PNG/JPG imposerait
        d'ajouter gd ET ses en-têtes de compilation au Dockerfile ;
      · pas de police externe : on reste sur DejaVu Sans, embarquée avec
        dompdf, seule à couvrir correctement les accents français.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Récapitulatif — {{ $client->ref }}</title>
    <style>
        /* Marges resserrées pour qu'un dossier courant (jusqu'à ~5 pièces) tienne
           sur une seule page — un récapitulatif client de 2 pages dont la
           seconde est vide aux trois quarts fait négligé. */
        @page { margin: 16mm 18mm 20mm; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10pt;
            color: #2A2226;
            line-height: 1.5;
        }

        .marque       { color: #7B1A2E; }
        .entete       { border-bottom: 2pt solid #7B1A2E; padding-bottom: 8pt; margin-bottom: 12pt; }
        .entete h1    { font-size: 17pt; margin: 0 0 2pt; color: #7B1A2E; letter-spacing: -0.3pt; }
        .entete .sous { font-size: 8.5pt; color: #6B5B60; margin: 0; }
        .entete .ref  { font-size: 9pt; color: #6B5B60; margin: 6pt 0 0; }

        h2 {
            font-size: 10.5pt; color: #7B1A2E; margin: 11pt 0 5pt;
            text-transform: uppercase; letter-spacing: 0.6pt;
            border-bottom: 0.6pt solid #E6D9DC; padding-bottom: 3pt;
        }

        table.champs { width: 100%; border-collapse: collapse; }
        table.champs td { padding: 3pt 0; vertical-align: top; }
        table.champs td.cle { color: #6B5B60; width: 42%; }
        table.champs td.val { font-weight: bold; }

        /* Pas de `page-break-inside: avoid` ici : dompdf pousse alors le tableau
           ENTIER sur la page suivante, ce qui creuse un blanc sous le titre.
           Mieux vaut le laisser se couper — le <thead> se répète tout seul. */
        table.pieces { width: 100%; border-collapse: collapse; margin-top: 4pt; }
        table.pieces th {
            text-align: left; font-size: 8pt; text-transform: uppercase;
            letter-spacing: 0.5pt; color: #6B5B60; padding: 5pt 6pt;
            border-bottom: 0.6pt solid #E6D9DC;
        }
        table.pieces td { padding: 5pt 6pt; border-bottom: 0.4pt solid #F0E8EA; }

        .etat        { font-weight: bold; }
        .etat-ok     { color: #1A6B44; }
        .etat-ko     { color: #C0392B; }
        .etat-attente{ color: #B05070; }
        .etat-neutre { color: #6B5B60; }

        .encadre {
            margin-top: 10pt; padding: 8pt 11pt;
            background: #FAF5F6; border-left: 2.5pt solid #7B1A2E;
            font-size: 9pt;
        }

        .pied {
            position: fixed; bottom: -12mm; left: 0; right: 0;
            font-size: 7.5pt; color: #8A7A7F; text-align: center;
            border-top: 0.4pt solid #E6D9DC; padding-top: 5pt;
        }
    </style>
</head>
<body>

<div class="pied">
    Compagnie Prestige Immobilier — document généré le {{ $genereLe }}.
    Ce récapitulatif reflète l'état du dossier à cette date et ne vaut pas accord de financement.
</div>

<div class="entete">
    <h1>Récapitulatif de demande</h1>
    <p class="sous">COMPAGNIE PRESTIGE IMMOBILIER — Financement immobilier au Sénégal</p>
    <p class="ref">Dossier <strong class="marque">{{ $client->ref }}</strong> · édité le {{ $genereLe }}</p>
</div>

<h2>Demandeur</h2>
<table class="champs">
    <tr><td class="cle">Nom</td><td class="val">{{ $client->name }}</td></tr>
    <tr><td class="cle">E-mail</td><td class="val">{{ $client->email ?: '—' }}</td></tr>
    <tr><td class="cle">Téléphone</td><td class="val">{{ $client->phone ?: '—' }}</td></tr>
    <tr><td class="cle">Adresse</td><td class="val">{{ $client->adresse ?: '—' }}</td></tr>
    <tr><td class="cle">Conseiller CPI</td><td class="val">{{ $client->conseiller ?: 'Non assigné' }}</td></tr>
</table>

<h2>Projet</h2>
<table class="champs">
    <tr><td class="cle">Type de demande</td><td class="val">{{ $demande?->type_projet ?: '—' }}</td></tr>
    <tr><td class="cle">Nature du projet</td><td class="val">{{ $demande?->nature_projet ?: '—' }}</td></tr>
    <tr><td class="cle">Montant demandé</td><td class="val">{{ $montant }}</td></tr>
    <tr><td class="cle">Durée souhaitée</td><td class="val">{{ $demande?->duree ?: '—' }}</td></tr>
    <tr><td class="cle">Apport personnel</td><td class="val">{{ $apport }}</td></tr>
    <tr><td class="cle">Localisation</td><td class="val">{{ $localisation }}</td></tr>
</table>

@if ($demande?->description)
    <div class="encadre">{{ $demande->description }}</div>
@endif

<h2>Pièces justificatives ({{ $nbValidees }}/{{ $pieces->count() }} validées)</h2>
<table class="pieces">
    <thead>
        <tr><th style="width:46%">Pièce</th><th style="width:27%">État</th><th style="width:27%">Dernier dépôt</th></tr>
    </thead>
    <tbody>
        @forelse ($pieces as $piece)
            <tr>
                <td>{{ $piece->label }}</td>
                <td class="etat {{ $etatClasses[$piece->status] ?? 'etat-neutre' }}">
                    {{ $etatLibelles[$piece->status] ?? $piece->status }}
                </td>
                <td>{{ $piece->date ?: '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="3">Aucune pièce enregistrée.</td></tr>
        @endforelse
    </tbody>
</table>

<h2>Avancement</h2>
<table class="champs">
    <tr><td class="cle">Statut de la demande</td><td class="val">{{ $statutDemande }}</td></tr>
    <tr><td class="cle">Étape du parcours</td><td class="val">{{ $etapeLibelle }} ({{ $etape + 1 }}/6)</td></tr>
    <tr><td class="cle">Date d'envoi</td><td class="val">{{ $envoyeeLe }}</td></tr>
</table>

</body>
</html>
