<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Squelette Phase 6 — les routes du STEP 9 existent dès maintenant,
 * chaque méthode répond 501 tant que sa phase n'est pas implémentée.
 */
class NotificationController extends Controller
{

    public function mine(): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté'], 501);
    }

    public function markRead(): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté'], 501);
    }

    public function send(): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté'], 501);
    }

    public function index(): JsonResponse
    {
        return response()->json(['message' => 'Non implémenté'], 501);
    }
}
