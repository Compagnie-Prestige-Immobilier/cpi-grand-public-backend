<?php

use App\Http\Middleware\ClientAuth;
use App\Http\Middleware\StaffAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'client' => ClientAuth::class,
            'staff' => StaffAuth::class,
        ]);

        // Applique `throttle:api` à tout le groupe api. Sans cet appel, le
        // squelette Laravel 11+ ne pose AUCUNE limite de débit : bourrage
        // d'identifiants illimité sur /auth/login, création de comptes en masse
        // sur /auth/register, et inondation de la boîte support (qui envoie de
        // vrais courriels). Le limiteur `api` est défini dans AppServiceProvider.
        $middleware->throttleApi();

        // Backend purement API : aucune route `login` nommée. Sans ceci le
        // squelette Laravel évalue `route('login')` dès qu'un invité arrive sans
        // en-tête Accept: application/json — 500 au lieu de 401.
        $middleware->redirectGuestsTo(null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
