<?php

namespace App\Http\Controllers\Api;

use App\Dto\NotificationData;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifications applicatives (table `app_notifications`, STEP 10.11).
 *
 * Une notification est adressée à un dossier (`client_id`) et, quand ce dossier
 * a un compte de connexion, au compte lui-même (`user_id`) : la boîte du client
 * est donc l'union des deux, et la vérification d'appartenance porte sur les
 * deux colonnes (cf. NotificationPolicy). Le personnel CPI voit le flux
 * complet et est seul à pouvoir émettre.
 */
class NotificationController extends Controller
{
    // ─── Espace client ────────────────────────────────────────

    /**
     * GET /client/notifications — la boîte du client connecté, la plus
     * récente en tête. Ne peut structurellement contenir que ses propres
     * notifications : la requête est bornée à son dossier et à son compte.
     */
    public function mine(Request $request): JsonResponse
    {
        $client = $this->currentClient($request);
        $this->authorize('viewAny', Notification::class);

        $notifications = Notification::query()
            ->where(function ($query) use ($client, $request) {
                $query->where('client_id', $client->id)
                    ->orWhere('user_id', $request->user()?->id);
            })
            ->orderByDesc('date')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => NotificationData::collect($notifications)]);
    }

    /**
     * POST /client/notifications/{id}/read — marque une notification lue.
     *
     * `{id}` n'est pas résolu par model binding : un identifiant inconnu doit
     * faire 404, mais celui d'une notification appartenant à un AUTRE dossier
     * doit faire 403 — c'est la policy qui tranche, pas la requête.
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $this->currentClient($request);

        /** @var Notification|null $notification */
        $notification = Notification::query()->find($id);
        abort_if($notification === null, 404, 'Notification introuvable.');

        $this->authorize('markRead', $notification);

        $notification->update(['lu' => true]);

        return response()->json(['data' => NotificationData::from($notification->refresh())]);
    }

    // ─── Espace staff ─────────────────────────────────────────

    /**
     * GET /staff/notifications — flux complet, le plus récent en tête.
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Notification::class);

        $notifications = Notification::query()
            ->orderByDesc('date')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => NotificationData::collect($notifications)]);
    }

    /**
     * POST /staff/notifications/send — émet une notification vers un dossier.
     *
     * `date` et `heure` sont posées par le serveur (l'heure locale du poste
     * émetteur n'a rien à faire dans la boîte du destinataire) et l'activité
     * est journalisée sur le dossier concerné.
     */
    public function send(Request $request): JsonResponse
    {
        $this->authorize('send', Notification::class);

        $validated = $request->validate([
            'client_id' => 'required|uuid|exists:clients,id',
            'titre' => 'required|string|max:255',
            'message' => 'required|string|max:2000',
            'type' => 'required|string|max:50',
            'target_page' => 'nullable|string|max:255',
            'target_sub' => 'nullable|string|max:255',
        ]);

        /** @var Client $client */
        $client = Client::query()->findOrFail($validated['client_id']);

        $notification = Notification::create([
            'client_id' => $client->id,
            'user_id' => $client->user_id,
            'titre' => $validated['titre'],
            'message' => $validated['message'],
            'type' => $validated['type'],
            'target_page' => $validated['target_page'] ?? null,
            'target_sub' => $validated['target_sub'] ?? null,
            'date' => now(),
            'heure' => now()->format('H:i'),
            'lu' => false,
        ])->refresh();   // create() ne remonte pas le défaut SQL de `lu`

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['titre' => $notification->titre, 'type' => $notification->type])
            ->event('notification-envoyee')
            ->log("{$request->user()?->name} a envoyé une notification à {$client->name} : « {$notification->titre} »");

        return response()->json(['data' => NotificationData::from($notification)], 201);
    }

    // ─── Helpers ──────────────────────────────────────────────

    private function currentClient(Request $request): Client
    {
        $client = $request->user()?->client;
        abort_if($client === null, 404, 'Aucun dossier client associé à ce compte.');

        return $client;
    }
}
