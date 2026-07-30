<?php

namespace App\Http\Controllers\Api\Chantier;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Squelette Phase 5 — les routes du STEP 9 existent dès maintenant,
 * chaque méthode répond 501 tant que sa phase n'est pas implémentée.
 */
class ChantierPublicationController extends Controller
{

    public function index(): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté'], 501);
    }

    public function store(): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté'], 501);
    }

    public function update(): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté'], 501);
    }

    public function destroy(): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté'], 501);
    }
}
