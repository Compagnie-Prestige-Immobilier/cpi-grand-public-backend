<?php

namespace App\Http\Controllers\Api\Chantier;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Squelette Phase 5 — les routes du STEP 9 existent dès maintenant,
 * chaque méthode répond 501 tant que sa phase n'est pas implémentée.
 */
class ChantierController extends Controller
{

    public function mine(): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté'], 501);
    }

    public function show(): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté'], 501);
    }

    public function update(): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté'], 501);
    }

    public function updateProgression(): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté'], 501);
    }

    public function updateEtape(): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté'], 501);
    }

    public function updateStatut(): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté'], 501);
    }

    public function validateTranche(): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté'], 501);
    }
}
