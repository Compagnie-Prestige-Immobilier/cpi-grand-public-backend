<?php

namespace App\Http\Controllers\Api;

use App\Dto\CpiDocData;
use App\Enums\CpiDocStatut;
use App\Http\Controllers\Concerns\ResoudLeDossierDuClient;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CpiDoc;
use App\Services\NotifieLeClient;
use App\Services\StorageService;
use App\Support\BorneListe;
use App\Support\TransitionStatut;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Number;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class CpiDocController extends Controller
{
    use ResoudLeDossierDuClient;

    public function __construct(
        private readonly StorageService $storage,
        private readonly NotifieLeClient $notifie,
    ) {}

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
            // Borne anti-déni de service : voir App\Support\BorneListe.
            ->limit(BorneListe::MAX)
            ->get();

        return response()->json(['data' => CpiDocData::collect($docs)]);
    }

    /**
     * POST /client/mes-documents-cpi/{doc}/sign — signature électronique client.
     */
    public function signByClient(Request $request, CpiDoc $doc): JsonResponse
    {
        $this->authorize('signAsClient', $doc);

        TransitionStatut::verifier($doc->status, CpiDocStatut::Signe, 'Signature du document');

        $doc->update([
            'status' => CpiDocStatut::Signe,
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
            ...array_filter($validated, fn ($v) => $v !== null),
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
     * POST /staff/cpi-docs/{cpiDoc}/upload — dépôt du fichier réel (multipart).
     *
     * `fichier` reste le libellé affiché ; le fichier vit sur R2 (privé) sous
     * cpi-docs/{clientId}/{docId}.{ext} et n'est servi que via lien signé.
     */
    public function upload(Request $request, CpiDoc $cpiDoc): JsonResponse
    {
        $this->authorize('update', $cpiDoc);

        $validated = $request->validate([
            'file' => 'required|file|max:10240|mimes:pdf,doc,docx,jpg,jpeg,png,webp',
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];

        // Remplacement : l'ancien fichier ne doit pas rester orphelin sur R2
        // (l'extension peut changer, donc le chemin aussi).
        if ($cpiDoc->file_path !== null) {
            $this->storage->delete($cpiDoc->file_path);
        }

        $path = $this->storage->uploadCpiDoc($cpiDoc->client_id, $cpiDoc->id, $file);

        $cpiDoc->update([
            'file_path' => $path,
            'fichier' => $file->getClientOriginalName(),
            'taille' => Number::fileSize($file->getSize()),
            'format' => strtoupper($file->getClientOriginalExtension()),
        ]);

        activity()
            ->causedBy($request->user())
            ->performedOn($cpiDoc->client)
            ->withProperties(['cpi_doc_id' => $cpiDoc->id])
            ->event('cpi-doc-fichier')
            ->log("{$request->user()?->name} a joint un fichier au document {$cpiDoc->nom}");

        return response()->json(['data' => CpiDocData::from($cpiDoc->refresh())]);
    }

    /**
     * DELETE /staff/cpi-docs/{cpiDoc} — réservé au super-admin.
     */
    public function destroy(Request $request, CpiDoc $cpiDoc): JsonResponse
    {
        $this->authorize('delete', $cpiDoc);

        if ($cpiDoc->file_path !== null) {
            $this->storage->delete($cpiDoc->file_path);
        }

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

        TransitionStatut::verifier(
            $cpiDoc->status,
            $cpiDoc->signature_requise ? CpiDocStatut::ASigner : CpiDocStatut::Disponible,
            'Publication du document',
        );

        $cpiDoc->update([
            'status' => $cpiDoc->signature_requise ? CpiDocStatut::ASigner : CpiDocStatut::Disponible,
            'visible_client' => true,
            'date_publication' => now(),
        ]);

        activity()
            ->causedBy($request->user())
            ->performedOn($cpiDoc->client)
            ->withProperties(['cpi_doc_id' => $cpiDoc->id])
            ->event('cpi-doc-publie')
            ->log("{$request->user()?->name} a publié le document {$cpiDoc->nom}");

        // Un contrat mis à disposition — a fortiori à signer — sans que le
        // client en soit averti reste invisible jusqu'à sa prochaine visite.
        if ($cpiDoc->client !== null) {
            $cpiDoc->signature_requise
                ? $this->notifie->documentASigner($cpiDoc->client, $cpiDoc->nom)
                : $this->notifie->documentDisponible($cpiDoc->client, $cpiDoc->nom);
        }

        return response()->json(['data' => CpiDocData::from($cpiDoc->refresh())]);
    }

    /**
     * POST /staff/cpi-docs/{cpiDoc}/archive.
     */
    public function archive(Request $request, CpiDoc $cpiDoc): JsonResponse
    {
        $this->authorize('archive', $cpiDoc);

        TransitionStatut::verifier($cpiDoc->status, CpiDocStatut::Archive, 'Archivage du document');

        $cpiDoc->update([
            'status' => CpiDocStatut::Archive,
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

        TransitionStatut::verifier($cpiDoc->status, CpiDocStatut::Signe, 'Signature du document');

        $cpiDoc->update([
            'status' => CpiDocStatut::Signe,
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

}
