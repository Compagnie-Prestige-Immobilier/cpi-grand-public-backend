<?php

namespace App\Http\Controllers\Api\Chantier;

use App\Dto\ChantierEventData;
use App\Http\Controllers\Controller;
use App\Models\ChantierEvent;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Calendrier d'un chantier — routes /staff/chantiers/{client}/events.
 *
 * Tout événement est résolu DANS le chantier de {client} : un identifiant
 * appartenant au chantier d'un autre dossier donne 404, jamais une lecture ni
 * une écriture croisée.
 */
class ChantierEventController extends Controller
{
    /** Natures d'événement du calendrier de chantier. */
    private const TYPES = [
        'visite', 'inspection', 'livraison-materiaux', 'debut-etape',
        'fin-etape', 'rdv-client', 'reception', 'remise-cles',
    ];

    /** Cycle de vie d'un événement. */
    private const STATUTS = ['prevu', 'confirme', 'realise', 'reporte', 'annule'];

    /**
     * GET /staff/chantiers/{client}/events
     */
    public function index(Client $client): JsonResponse
    {
        $this->authorize('viewAny', ChantierEvent::class);
        $chantier = $client->ensureChantier();

        return response()->json([
            'data' => ChantierEventData::collect($chantier->events()->get()),
        ]);
    }

    /**
     * POST /staff/chantiers/{client}/events
     */
    public function store(Request $request, Client $client): JsonResponse
    {
        $this->authorize('create', ChantierEvent::class);
        $chantier = $client->ensureChantier();

        $validated = $request->validate([
            'titre' => 'required|string|max:255',
            'type' => ['required', Rule::in(self::TYPES)],
            'date' => 'required|date',
            'heure' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:5000',
            'statut' => ['sometimes', Rule::in(self::STATUTS)],
            'visible_client' => 'sometimes|boolean',
        ]);

        $event = $chantier->events()->create([
            'titre' => $validated['titre'],
            'type' => $validated['type'],
            'date' => $validated['date'],
            'heure' => $validated['heure'] ?? null,
            'description' => $validated['description'] ?? '',
            'statut' => $validated['statut'] ?? 'prevu',
            'visible_client' => $validated['visible_client'] ?? true,
        ]);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['event' => $event->id, 'type' => $event->type])
            ->event('chantier-event-planifie')
            ->log("{$request->user()?->name} a planifié « {$event->titre} » sur le chantier de {$client->name}");

        return response()->json(['data' => ChantierEventData::from($event->refresh())], 201);
    }

    /**
     * PUT /staff/chantiers/{client}/events/{event}
     */
    public function update(Request $request, Client $client, string $event): JsonResponse
    {
        $row = $this->findEvent($client, $event);
        $this->authorize('update', $row);

        $validated = $request->validate([
            'titre' => 'sometimes|string|max:255',
            'type' => ['sometimes', Rule::in(self::TYPES)],
            'date' => 'sometimes|date',
            'heure' => 'sometimes|nullable|string|max:20',
            'description' => 'sometimes|nullable|string|max:5000',
            'statut' => ['sometimes', Rule::in(self::STATUTS)],
            'visible_client' => 'sometimes|boolean',
        ]);

        if (array_key_exists('description', $validated)) {
            $validated['description'] = $validated['description'] ?? '';
        }

        $row->update($validated);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['event' => $row->id, 'champs' => array_keys($validated)])
            ->event('chantier-event-modifie')
            ->log("{$request->user()?->name} a modifié l'événement « {$row->titre} » du chantier de {$client->name}");

        return response()->json(['data' => ChantierEventData::from($row->refresh())]);
    }

    /**
     * DELETE /staff/chantiers/{client}/events/{event}
     */
    public function destroy(Request $request, Client $client, string $event): JsonResponse
    {
        $row = $this->findEvent($client, $event);
        $this->authorize('delete', $row);

        $titre = $row->titre;
        $row->delete();

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['event' => $event])
            ->event('chantier-event-supprime')
            ->log("{$request->user()?->name} a annulé l'événement « {$titre} » du chantier de {$client->name}");

        return response()->json(['message' => 'Événement supprimé.']);
    }

    // ─── Helpers ──────────────────────────────────────────────

    /**
     * Événement du chantier de CE dossier — 404 pour tout identifiant étranger.
     */
    private function findEvent(Client $client, string $id): ChantierEvent
    {
        // Sur PostgreSQL la clé est une vraie colonne `uuid` : comparer un
        // identifiant mal formé y déclenche une erreur SQL, pas un résultat vide.
        abort_unless(Str::isUuid($id), 404, 'Événement de chantier introuvable.');

        $chantier = $client->ensureChantier();

        /** @var ChantierEvent|null $row */
        $row = $chantier->events()->whereKey($id)->first();
        abort_if($row === null, 404, 'Événement de chantier introuvable.');

        return $row;
    }
}
