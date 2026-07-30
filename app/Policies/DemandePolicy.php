<?php

namespace App\Policies;

use App\Models\Demande;
use App\Models\User;

class DemandePolicy
{
    public function view(User $user, Demande $demande): bool
    {
        // Le staff consulte toute demande ; un client uniquement la sienne.
        if ($user->hasAnyRole(['agent-cpi', 'super-admin'])) {
            return $user->hasPermissionTo('view-clients');
        }

        return $user->id === $demande->client?->user_id;
    }

    public function update(User $user, Demande $demande): bool
    {
        // Seul le client propriétaire modifie/soumet sa demande.
        return $user->id === $demande->client?->user_id;
    }
}
