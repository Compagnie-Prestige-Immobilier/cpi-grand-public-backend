<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * Journal d'activité (Spatie Activity Log) — module purement interne
 * (routes /staff/historique*). Il n'existe pas de permission dédiée dans le
 * seeder : le journal retrace la vie des DOSSIERS, on le calque donc sur
 * `view-clients`, détenue par agent-cpi et super-admin et par eux seuls.
 * Aucune écriture par l'API : les entrées naissent des mutations métier.
 */
class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view-clients');
    }

    public function view(User $user, Activity $activity): bool
    {
        return $user->hasPermissionTo('view-clients');
    }
}
