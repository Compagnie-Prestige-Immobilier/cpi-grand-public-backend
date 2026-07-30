<?php

namespace App\Http\Controllers\Api;

use App\Dto\CpiDocData;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CpiDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class CpiDocController extends Controller
{
    // ─── Espace client ────────────────────────────────────────

    /**
     * GET /client/mes-documents-cpi — documents CPI visibles du client.
     */
    public function mine(Request $request): JsonResponse
    {
        $client = $this->currentClient($request);

        $docs = $client->cpiDocs()
            ->where('visible_client', true)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => CpiDocData::collect($docs)]);
    }

    /**
     * POST /client/mes-documents-cpi/{doc}/sign — signature électronique client.
     */
    public function signByClient(Request $request, CpiDoc $doc): JsonResponse
    {
        $this->authorize('signAsClient', $doc);

        $doc->update([
            'status' => 'signe',
            'signature_requise' => false,
            'date_publication' => now(),
        ]);

        activity()
            ->causedBy($request->user())
            ->performedOn($doc->client)
            ->withProperties(['cpi_doc_id' => $doc->id])
            ->event('cpi-doc-signe-client')
            ->log("{$request->user()?->name} a signé électroniquement le document {$doc->nom}");

        return response()->json(['data' => CpiDocData::from($doc->refresh())]);
    }

    // ─── Espace staff ─────────────────────────────────────────

    /**
     * GET /staff/cpi-docs — liste, filtrable par client_id
     * (filter[client_id]=… via spatie/laravel-query-builder, ou client_id=… direct).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CpiDoc::class);

        $docs = QueryBuilder::for(CpiDoc::class)
            ->allowedFilters(AllowedFilter::exact('client_id'))
            ->allowedSorts('created_at', 'nom', 'status')
            // Compat avec la forme du STEP 12.2 : ?client_id=… sans enveloppe filter[].
            ->when($request->query('client_id'), fn ($q, $clientId) => $q->where('client_id', $clientId))
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => CpiDocData::collect($docs)]);
    }

    /**
     * POST /staff/cpi-docs — création (brouillon par défaut).
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', CpiDoc::class);

        $validated = $request->validate([
            'client_id' => 'required|uuid|exists:clients,id',
            'categorie' => 'required|string|max:100',
            'nom' => 'required|string|max:255',
            'reference' => 'nullable|string|max:100',
            'version' => 'nullable|string|max:20',
            'commentaire' => 'nullable|string|max:2000',
            'fichier' => 'nullable|string|max:255',
            'visible_client' => 'sometimes|boolean',
            'signature_requise' => 'sometimes|boolean',
            'taille' => 'nullable|string|max:20',
            'format' => 'nullable|string|max:20',
        ]);

        $doc = CpiDoc::create([
            ...collect($validated)->filter(fn ($v) => $v !== null)->all(),
            'auteur' => $request->user()->name,
            'date_creation' => now(),
        ])->refresh();

        activity()
            ->causedBy($request->user())
            ->performedOn($doc->client)
            ->withProperties(['cpi_doc_id' => $doc->id])
            ->event('cpi-doc-cree')
            ->log("{$request->user()?->name} a créé le document {$doc->nom}");

        return response()->json(['data' => CpiDocData::from($doc)], 201);
    }

    /**
     * PUT /staff/cpi-docs/{cpiDoc} — mise à jour des champs.
     */
    public function update(Request $request, CpiDoc $cpiDoc): JsonResponse
    {
        $this->authorize('update', $cpiDoc);

        $validated = $request->validate([
            'categorie' => 'sometimes|string|max:100',
            'nom' => 'sometimes|string|max:255',
            'reference' => 'sometimes|nullable|string|max:100',
            'version' => 'sometimes|string|max:20',
            'commentaire' => 'sometimes|nullable|string|max:2000',
            'fichier' => 'sometimes|nullable|string|max:255',
            'visible_client' => 'sometimes|boolean',
            'signature_requise' => 'sometimes|boolean',
            'taille' => 'sometimes|nullable|string|max:20',
            'format' => 'sometimes|nullable|string|max:20',
        ]);

        $cpiDoc->update($validated);

        return response()->json(['data' => CpiDocData::from($cpiDoc->refresh())]);
    }

    /**
     * DELETE /staff/cpi-docs/{cpiDoc} — réservé au super-admin.
     */
    public function destroy(Request $request, CpiDoc $cpiDoc): JsonResponse
    {
        $this->authorize('delete', $cpiDoc);

        activity()
            ->causedBy($request->user())
            ->performedOn($cpiDoc->client)
            ->event('cpi-doc-supprime')
            ->log("{$request->user()?->name} a supprimé le document {$cpiDoc->nom}");

        $cpiDoc->delete();

        return response()->json(['message' => 'Document supprimé.']);
    }

    /**
     * POST /staff/cpi-docs/{cpiDoc}/publish — publication vers le client.
     */
    public function publish(Request $request, CpiDoc $cpiDoc): JsonResponse
    {
        $this->authorize('publish', $cpiDoc);

        $cpiDoc->update([
            'status' => $cpiDoc->signature_requise ? 'a-signer' : 'disponible',
            'visible_client' => true,
            'date_publication' => now(),
        ]);

        activity()
            ->causedBy($request->user())
            ->performedOn($cpiDoc->client)
            ->withProperties(['cpi_doc_id' => $cpiDoc->id])
            ->event('cpi-doc-publie')
            ->log("{$request->user()?->name} a publié le document {$cpiDoc->nom}");

        return response()->json(['data' => CpiDocData::from($cpiDoc->refresh())]);
    }

    /**
     * POST /staff/cpi-docs/{cpiDoc}/archive.
     */
    public function archive(Request $request, CpiDoc $cpiDoc): JsonResponse
    {
        $this->authorize('archive', $cpiDoc);

        $cpiDoc->update([
            'status' => 'archive',
            'visible_client' => false,
        ]);

        activity()
            ->causedBy($request->user())
            ->performedOn($cpiDoc->client)
            ->withProperties(['cpi_doc_id' => $cpiDoc->id])
            ->event('cpi-doc-archive')
            ->log("{$request->user()?->name} a archivé le document {$cpiDoc->nom}");

        return response()->json(['data' => CpiDocData::from($cpiDoc->refresh())]);
    }

    /**
     * POST /staff/cpi-docs/{cpiDoc}/sign — signature par un agent CPI.
     */
    public function sign(Request $request, CpiDoc $cpiDoc): JsonResponse
    {
        $this->authorize('sign', $cpiDoc);

        $cpiDoc->update([
            'status' => 'signe',
            'signature_requise' => false,
            'date_publication' => now(),
        ]);

        activity()
            ->causedBy($request->user())
            ->performedOn($cpiDoc->client)
            ->withProperties(['cpi_doc_id' => $cpiDoc->id])
            ->event('cpi-doc-signe')
            ->log("{$request->user()?->name} a marqué le document {$cpiDoc->nom} comme signé");

        return response()->json(['data' => CpiDocData::from($cpiDoc->refresh())]);
    }

    // ─── Helpers ──────────────────────────────────────────────

    private function currentClient(Request $request): Client
    {
        $client = $request->user()?->client;
        abort_if($client === null, 404, 'Aucun dossier client associé à ce compte.');

        return $client;
    }
}
