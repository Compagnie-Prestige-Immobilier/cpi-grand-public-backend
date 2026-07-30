<?php

namespace App\Http\Controllers\Api\Chantier;

use App\Dto\ChantierMediaData;
use App\Http\Controllers\Controller;
use App\Models\ChantierMedia;
use App\Models\Client;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Photos / vidéos d'un chantier — routes /staff/chantiers/{client}/medias.
 *
 * Le fichier part sur le bucket R2 PRIVÉ via StorageService : la colonne `url`
 * ne contient qu'une clé de stockage, jamais un lien public. L'API ne renvoie
 * que `fileUrl`, une URL signée de courte durée régénérée à chaque lecture
 * (cf. ChantierMediaData).
 *
 * Tout média est résolu DANS le chantier de {client} : un identifiant
 * appartenant au chantier d'un autre dossier donne 404.
 */
class ChantierMediaController extends Controller
{
    public function __construct(private readonly StorageService $storage)
    {
    }

    /**
     * GET /staff/chantiers/{client}/medias
     */
    public function index(Client $client): JsonResponse
    {
        $this->authorize('viewAny', ChantierMedia::class);
        $chantier = $client->ensureChantier();

        return response()->json([
            'data' => ChantierMediaData::collect($chantier->medias()->get()),
        ]);
    }

    /**
     * POST /staff/chantiers/{client}/medias — dépôt d'une photo/vidéo
     * (multipart, champ `file`).
     */
    public function store(Request $request, Client $client): JsonResponse
    {
        $this->authorize('create', ChantierMedia::class);
        $chantier = $client->ensureChantier();

        $validated = $request->validate([
            'file' => 'required|file|max:51200|mimes:jpg,jpeg,png,webp,mp4,webm',
            'type' => ['required', Rule::in(['photo', 'video'])],
            'titre' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'phase' => 'required|integer|min:0|max:10',
            'bg' => 'nullable|string|max:255',
            'visible_client' => 'sometimes|boolean',
            'date' => 'sometimes|nullable|date',
        ]);

        // L'identifiant est tiré AVANT l'envoi : le chemin R2 le contient, et un
        // stockage en échec (503) ne doit laisser aucune ligne orpheline.
        $media = new ChantierMedia();
        $id = $media->newUniqueId();

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];
        $path = $this->storage->uploadChantierMedia($client->id, $id, $file);

        $media->forceFill([
            'id' => $id,
            'chantier_id' => $chantier->id,
            'type' => $validated['type'],
            'titre' => $validated['titre'],
            'description' => $validated['description'] ?? null,
            'phase' => $validated['phase'],
            'bg' => $validated['bg'] ?? null,
            'visible_client' => $validated['visible_client'] ?? true,
            'date' => $validated['date'] ?? now(),
            // Route derrière auth:sanctum : l'utilisateur est toujours présent.
            'auteur' => $request->user()->name,
            'url' => $path,
        ])->save();

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['media' => $id, 'type' => $media->type])
            ->event('chantier-media-ajoute')
            ->log("{$request->user()?->name} a ajouté le média « {$media->titre} » au chantier de {$client->name}");

        return response()->json(['data' => ChantierMediaData::from($media->refresh())], 201);
    }

    /**
     * PUT /staff/chantiers/{client}/medias/{media} — métadonnées seulement.
     * Remplacer le fichier revient à déposer un nouveau média.
     */
    public function update(Request $request, Client $client, string $media): JsonResponse
    {
        $row = $this->findMedia($client, $media);
        $this->authorize('update', $row);

        $validated = $request->validate([
            'type' => ['sometimes', Rule::in(['photo', 'video'])],
            'titre' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string|max:5000',
            'phase' => 'sometimes|integer|min:0|max:10',
            'bg' => 'sometimes|nullable|string|max:255',
            'visible_client' => 'sometimes|boolean',
            'date' => 'sometimes|nullable|date',
        ]);

        $row->update($validated);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['media' => $row->id, 'champs' => array_keys($validated)])
            ->event('chantier-media-modifie')
            ->log("{$request->user()?->name} a modifié le média « {$row->titre} » du chantier de {$client->name}");

        return response()->json(['data' => ChantierMediaData::from($row->refresh())]);
    }

    /**
     * DELETE /staff/chantiers/{client}/medias/{media} — la ligne ET le fichier.
     */
    public function destroy(Request $request, Client $client, string $media): JsonResponse
    {
        $row = $this->findMedia($client, $media);
        $this->authorize('delete', $row);

        $titre = $row->titre;
        $path = $row->url;
        $row->delete();

        if ($path !== '') {
            $this->storage->delete($path);
        }

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['media' => $media])
            ->event('chantier-media-supprime')
            ->log("{$request->user()?->name} a supprimé le média « {$titre} » du chantier de {$client->name}");

        return response()->json(['message' => 'Média supprimé.']);
    }

    // ─── Helpers ──────────────────────────────────────────────

    /**
     * Média du chantier de CE dossier — 404 pour tout identifiant étranger.
     */
    private function findMedia(Client $client, string $id): ChantierMedia
    {
        // Sur PostgreSQL la clé est une vraie colonne `uuid` : comparer un
        // identifiant mal formé y déclenche une erreur SQL, pas un résultat vide.
        abort_unless(Str::isUuid($id), 404, 'Média de chantier introuvable.');

        $chantier = $client->ensureChantier();

        /** @var ChantierMedia|null $row */
        $row = $chantier->medias()->whereKey($id)->first();
        abort_if($row === null, 404, 'Média de chantier introuvable.');

        return $row;
    }
}
