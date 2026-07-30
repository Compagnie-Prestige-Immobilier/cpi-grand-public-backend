<?php

namespace App\Http\Controllers\Api;

use App\Dto\ClientData;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\RequisDoc;
use App\Services\DemoDataService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\PaginatedDataCollection;

class ClientController extends Controller
{
    // ─── Espace client (self-service) ─────────────────────────

    /**
     * GET /client/profile — le dossier du client connecté.
     *
     * NB : sérialisation via response()->json() (contexte sans wrapping) pour
     * éviter le double-wrap des collections imbriquées par le wrap global.
     */
    public function myProfile(Request $request): JsonResponse
    {
        $client = $this->currentClient($request);

        return response()->json(['data' => ClientData::from($client->load('demande'))]);
    }

    /**
     * PUT /client/profile — mise à jour du profil par le client.
     */
    public function updateMyProfile(Request $request): JsonResponse
    {
        $client = $this->currentClient($request);
        $this->authorize('update', $client);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|nullable|string|max:50',
            'adresse' => 'sometimes|nullable|string|max:255',
            'employer' => 'sometimes|nullable|string|max:255',
            'fonction' => 'sometimes|nullable|string|max:255',
            'project_nom' => 'sometimes|nullable|string|max:255',
        ]);

        $client->update($validated);

        return response()->json(['data' => ClientData::from($client->refresh())]);
    }

    /**
     * GET /client/mon-dossier-journey — étape du parcours du dossier.
     */
    public function myDossierJourney(Request $request): JsonResponse
    {
        return $this->journeyResponse($this->currentClient($request));
    }

    // ─── Espace staff ─────────────────────────────────────────

    /**
     * GET /staff/clients — liste paginée (relations chargées pour que les
     * tableaux de bord staff s'alimentent en un seul appel).
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Client::class);

        return response()->json(ClientData::collect(
            Client::query()
                ->with('demande', 'requisDocs')
                ->orderByDesc('created_at')
                ->paginate(50),
            PaginatedDataCollection::class,
        ));
    }

    /**
     * GET /staff/clients/{client} — un client avec ses relations.
     */
    public function show(Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        return response()->json(['data' => ClientData::from($client->load('demande', 'requisDocs'))]);
    }

    /**
     * POST /staff/clients — création d'un client (réf générée côté serveur).
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Client::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'adresse' => 'nullable|string|max:255',
            'project_nom' => 'nullable|string|max:255',
            'employer' => 'nullable|string|max:255',
            'fonction' => 'nullable|string|max:255',
            'conseiller' => 'nullable|string|max:255',
            'banque' => 'nullable|string|max:255',
            'statut' => 'nullable|string|max:255',
            'date_inscription' => 'nullable|date',
        ]);

        $client = Client::create([
            ...collect($validated)->filter(fn ($v) => $v !== null)->all(),
            'ref' => Client::generateRef(),
            'date_inscription' => $validated['date_inscription'] ?? now(),
        ])->refresh();

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->event('client-cree')
            ->log("{$request->user()?->name} a créé le dossier client {$client->name}");

        return response()->json(['data' => ClientData::from($client)], 201);
    }

    /**
     * PUT /staff/clients/{client} — mise à jour.
     */
    public function update(Request $request, Client $client): JsonResponse
    {
        $this->authorize('update', $client);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'phone' => 'sometimes|nullable|string|max:50',
            'adresse' => 'sometimes|nullable|string|max:255',
            'project_nom' => 'sometimes|nullable|string|max:255',
            'employer' => 'sometimes|nullable|string|max:255',
            'fonction' => 'sometimes|nullable|string|max:255',
            'conseiller' => 'sometimes|nullable|string|max:255',
            'banque' => 'sometimes|nullable|string|max:255',
            'statut' => 'sometimes|string|max:255',
            'progression' => 'sometimes|integer|min:0|max:100',
        ]);

        $client->update($validated);

        return response()->json(['data' => ClientData::from($client->refresh())]);
    }

    /**
     * DELETE /staff/clients/{client}.
     */
    public function destroy(Request $request, Client $client): JsonResponse
    {
        $this->authorize('delete', $client);

        activity()
            ->causedBy($request->user())
            ->event('client-supprime')
            ->log("{$request->user()?->name} a supprimé le dossier client {$client->name}");

        $client->delete();

        return response()->json(['message' => 'Dossier client supprimé.']);
    }

    /**
     * GET /staff/clients/{client}/summary — résumé léger pour la sidebar.
     */
    public function summary(Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        return response()->json(['data' => [
            'id' => $client->id,
            'name' => $client->name,
            'ref' => $client->ref,
            'statut' => $client->statut,
            'progression' => $client->progression,
            'projectNom' => $client->project_nom,
            'adresse' => $client->adresse,
            'dossierEtape' => $client->dossier_etape,
        ]]);
    }

    /**
     * POST /staff/clients/{client}/dossier-etape — étape 0-5 + journal.
     */
    public function setDossierEtape(Request $request, Client $client): JsonResponse
    {
        $this->authorize('update', $client);

        $validated = $request->validate([
            'etape' => 'required|integer|min:0|max:5',
        ]);

        $client->update(['dossier_etape' => $validated['etape']]);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['etape' => $validated['etape']])
            ->event('dossier-etape')
            ->log("{$request->user()?->name} a défini l'étape du dossier de {$client->name} à {$validated['etape']}");

        return response()->json(['data' => ClientData::from($client->refresh())]);
    }

    /**
     * GET /staff/clients/{client}/dossier-journey.
     */
    public function dossierJourney(Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        return $this->journeyResponse($client);
    }

    // ─── Démonstration (Phase 7) ──────────────────────────────

    /**
     * POST /staff/demo/seed — charge 30 dossiers fictifs (STEP 14).
     *
     * Réservé à l'administrateur (policy `manageDemo` → permission
     * `manage-demo-data`, que seul le rôle super-admin détient).
     *
     * NON REJOUABLE tant que la démonstration est en place : un second appel
     * répond 409 au lieu de doubler le jeu de données. C'est le choix le plus
     * lisible pour l'opérateur — « Supprimer les données de démo » est le seul
     * chemin de remise à zéro, et aucun état intermédiaire n'est possible.
     */
    public function seedDemo(Request $request, DemoDataService $demo): JsonResponse
    {
        $this->authorize('manageDemo', Client::class);

        $existants = $demo->count();
        if ($existants > 0) {
            return response()->json([
                'message' => "Des données de démonstration sont déjà présentes ({$existants} dossier(s)). "
                    .'Supprimez-les avant d\'en charger de nouvelles.',
            ], 409);
        }

        $clients = DB::transaction(fn (): int => $demo->seed());

        activity()
            ->causedBy($request->user())
            ->withProperties(['clients' => $clients])
            ->event('demo-chargee')
            ->log("{$request->user()?->name} a chargé {$clients} dossiers de démonstration");

        return response()->json([
            'data' => [
                'clients' => $clients,
                'comptes' => $clients,
                'banques' => 3,
                'motDePasse' => DemoDataService::PASSWORD,
            ],
            'message' => "{$clients} dossiers de démonstration ont été créés.",
        ]);
    }

    /**
     * DELETE /staff/demo/clear — purge intégrale de la démonstration.
     *
     * Ne touche QUE les lignes préfixées « demo- » et ce qui en dépend : les
     * vrais dossiers, leurs pièces, leurs chantiers et leur journal restent
     * intacts (cf. DemoDataTest).
     */
    public function clearDemo(Request $request, DemoDataService $demo): JsonResponse
    {
        $this->authorize('manageDemo', Client::class);

        $bilan = DB::transaction(fn (): array => $demo->clear());

        activity()
            ->causedBy($request->user())
            ->withProperties($bilan)
            ->event('demo-supprimee')
            ->log("{$request->user()?->name} a supprimé {$bilan['clients']} dossiers de démonstration");

        return response()->json([
            'data' => [
                'clients' => $bilan['clients'],
                'comptes' => $bilan['users'],
                'banques' => $bilan['banks'],
                'entreesJournal' => $bilan['activites'],
            ],
            'message' => "{$bilan['clients']} dossiers de démonstration ont été supprimés.",
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────

    private function currentClient(Request $request): Client
    {
        $client = $request->user()?->client;
        abort_if($client === null, 404, 'Aucun dossier client associé à ce compte.');

        return $client;
    }

    private function journeyResponse(Client $client): JsonResponse
    {
        $client->loadMissing('demande', 'requisDocs');

        $submitted = (bool) $client->demande?->submitted;
        $step = $this->computeJourneyStep($submitted, $client->requisDocs, $client->dossier_etape);

        return response()->json(['data' => [
            'step' => $step,
            'submitted' => $submitted,
            'dossierEtape' => $client->dossier_etape,
        ]]);
    }

    /**
     * Porté de dossierJourney.ts — le backend est la source de vérité.
     *
     * @param  Collection<int, RequisDoc>  $docs
     */
    private function computeJourneyStep(bool $submitted, Collection $docs, int $etapeCpi = 2): int
    {
        if (! $submitted) {
            return 0;
        }
        $allValid = $docs->isNotEmpty() && $docs->every(fn (RequisDoc $d) => $d->status === 'accepte');
        if (! $allValid) {
            return 1;
        }

        return min(5, max(2, $etapeCpi));
    }
}
