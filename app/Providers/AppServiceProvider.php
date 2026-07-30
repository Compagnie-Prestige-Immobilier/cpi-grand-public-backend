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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
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
}
