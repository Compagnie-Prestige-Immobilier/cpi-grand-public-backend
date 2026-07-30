<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ClientAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user() || ! $request->user()->hasRole('client')) {
            return response()->json(['message' => 'Accès client uniquement.'], 403);
        }

        return $next($request);
    }
}
