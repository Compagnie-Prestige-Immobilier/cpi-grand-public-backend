<?php

namespace App\Http\Controllers\Api;

use App\Dto\RequisDocData;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\RequisDoc;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

class DocController extends Controller
{
    public function __construct(private readonly StorageService $storage) {}

    // ─── Espace client ────────────────────────────────────────

    /**
     * GET /client/mes-documents — les pièces requises du client connecté.
     */
    public function mine(Request $request): JsonResponse
    {
        $client = $this->currentClient($request);
        $docs = $this->ensureRequiredDocs($client);

        return response()->json(['data' => RequisDocData::collect($docs)]);
    }

    /**
     * POST /client/mes-documents/{doc}/deposit — dépôt d'une pièce (multipart).
     */
    public function depositMine(Request $request, string $doc): JsonResponse
    {
        $client = $this->currentClient($request);
        $this->ensureRequiredDocs($client);
        $docRow = $this->findDoc($client, $doc);
        $this->authorize('deposit', $docRow);

        $validated = $request->validate([
            'file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,webp',
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $version = $docRow->version + 1;
        $path = $this->storage->uploadDoc($client->id, $docRow->doc_id, $file, $version);

        $docRow->update([
            'status' => 'depose',
            'version' => $version,
            'file_path' => $path,
            'submitted_label' => $file->getClientOriginalName(),
            'date' => now()->format('d/m/Y'),
            'taille' => $this->humanSize($file->getSize()),
            'commentaire' => null,
            'agent_name' => null,
            'date_validation' => null,
        ]);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['doc_id' => $docRow->doc_id, 'version' => $version])
            ->event('doc-depose')
            ->log("{$client->name} a déposé le document {$docRow->label}");

        return response()->json(['data' => RequisDocData::from($docRow->refresh())]);
    }

    // ─── Espace staff ─────────────────────────────────────────

    /**
     * GET /staff/clients/{client}/docs — pièces d'un client.
     */
    public function index(Client $client): JsonResponse
    {
        $this->authorize('viewAny', RequisDoc::class);
        $docs = $this->ensureRequiredDocs($client);

        return response()->json(['data' => RequisDocData::collect($docs)]);
    }

    /**
     * POST .../docs/{doc}/accept — valide la pièce.
     */
    public function accept(Request $request, Client $client, string $doc): JsonResponse
    {
        $docRow = $this->findDoc($client, $doc);
        $this->authorize('validate', $docRow);

        $docRow->update([
            'status' => 'accepte',
            'date_validation' => now(),
            'agent_name' => $request->user()?->name,
            'commentaire' => null,
        ]);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['action' => 'doc_accept', 'doc_id' => $docRow->doc_id])
            ->event('validated')
            ->log("{$request->user()?->name} a validé le document {$docRow->label} pour {$client->name}");

        return response()->json(['data' => RequisDocData::from($docRow->refresh())]);
    }

    /**
     * POST .../docs/{doc}/refuse — refuse la pièce (commentaire requis).
     */
    public function refuse(Request $request, Client $client, string $doc): JsonResponse
    {
        $docRow = $this->findDoc($client, $doc);
        $this->authorize('validate', $docRow);

        $validated = $request->validate(['comment' => 'required|string|max:2000']);

        $docRow->update([
            'status' => 'refuse',
            'commentaire' => $validated['comment'],
            'agent_name' => $request->user()?->name,
            'date_validation' => null,
        ]);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['action' => 'doc_refuse', 'doc_id' => $docRow->doc_id])
            ->event('refused')
            ->log("{$request->user()?->name} a refusé le document {$docRow->label} pour {$client->name}");

        return response()->json(['data' => RequisDocData::from($docRow->refresh())]);
    }

    /**
     * POST .../docs/{doc}/replace — demande de remplacement (commentaire requis).
     */
    public function requestReplacement(Request $request, Client $client, string $doc): JsonResponse
    {
        $docRow = $this->findDoc($client, $doc);
        $this->authorize('validate', $docRow);

        $validated = $request->validate(['comment' => 'required|string|max:2000']);

        $docRow->update([
            'status' => 'a-remplacer',
            'commentaire' => $validated['comment'],
            'agent_name' => $request->user()?->name,
            'date_validation' => null,
        ]);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['action' => 'doc_replace', 'doc_id' => $docRow->doc_id])
            ->event('replacement-requested')
            ->log("{$request->user()?->name} a demandé le remplacement du document {$docRow->label} pour {$client->name}");

        return response()->json(['data' => RequisDocData::from($docRow->refresh())]);
    }

    /**
     * POST .../docs/{doc}/verify — remet la pièce en vérification.
     */
    public function remettreVerification(Request $request, Client $client, string $doc): JsonResponse
    {
        $docRow = $this->findDoc($client, $doc);
        $this->authorize('validate', $docRow);

        $docRow->update([
            'status' => 'verification',
            'agent_name' => $request->user()?->name,
            'date_validation' => null,
        ]);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['action' => 'doc_verify', 'doc_id' => $docRow->doc_id])
            ->event('verification')
            ->log("{$request->user()?->name} a remis le document {$docRow->label} en vérification pour {$client->name}");

        return response()->json(['data' => RequisDocData::from($docRow->refresh())]);
    }

    // ─── Helpers ──────────────────────────────────────────────

    private function currentClient(Request $request): Client
    {
        $client = $request->user()?->client;
        abort_if($client === null, 404, 'Aucun dossier client associé à ce compte.');

        return $client;
    }

    /**
     * Rattrapage pour les dossiers antérieurs à la création automatique des
     * pièces requises (voir Client::booted).
     *
     * @return Collection<int, RequisDoc>
     */
    private function ensureRequiredDocs(Client $client)
    {
        return $client->ensureRequiredDocs();
    }

    private function findDoc(Client $client, string $docId): RequisDoc
    {
        $doc = $client->requisDocs()->where('doc_id', $docId)->first();

        // Dossier créé avant la génération automatique : on rattrape puis on relit.
        if ($doc === null && array_key_exists($docId, RequisDoc::REQUIRED)) {
            $client->ensureRequiredDocs();
            $doc = $client->requisDocs()->where('doc_id', $docId)->first();
        }

        abort_if($doc === null, 404, 'Document requis introuvable.');

        return $doc;
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', ' ').' Mo';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0, ',', ' ').' Ko';
        }

        return $bytes.' o';
    }
}
