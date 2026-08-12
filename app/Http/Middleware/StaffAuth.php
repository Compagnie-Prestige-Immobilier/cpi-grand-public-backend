<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StaffAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->hasAnyRole(['agent-cpi', 'super-admin'])) {
            return response()->json(['message' => 'Accès personnel CPI uniquement.'], 403);
        }

        return $next($request);
    }
}
