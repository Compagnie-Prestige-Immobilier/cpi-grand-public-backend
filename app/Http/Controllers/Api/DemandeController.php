<?php

namespace App\Http\Controllers\Api;

use App\Dto\DemandeData;
use App\Http\Controllers\Concerns\ResoudLeDossierDuClient;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Demande;
use App\Models\Notification;
use App\Models\RequisDoc;
use App\Support\ParcoursDossier;
use App\Support\VerrouDossier;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class DemandeController extends Controller
{
    use ResoudLeDossierDuClient;

    /**
     * GET /client/ma-demande — la demande du client connecté (ou null).
     */
    public function mine(Request $request): JsonResponse
    {
        $client = $this->currentClient($request);
        $demande = $client->demande;

        return response()->json(['data' => $demande ? DemandeData::from($demande) : null]);
    }

    /**
     * POST /client/ma-demande — crée ou met à jour la demande du client.
     */
    public function saveMine(Request $request): JsonResponse
    {
        $client = $this->currentClient($request);

        // Le dossier se fige dès que CPI l'étudie.
        //
        // Auparavant la demande restait modifiable indéfiniment : un client
        // pouvait porter son montant de 25 à 40 millions après l'analyse et
        // l'envoi en banque, sans que personne n'en soit informé — l'agent
        // continuait de travailler sur un chiffre que la fiche ne portait plus.
        //
        // Le verrou est ICI, côté serveur, et pas seulement dans l'interface :
        // masquer le bouton laisserait l'API grande ouverte.
        abort_if(
            VerrouDossier::estVerrouille($client),
            409,
            "Votre dossier est en cours d'étude par CPI : les informations ne sont plus modifiables. Contactez votre conseiller pour toute correction.",
        );

        $validated = $request->validate([
            'type_projet' => 'sometimes|string|max:100',
            'nature_projet' => 'sometimes|string|max:100',
            'montant' => 'sometimes|nullable|numeric|min:0',
            'duree' => 'sometimes|string|max:10',
            'apport' => 'sometimes|numeric|min:0',
            'region' => 'sometimes|string|max:100',
            'commune' => 'sometimes|nullable|string|max:150',
            'adresse_projet' => 'sometimes|nullable|string|max:255',
            'description' => 'sometimes|nullable|string|max:5000',
        ]);

        $demande = Demande::updateOrCreate(['client_id' => $client->id], $validated)->refresh();

        return response()->json(['data' => DemandeData::from($demande)]);
    }

    /**
     * POST /client/ma-demande/submit — soumet la demande + journal.
     */
    public function submitMine(Request $request): JsonResponse
    {
        $client = $this->currentClient($request);
        $demande = $client->demande;
        abort_if($demande === null, 404, 'Aucune demande à soumettre.');

        $this->authorize('update', $demande);
        // Soumettre après le début de l'analyse remettrait le dossier dans un
        // état que l'agent croit figé.
        VerrouDossier::refuserSiVerrouille($client, 'Soumission impossible');

        $demande->update([
            'submitted' => true,
            'submitted_at' => now(),
        ]);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->event('demande-soumise')
            ->log("{$client->name} a soumis sa demande de financement");

        return response()->json(['data' => DemandeData::from($demande->refresh())]);
    }

    /**
     * PUT /staff/clients/{client}/demande — correction par le personnel CPI.
     *
     * Contrepartie indispensable du verrou posé sur `saveMine` : à partir de
     * l'étape « Analyse » le client ne peut plus toucher à sa demande, et une
     * coquille repérée à ce moment-là ne pouvait plus être corrigée par
     * PERSONNE. L'agent reprend donc la main, sans limite d'étape.
     *
     * La différence de fond avec l'ancienne situation n'est pas qui écrit, mais
     * que l'écriture laisse une trace : l'ancien et le nouveau contenu partent
     * au journal, et le client est prévenu.
     */
    public function updateForClient(Request $request, Client $client): JsonResponse
    {
        $validated = $request->validate([
            'type_projet' => 'sometimes|string|max:100',
            'nature_projet' => 'sometimes|string|max:100',
            'montant' => 'sometimes|nullable|numeric|min:0',
            'duree' => 'sometimes|string|max:10',
            'apport' => 'sometimes|numeric|min:0',
            'region' => 'sometimes|string|max:100',
            'commune' => 'sometimes|nullable|string|max:150',
            'adresse_projet' => 'sometimes|nullable|string|max:255',
            'description' => 'sometimes|nullable|string|max:5000',
        ]);

        $demande = $client->demande;
        abort_if($demande === null, 404, "Ce dossier n'a pas encore de demande à corriger.");

        // Le middleware `staff` garantit le rôle, pas la permission : sans ceci,
        // un agent sans `edit-client` écrivait dans la demande d'un dossier
        // verrouillé alors que toutes les autres mutations staff du domaine
        // passent par une policy. C'était le seul trou d'autorisation de l'API.
        $this->authorize('updateAsStaff', $demande);

        // Valeurs d'avant, limitées aux champs réellement touchés : le journal
        // doit dire ce qui a changé, pas répéter toute la demande.
        $avant = [];
        foreach (array_keys($validated) as $champ) {
            $avant[$champ] = $demande->getAttribute($champ);
        }

        $demande->update($validated);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['avant' => $avant, 'apres' => $validated])
            ->event('demande-corrigee')
            ->log("{$request->user()?->name} a corrigé la demande de {$client->name}");

        // Le client doit savoir que son dossier a été modifié en son nom.
        if ($client->user_id !== null) {
            Notification::create([
                'client_id' => $client->id,
                'user_id' => $client->user_id,
                'titre' => 'Demande corrigée',
                'message' => 'Votre conseiller CPI a corrigé une information de votre demande. Consultez « Ma demande ».',
                'type' => 'info',
                'target_page' => 'ma-demande',
                'date' => now(),
                'heure' => now()->format('H:i'),
                'lu' => false,
            ]);
        }

        return response()->json(['data' => DemandeData::from($demande->refresh())]);
    }

    /**
     * GET /client/ma-demande/recapitulatif — récapitulatif PDF du dossier.
     *
     * Rend un PDF téléchargeable plutôt que du JSON : c'est le seul endpoint
     * client qui ne renvoie pas de données structurées, d'où l'absence de
     * `['data' => …]`.
     */
    public function recapitulatifMine(Request $request): Response
    {
        $client = $this->currentClient($request);
        $client->loadMissing('demande', 'requisDocs');
        $demande = $client->demande;
        $pieces = $client->requisDocs;

        $nbValidees = $pieces->where('status', 'accepte')->count();
        $etape = ParcoursDossier::etape((bool) $demande?->submitted, $pieces, $client->dossier_etape);

        $pdf = Pdf::loadView('pdf.recapitulatif', [
            'client' => $client,
            'demande' => $demande,
            'pieces' => $pieces,
            'nbValidees' => $nbValidees,
            'genereLe' => $this->dateFr(now()),
            'montant' => $demande?->montant !== null ? $this->fcfa((float) $demande->montant) : '—',
            'apport' => $demande?->apport !== null ? $this->fcfa((float) $demande->apport) : '—',
            'localisation' => collect([$demande?->adresse_projet, $demande?->commune, $demande?->region])
                ->filter()->implode(', ') ?: '—',
            'envoyeeLe' => $demande?->submitted_at ? $this->dateFr($demande->submitted_at) : 'Non envoyée',
            'statutDemande' => $demande?->submitted ? 'Envoyée à CPI' : 'Brouillon — pas encore envoyée',
            'etape' => $etape,
            'etapeLibelle' => self::ETAPES[$etape] ?? '—',
            'etatLibelles' => self::ETATS_PIECE,
            'etatClasses' => self::CLASSES_PIECE,
        ]);

        // Nom de fichier bâti sur la référence du dossier : deux
        // téléchargements successifs ne s'écrasent pas dans le dossier
        // « Téléchargements » du client.
        return $pdf->download("recapitulatif-{$client->ref}.pdf");
    }

    /**
     * Étape à partir de laquelle la demande n'est plus modifiable par le client.
     *
     * 3 = « Analyse » : le client garde la main tant que le dossier est
     * seulement *reçu* (il peut corriger une faute de frappe lui-même), et le
     * dossier se fige dès que CPI commence réellement à l'instruire.
     */
    /** @deprecated Utiliser VerrouDossier::ETAPE — conservée le temps que les tests migrent. */
    public const ETAPE_VERROUILLAGE = VerrouDossier::ETAPE;

    /** Libellés du parcours — miroir de TIMELINE_STEPS côté frontend. */
    private const ETAPES = [
        'Inscription', 'Dossier reçu', 'Documents valides',
        'Analyse', 'Validation banque', 'Signature',
    ];

    /** Libellés affichés pour les statuts serveur (les clés ne changent pas). */
    private const ETATS_PIECE = [
        'en-attente' => 'À envoyer',
        'depose' => 'En attente de validation',
        'verification' => 'En vérification',
        'accepte' => 'Validé',
        'refuse' => 'Refusé',
        'a-remplacer' => 'À remplacer',
    ];

    private const CLASSES_PIECE = [
        'en-attente' => 'etat-neutre',
        'depose' => 'etat-attente',
        'verification' => 'etat-attente',
        'accepte' => 'etat-ok',
        'refuse' => 'etat-ko',
        'a-remplacer' => 'etat-ko',
    ];

    /**
     * Portage de ClientController::computeJourneyStep — même règle, pour que le
     * PDF affiche exactement l'étape que le client voit à l'écran.
     *
     * @param  Collection<int, RequisDoc>  $pieces
     */

    /** 25000000 → « 25 000 000 FCFA » (espace insécable fine, comme à l'écran). */
    private function fcfa(float $montant): string
    {
        return number_format($montant, 0, ',', ' ').' FCFA';
    }

    /**
     * « 11 août 2026 » — locale forcée sur l'instance.
     *
     * APP_LOCALE vaut « en » : sans ce `locale('fr')`, translatedFormat sortait
     * « 11 August 2026 » dans un document par ailleurs entièrement français.
     * On le fixe ici plutôt que globalement pour ne rien changer au reste de
     * l'application (messages de validation, etc.).
     */
    private function dateFr(CarbonInterface $date): string
    {
        return $date->locale('fr')->translatedFormat('j F Y');
    }
}
