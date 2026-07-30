<?php

namespace App\Http\Controllers\Api\Chantier;

use App\Dto\ChantierPublicationData;
use App\Http\Controllers\Controller;
use App\Models\ChantierPublication;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Fil de chantier d'un dossier — routes /staff/chantiers/{client}/publications.
 *
 * Toute publication est résolue DANS le chantier de {client} : un identifiant
 * appartenant au chantier d'un autre dossier donne 404, jamais une lecture ni
 * une écriture croisée.
 */
class ChantierPublicationController extends Controller
{
    /** Types de publication acceptés par le fil de chantier. */
    private const TYPES = [
        'actualite', 'photo', 'video', 'document',
        'commentaire', 'etape-validee', 'retard', 'visite',
    ];

    /**
     * GET /staff/chantiers/{client}/publications
     */
    public function index(Client $client): JsonResponse
    {
        $this->authorize('viewAny', ChantierPublication::class);
        $chantier = $client->ensureChantier();

        return response()->json([
            'data' => ChantierPublicationData::collect($chantier->publications()->get()),
        ]);
    }

    /**
     * POST /staff/chantiers/{client}/publications
     */
    public function store(Request $request, Client $client): JsonResponse
    {
        $this->authorize('create', ChantierPublication::class);
        $chantier = $client->ensureChantier();

        $validated = $request->validate([
            'phase' => 'required|integer|min:0|max:10',
            'titre' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'type' => ['required', Rule::in(self::TYPES)],
            'visible_client' => 'sometimes|boolean',
            'date' => 'sometimes|nullable|date',
            'heure' => 'sometimes|nullable|string|max:20',
        ]);

        $publication = $chantier->publications()->create([
            'phase' => $validated['phase'],
            'titre' => $validated['titre'],
            'description' => $validated['description'] ?? '',
            'type' => $validated['type'],
            'visible_client' => $validated['visible_client'] ?? true,
            'date' => $validated['date'] ?? now(),
            'heure' => $validated['heure'] ?? now()->format('H:i'),
            // Route derrière auth:sanctum : l'utilisateur est toujours présent.
            'auteur' => $request->user()->name,
        ]);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['publication' => $publication->id, 'type' => $publication->type])
            ->event('chantier-publication-ajoutee')
            ->log("{$request->user()?->name} a publié « {$publication->titre} » sur le chantier de {$client->name}");

        return response()->json(['data' => ChantierPublicationData::from($publication->refresh())], 201);
    }

    /**
     * PUT /staff/chantiers/{client}/publications/{publication}
     */
    public function update(Request $request, Client $client, string $publication): JsonResponse
    {
        $row = $this->findPublication($client, $publication);
        $this->authorize('update', $row);

        $validated = $request->validate([
            'phase' => 'sometimes|integer|min:0|max:10',
            'titre' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string|max:5000',
            'type' => ['sometimes', Rule::in(self::TYPES)],
            'visible_client' => 'sometimes|boolean',
            'date' => 'sometimes|nullable|date',
            'heure' => 'sometimes|nullable|string|max:20',
        ]);

        if (array_key_exists('description', $validated)) {
            $validated['description'] = $validated['description'] ?? '';
        }

        $row->update($validated);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['publication' => $row->id, 'champs' => array_keys($validated)])
            ->event('chantier-publication-modifiee')
            ->log("{$request->user()?->name} a modifié la publication « {$row->titre} » du chantier de {$client->name}");

        return response()->json(['data' => ChantierPublicationData::from($row->refresh())]);
    }

    /**
     * DELETE /staff/chantiers/{client}/publications/{publication}
     */
    public function destroy(Request $request, Client $client, string $publication): JsonResponse
    {
        $row = $this->findPublication($client, $publication);
        $this->authorize('delete', $row);

        $titre = $row->titre;
        $row->delete();

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['publication' => $publication])
            ->event('chantier-publication-supprimee')
            ->log("{$request->user()?->name} a supprimé la publication « {$titre} » du chantier de {$client->name}");

        return response()->json(['message' => 'Publication supprimée.']);
    }

    // ─── Helpers ──────────────────────────────────────────────

    /**
     * Publication du chantier de CE dossier — 404 pour tout identifiant
     * étranger, y compris celui d'un chantier voisin.
     */
    private function findPublication(Client $client, string $id): ChantierPublication
    {
        // Sur PostgreSQL la clé est une vraie colonne `uuid` : comparer un
        // identifiant mal formé y déclenche une erreur SQL, pas un résultat vide.
        abort_unless(Str::isUuid($id), 404, 'Publication de chantier introuvable.');

        $chantier = $client->ensureChantier();

        /** @var ChantierPublication|null $row */
        $row = $chantier->publications()->whereKey($id)->first();
        abort_if($row === null, 404, 'Publication de chantier introuvable.');

        return $row;
    }
}
