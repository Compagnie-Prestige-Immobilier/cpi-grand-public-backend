<?php

namespace App\Http\Controllers\Api\Chantier;

use App\Dto\ChantierData;
use App\Enums\ChantierStatut;
use App\Http\Controllers\Concerns\ResoudLeDossierDuClient;
use App\Http\Controllers\Controller;
use App\Models\Chantier;
use App\Models\ChantierTranche;
use App\Models\Client;
use App\Support\TransitionStatut;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Chantier d'un dossier : avancement des TRAVAUX (à ne pas confondre avec le
 * décaissement bancaire, qui suit les versements — cf. DecaissementController).
 *
 * Une ligne `chantiers` est provisionnée à la création du dossier
 * (Client::ensureChantier), comme l'état de décaissement : GET /client/mon-chantier
 * renvoie donc toujours un chantier affichable, « non démarré » à 0 % tant que
 * rien n'a commencé. Aucun 500 possible sur un dossier sans travaux.
 *
 * Le paramètre de route {num} est le NUMÉRO de la tranche (T1…T4), c'est-à-dire
 * la colonne `num` — et non un index à base 0 comme pour les décaissements, où
 * les tranches vivent dans une colonne JSON.
 */
class ChantierController extends Controller
{
    use ResoudLeDossierDuClient;

    /**
     * GET /client/mon-chantier — chantier du client connecté.
     *
     * Seuls les contenus marqués « visible client » remontent : les
     * publications, médias et événements internes ne quittent jamais le staff.
     */
    public function mine(Request $request): JsonResponse
    {
        $client = $this->currentClient($request);
        $chantier = $client->ensureChantier();
        $this->authorize('view', $chantier);

        return response()->json(['data' => $this->payload($chantier, clientView: true)]);
    }

    /**
     * GET /staff/chantiers/{client} — chantier + relations complètes.
     * La ligne est créée à la volée si le dossier est antérieur au module.
     */
    public function show(Client $client): JsonResponse
    {
        $chantier = $client->ensureChantier();
        $this->authorize('view', $chantier);

        return response()->json(['data' => $this->payload($chantier)]);
    }

    /**
     * PUT /staff/chantiers/{client} — fiche du chantier (projet, équipe, dates).
     */
    public function update(Request $request, Client $client): JsonResponse
    {
        $chantier = $client->ensureChantier();
        $this->authorize('update', $chantier);

        $validated = $request->validate([
            'projet' => 'sometimes|nullable|string|max:255',
            'reference' => 'sometimes|nullable|string|max:255',
            'localisation' => 'sometimes|nullable|string|max:255',
            'chef_chantier' => 'sometimes|nullable|string|max:255',
            'entreprise' => 'sometimes|nullable|string|max:255',
            'date_debut' => 'sometimes|nullable|date',
            'date_livraison' => 'sometimes|nullable|date',
            'progression' => 'sometimes|integer|min:0|max:100',
            'etape_actuelle' => 'sometimes|string|max:255',
            'statut' => ['sometimes', Rule::in(Chantier::STATUTS)],
        ]);

        $chantier->update([...$validated, 'derniere_maj' => $this->stamp()]);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['champs' => array_keys($validated)])
            ->event('chantier-modifie')
            ->log("{$request->user()?->name} a mis à jour le chantier de {$client->name}");

        return response()->json(['data' => $this->payload($chantier->refresh())]);
    }

    /**
     * POST /staff/chantiers/{client}/progression — avancement global (0-100 %).
     */
    public function updateProgression(Request $request, Client $client): JsonResponse
    {
        $chantier = $client->ensureChantier();
        $this->authorize('update', $chantier);

        $validated = $request->validate(['pct' => 'required|integer|min:0|max:100']);
        $ancienne = $chantier->progression;
        $nouvelle = $validated['pct'];

        $chantier->update(['progression' => $nouvelle, 'derniere_maj' => $this->stamp()]);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['ancienne' => $ancienne, 'nouvelle' => $nouvelle])
            ->event('chantier-progression')
            ->log("{$request->user()?->name} a porté l'avancement du chantier de {$client->name} de {$ancienne}% à {$nouvelle}%");

        return response()->json(['data' => $this->payload($chantier->refresh())]);
    }

    /**
     * POST /staff/chantiers/{client}/etape — étape courante des travaux.
     */
    public function updateEtape(Request $request, Client $client): JsonResponse
    {
        $chantier = $client->ensureChantier();
        $this->authorize('update', $chantier);

        $validated = $request->validate(['etape' => 'required|string|max:255']);
        $ancienne = $chantier->etape_actuelle;
        $nouvelle = $validated['etape'];

        $chantier->update(['etape_actuelle' => $nouvelle, 'derniere_maj' => $this->stamp()]);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['ancienne' => $ancienne, 'nouvelle' => $nouvelle])
            ->event('chantier-etape')
            ->log("{$request->user()?->name} a placé le chantier de {$client->name} à l'étape « {$nouvelle} »");

        return response()->json(['data' => $this->payload($chantier->refresh())]);
    }

    /**
     * POST /staff/chantiers/{client}/statut — statut du chantier.
     */
    public function updateStatut(Request $request, Client $client): JsonResponse
    {
        $chantier = $client->ensureChantier();
        $this->authorize('update', $chantier);

        $validated = $request->validate([
            'statut' => ['required', Rule::in(Chantier::STATUTS)],
        ]);
        $ancien = $chantier->statut;
        $nouveau = ChantierStatut::from($validated['statut']);

        // `Rule::in` validait la valeur, jamais la transition : un chantier
        // livré pouvait redevenir « non démarré », ce que le client voyait
        // sans explication.
        TransitionStatut::verifier($ancien, $nouveau, 'Statut du chantier');

        $chantier->update(['statut' => $nouveau, 'derniere_maj' => $this->stamp()]);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['ancien' => $ancien, 'nouveau' => $nouveau])
            ->event('chantier-statut')
            ->log("{$request->user()?->name} a passé le chantier de {$client->name} au statut « {$nouveau->libelle()} »");

        return response()->json(['data' => $this->payload($chantier->refresh())]);
    }

    /**
     * POST /staff/chantiers/{client}/tranche/{num}/validate — travaux de la
     * tranche T{num} terminés ({num} = colonne `num`, 1 à 4).
     */
    public function validateTranche(Request $request, Client $client, string $num): JsonResponse
    {
        $chantier = $client->ensureChantier();
        $this->authorize('validate', $chantier);

        $tranche = $this->findTranche($chantier, $num);
        $tranche->update(['etat' => 'terminee', 'date' => now()]);
        $chantier->update(['derniere_maj' => $this->stamp()]);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['tranche' => $tranche->num])
            ->event('chantier-tranche-terminee')
            ->log("{$request->user()?->name} a marqué la tranche T{$tranche->num} du chantier de {$client->name} comme terminée");

        return response()->json(['data' => $this->payload($chantier->refresh())]);
    }

    // ─── Helpers ──────────────────────────────────────────────

    /**
     * Tranche T{num} du chantier — 404 si le numéro n'existe pas.
     */
    private function findTranche(Chantier $chantier, string $num): ChantierTranche
    {
        abort_unless(ctype_digit($num), 404, 'Tranche de chantier inconnue.');

        /** @var ChantierTranche|null $tranche */
        $tranche = $chantier->tranches()->where('num', (int) $num)->first();
        abort_if($tranche === null, 404, 'Tranche de chantier inconnue.');

        return $tranche;
    }

    /**
     * Chantier + relations. `clientView` filtre les contenus internes : un
     * client ne voit que ce que le personnel a explicitement rendu visible.
     */
    private function payload(Chantier $chantier, bool $clientView = false): ChantierData
    {
        $visible = fn ($query) => $clientView ? $query->where('visible_client', true) : $query;

        $chantier->load([
            'tranches',
            'publications' => $visible,
            'medias' => $visible,
            'events' => $visible,
        ]);

        return ChantierData::from($chantier);
    }

    /**
     * Horodatage lisible de la dernière mise à jour (colonne `derniere_maj`,
     * texte libre affiché tel quel — même format que DocController).
     */
    private function stamp(): string
    {
        return now()->format('d/m/Y');
    }
}
