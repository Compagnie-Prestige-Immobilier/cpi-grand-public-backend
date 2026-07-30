<?php

namespace App\Policies;

use App\Models\Decaissement;
use App\Models\User;

/**
 * État de décaissement d'un dossier : acquisition foncière (décaissement
 * unique) puis construction par tranches. Le rôle `client` ne détient ni
 * `view-decaissements` ni `manage-decaissements` — le module est purement
 * interne (routes /staff/*).
 */
class DecaissementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view-decaissements');
    }

    public function view(User $user, Decaissement $decaissement): bool
    {
        return $user->hasPermissionTo('view-decaissements');
    }

    public function update(User $user, Decaissement $decaissement): bool
    {
        return $user->hasPermissionTo('manage-decaissements');
    }

    /**
     * Validation d'une étape : décaissement du terrain, étape foncière, tranche
     * de construction.
     */
    public function validate(User $user, Decaissement $decaissement): bool
    {
        return $user->hasPermissionTo('manage-decaissements');
    }
}
