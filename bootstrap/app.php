<?php

use App\Http\Middleware\ClientAuth;
use App\Http\Middleware\CompteValide;
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
            'compte.valide' => CompteValide::class,
            'staff' => StaffAuth::class,
        ]);

        // Le conteneur n'écoute que sur 127.0.0.1 (docker-compose.yml) : seul
        // le reverse proxy du VPS (TLS, Traefik) peut lui parler, jamais
        // Internet directement. Sans cette ligne, Laravel ignore les en-têtes
        // `X-Forwarded-Proto`/`-Host` de ce proxy et croit chaque requête
        // reçue en clair — un lien signé généré pendant la requête (courriel
        // de vérification, entre autres) sort alors en `http://` au lieu de
        // `https://`, même quand le certificat public est valide.
        $middleware->trustProxies(at: '*');

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
