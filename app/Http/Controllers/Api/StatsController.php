<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Squelette Phase 6 — les routes du STEP 9 existent dès maintenant,
 * chaque méthode répond 501 tant que sa phase n'est pas implémentée.
 */
class StatsController extends Controller
{

    public function dashboard(): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté'], 501);
    }

    public function agent(): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté'], 501);
    }

    public function admin(): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté'], 501);
    }
}
