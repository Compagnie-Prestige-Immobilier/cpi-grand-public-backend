<?php

namespace App\Http\Controllers\Api;

use App\Dto\DemandeData;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Demande;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DemandeController extends Controller
{
    /**
     * GET /client/ma-demande — la demande du client connecté (ou null).
     */
    public function mine(Request $request): JsonResponse
    {
        $client = $this->currentClient($request);
        $demande = $client->demande;

        return response()->json(['data' => $demande ? DemandeData::from($demande) : null]);
    }

    /**
     * POST /client/ma-demande — crée ou met à jour la demande du client.
     */
    public function saveMine(Request $request): JsonResponse
    {
        $client = $this->currentClient($request);

        $validated = $request->validate([
            'type_projet' => 'sometimes|string|max:100',
            'nature_projet' => 'sometimes|string|max:100',
            'montant' => 'sometimes|nullable|numeric|min:0',
            'duree' => 'sometimes|string|max:10',
            'apport' => 'sometimes|numeric|min:0',
            'region' => 'sometimes|string|max:100',
            'commune' => 'sometimes|nullable|string|max:150',
            'adresse_projet' => 'sometimes|nullable|string|max:255',
            'description' => 'sometimes|nullable|string|max:5000',
        ]);

        $demande = Demande::updateOrCreate(['client_id' => $client->id], $validated)->refresh();

        return response()->json(['data' => DemandeData::from($demande)]);
    }

    /**
     * POST /client/ma-demande/submit — soumet la demande + journal.
     */
    public function submitMine(Request $request): JsonResponse
    {
        $client = $this->currentClient($request);
        $demande = $client->demande;
        abort_if($demande === null, 404, 'Aucune demande à soumettre.');

        $this->authorize('update', $demande);

        $demande->update([
            'submitted' => true,
            'submitted_at' => now(),
        ]);

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->event('demande-soumise')
            ->log("{$client->name} a soumis sa demande de financement");

        return response()->json(['data' => DemandeData::from($demande->refresh())]);
    }

    private function currentClient(Request $request): Client
    {
        $client = $request->user()?->client;
        abort_if($client === null, 404, 'Aucun dossier client associé à ce compte.');

        return $client;
    }
}
