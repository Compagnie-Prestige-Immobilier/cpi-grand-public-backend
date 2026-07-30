<?php

namespace App\Providers;

use App\Models\Bank;
use App\Models\BankAssignment;
use App\Models\Client;
use App\Models\CpiDoc;
use App\Models\Decaissement;
use App\Models\Demande;
use App\Models\RequisDoc;
use App\Policies\BankAssignmentPolicy;
use App\Policies\BankPolicy;
use App\Policies\ClientPolicy;
use App\Policies\CpiDocPolicy;
use App\Policies\DecaissementPolicy;
use App\Policies\DemandePolicy;
use App\Policies\RequisDocPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Demande::class, DemandePolicy::class);
        Gate::policy(RequisDoc::class, RequisDocPolicy::class);
        Gate::policy(CpiDoc::class, CpiDocPolicy::class);
        Gate::policy(Bank::class, BankPolicy::class);
        Gate::policy(BankAssignment::class, BankAssignmentPolicy::class);
        Gate::policy(Decaissement::class, DecaissementPolicy::class);
    }
}
