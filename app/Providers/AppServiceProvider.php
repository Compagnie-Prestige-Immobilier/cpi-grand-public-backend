<?php

namespace App\Providers;

use App\Models\Bank;
use App\Models\BankAssignment;
use App\Models\Chantier;
use App\Models\ChantierEvent;
use App\Models\ChantierMedia;
use App\Models\ChantierPublication;
use App\Models\Client;
use App\Models\CpiDoc;
use App\Models\Decaissement;
use App\Models\Demande;
use App\Models\Notification;
use App\Models\RequisDoc;
use App\Policies\ActivityPolicy;
use App\Policies\BankAssignmentPolicy;
use App\Policies\BankPolicy;
use App\Policies\ChantierEventPolicy;
use App\Policies\ChantierMediaPolicy;
use App\Policies\ChantierPolicy;
use App\Policies\ChantierPublicationPolicy;
use App\Policies\ClientPolicy;
use App\Policies\CpiDocPolicy;
use App\Policies\DecaissementPolicy;
use App\Policies\DemandePolicy;
use App\Policies\NotificationPolicy;
use App\Policies\RequisDocPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerRateLimiters();
        $this->registerPasswordPolicy();
        $this->registerTrustedProxies();

        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Demande::class, DemandePolicy::class);
        Gate::policy(RequisDoc::class, RequisDocPolicy::class);
        Gate::policy(CpiDoc::class, CpiDocPolicy::class);
        Gate::policy(Bank::class, BankPolicy::class);
        Gate::policy(BankAssignment::class, BankAssignmentPolicy::class);
        Gate::policy(Decaissement::class, DecaissementPolicy::class);
        Gate::policy(Chantier::class, ChantierPolicy::class);
        Gate::policy(ChantierPublication::class, ChantierPublicationPolicy::class);
        Gate::policy(ChantierMedia::class, ChantierMediaPolicy::class);
        Gate::policy(ChantierEvent::class, ChantierEventPolicy::class);
        Gate::policy(Notification::class, NotificationPolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);
    }

    /**
     * Proxys de confiance.
     *
     * `TRUSTED_PROXIES` était documenté dans docker.env.example mais consommé
     * par rien : Laravel prenait l'adresse du proxy TLS de l'hôte pour celle du
     * client — tous les limiteurs de débit auraient partagé le même seau — et
     * considérait chaque requête comme non chiffrée.
     *
     * Déclaré ici et non dans bootstrap/app.php : la configuration n'y est pas
     * encore liée au conteneur, et `env()` ne répond plus après
     * `php artisan optimize` (que l'entrypoint exécute au démarrage).
     */
    private function registerTrustedProxies(): void
    {
        $proxies = trim((string) config('proxies.trusted'));

        if ($proxies === '') {
            return;
        }

        TrustProxies::at($proxies === '*'
            ? '*'
            : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
    }

    /**
     * Politique de mot de passe.
     *
     * `min:8` seul, sans aucune règle de composition : « 12345678 » passait.
     * `uncompromised()` interroge Have I Been Pwned et n'a donc de sens qu'en
     * production — en test il rendrait la suite dépendante du réseau.
     */
    private function registerPasswordPolicy(): void
    {
        Password::defaults(function (): Password {
            $regle = Password::min(10)->letters()->numbers();

            return app()->isProduction() ? $regle->uncompromised() : $regle;
        });
    }

    /**
     * Limiteurs de débit de l'API.
     *
     * `api` est consommé par `throttleApi()` (bootstrap/app.php) et couvre tout
     * le groupe api. Les trois autres se posent en plus sur les routes qu'un
     * attaquant a intérêt à marteler : authentification, création de compte et
     * envoi de courriel.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('rate_limits.api'))
            ->by($request->user()?->id ?: $request->ip()));

        // Par couple IP + email : un attaquant ne peut pas balayer les comptes
        // depuis une seule adresse, et un client derrière une IP partagée n'est
        // pas bloqué par les erreurs de son voisin.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(config('rate_limits.login'))
            ->by($request->ip().'|'.Str::lower((string) $request->input('email'))));

        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(config('rate_limits.register'))
            ->by($request->ip()));

        RateLimiter::for('support', fn (Request $request) => Limit::perMinute(config('rate_limits.support'))
            ->by($request->user()?->id ?: $request->ip()));
    }
}
