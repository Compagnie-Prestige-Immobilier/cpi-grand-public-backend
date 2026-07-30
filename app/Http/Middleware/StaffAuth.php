<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class StaffAuth
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user || !$user->hasAnyRole(['agent-cpi', 'super-admin'])) {
            return response()->json(['message' => 'Accès personnel CPI uniquement.'], 403);
        }
        return $next($request);
    }
}
