<?php

namespace App\Http\Controllers\Api;

use App\Dto\BankAssignmentData;
use App\Dto\BankData;
use App\Enums\BankAssignmentStatut;
use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Models\BankAssignment;
use App\Models\Client;
use App\Support\TransitionStatut;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BankController extends Controller
{
    /** Réponse d'une banque à une orientation de dossier. */
    /** @var list<string> Valeurs acceptées — l'enum en est la source. */
    private const STATUSES = ['en-attente', 'accord', 'refus'];

    // ─── Espace client ────────────────────────────────────────

    /**
     * GET /client/mes-banques — les orientations bancaires du client connecté.
     *
     * La requête part de `$user->client` : un client ne peut structurellement
     * pas lire les orientations d'un autre dossier.
     */
    public function myAssignments(Request $request): JsonResponse
    {
        $client = $this->currentClient($request);
        $this->authorize('viewOwn', BankAssignment::class);

        $assignments = $client->bankAssignments()
            ->with('bank')
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => BankAssignmentData::collect($assignments)]);
    }

    // ─── Espace staff : registre des banques ──────────────────

    /**
     * GET /staff/banks — registre complet, chaque banque portant ses
     * orientations de dossiers (relation chargée : un seul appel suffit aux
     * tableaux de bord du personnel).
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Bank::class);

        $banks = Bank::query()
            ->with('assignments.bank')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => BankData::collect($banks)]);
    }

    /**
     * POST /staff/banks — nouvelle convention bancaire (administrateur).
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Bank::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'convention_date' => 'nullable|string|max:100',
            'validity' => 'nullable|string|max:100',
            'products' => 'nullable|array',
            'products.*' => 'string|max:100',
            'rate' => 'nullable|string|max:50',
            'contact' => 'nullable|string|max:255',
            'color' => 'sometimes|string|max:20',
        ]);

        // create() ne remonte pas les défauts SQL (color) : refresh() obligatoire.
        $bank = Bank::create(array_filter($validated, fn ($v) => $v !== null))->refresh();

        activity()
            ->causedBy($request->user())
            ->performedOn($bank)
            ->withProperties(['bank_id' => $bank->id])
            ->event('banque-creee')
            ->log("{$request->user()?->name} a ajouté la banque partenaire {$bank->name}");

        return response()->json(['data' => BankData::from($bank->load('assignments.bank'))], 201);
    }

    /**
     * PUT /staff/banks/{bank} — mise à jour de la convention (administrateur).
     */
    public function update(Request $request, Bank $bank): JsonResponse
    {
        $this->authorize('update', $bank);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'convention_date' => 'sometimes|nullable|string|max:100',
            'validity' => 'sometimes|nullable|string|max:100',
            'products' => 'sometimes|nullable|array',
            'products.*' => 'string|max:100',
            'rate' => 'sometimes|nullable|string|max:50',
            'contact' => 'sometimes|nullable|string|max:255',
            'color' => 'sometimes|string|max:20',
        ]);

        $bank->update($validated);

        activity()
            ->causedBy($request->user())
            ->performedOn($bank)
            ->withProperties(['bank_id' => $bank->id])
            ->event('banque-modifiee')
            ->log("{$request->user()?->name} a modifié la banque partenaire {$bank->name}");

        return response()->json(['data' => BankData::from($bank->refresh()->load('assignments.bank'))]);
    }

    /**
     * DELETE /staff/banks/{bank} — retrait du registre (administrateur).
     * Les orientations liées tombent avec la banque (cascade en base).
     */
    public function destroy(Request $request, Bank $bank): JsonResponse
    {
        $this->authorize('delete', $bank);

        activity()
            ->causedBy($request->user())
            ->performedOn($bank)
            ->withProperties(['bank_id' => $bank->id])
            ->event('banque-supprimee')
            ->log("{$request->user()?->name} a retiré la banque partenaire {$bank->name}");

        $bank->delete();

        return response()->json(['message' => 'Banque retirée du registre.']);
    }

    // ─── Espace staff : orientation des dossiers ──────────────

    /**
     * POST /staff/clients/{client}/banks/{bank}/assign — oriente un dossier
     * vers une banque. Idempotent : ré-orienter ne crée pas de doublon
     * (index unique (client_id, bank_id) en base) et conserve le statut acquis.
     */
    public function assign(Request $request, Client $client, Bank $bank): JsonResponse
    {
        $this->authorize('assign', BankAssignment::class);

        $assignment = BankAssignment::firstOrCreate(
            ['client_id' => $client->id, 'bank_id' => $bank->id],
            ['status' => BankAssignmentStatut::EnAttente],
        );

        $created = $assignment->wasRecentlyCreated;

        if ($created) {
            activity()
                ->causedBy($request->user())
                ->performedOn($client)
                ->withProperties(['bank_id' => $bank->id, 'bank_name' => $bank->name])
                ->event('banque-assignee')
                ->log("{$request->user()?->name} a orienté le dossier de {$client->name} vers {$bank->name}");
        }

        $data = BankAssignmentData::from($assignment->refresh()->load('bank'));

        return response()->json(['data' => $data], $created ? 201 : 200);
    }

    /**
     * POST /staff/clients/{client}/banks/{bank}/status — réponse de la banque.
     */
    public function setStatus(Request $request, Client $client, Bank $bank): JsonResponse
    {
        $this->authorize('setStatus', BankAssignment::class);

        $validated = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
        ]);

        $assignment = $this->findAssignment($client, $bank);
        $nouveau = BankAssignmentStatut::from($validated['status']);

        // Un accord ou un refus bancaire est une décision de l'établissement :
        // il ne se révise pas silencieusement côté CPI. `Rule::in` laissait
        // repasser un refus en accord sans aucune trace de décision.
        TransitionStatut::verifier($assignment->status, $nouveau, 'Réponse de la banque');

        $assignment->update(['status' => $nouveau]);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties([
                'bank_id' => $bank->id,
                'bank_name' => $bank->name,
                'status' => $validated['status'],
            ])
            ->event('banque-statut')
            ->log("{$request->user()?->name} a enregistré la réponse « {$validated['status']} » de {$bank->name} pour {$client->name}");

        return response()->json(['data' => BankAssignmentData::from($assignment->refresh()->load('bank'))]);
    }

    /**
     * DELETE /staff/clients/{client}/banks/{bank} — retire l'orientation.
     */
    public function removeAssignment(Request $request, Client $client, Bank $bank): JsonResponse
    {
        $this->authorize('remove', BankAssignment::class);

        $assignment = $this->findAssignment($client, $bank);
        $assignment->delete();

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['bank_id' => $bank->id, 'bank_name' => $bank->name])
            ->event('banque-retiree')
            ->log("{$request->user()?->name} a retiré l'orientation vers {$bank->name} pour {$client->name}");

        return response()->json(['message' => 'Orientation bancaire retirée.']);
    }

    // ─── Helpers ──────────────────────────────────────────────

    private function currentClient(Request $request): Client
    {
        $client = $request->user()?->client;
        abort_if($client === null, 404, 'Aucun dossier client associé à ce compte.');

        return $client;
    }

    private function findAssignment(Client $client, Bank $bank): BankAssignment
    {
        $assignment = $client->bankAssignments()->where('bank_id', $bank->id)->first();

        abort_if($assignment === null, 404, "Ce dossier n'est pas orienté vers cette banque.");

        return $assignment;
    }
}
