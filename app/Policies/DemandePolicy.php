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

    /**
     * Correction d'une demande par le personnel CPI, y compris après
     * verrouillage du dossier. Distincte de `update` : celle-ci est réservée au
     * client propriétaire, celle-là au staff habilité à écrire dans un dossier.
     */
    public function updateAsStaff(User $user, Demande $demande): bool
    {
        return $user->hasAnyRole(['agent-cpi', 'super-admin'])
            && $user->hasPermissionTo('edit-client');
    }
}
