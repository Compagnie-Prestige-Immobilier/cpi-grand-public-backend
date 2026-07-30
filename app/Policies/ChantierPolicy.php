<?php

namespace App\Policies;

use App\Models\Chantier;
use App\Models\User;

/**
 * Chantier d'un dossier. Le rôle `client` détient `view-chantier` mais pas
 * `manage-chantier` : il consulte SON chantier (GET /client/mon-chantier) et
 * ne pilote rien. Le personnel CPI pilote tous les chantiers (routes /staff/*).
 */
class ChantierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view-chantier');
    }

    public function view(User $user, Chantier $chantier): bool
    {
        // Staff : tout chantier ; client : uniquement le sien.
        if ($user->hasAnyRole(['agent-cpi', 'super-admin'])) {
            return $user->hasPermissionTo('view-chantier');
        }

        return $user->hasPermissionTo('view-chantier')
            && $user->id === $chantier->client?->user_id;
    }

    public function update(User $user, Chantier $chantier): bool
    {
        return $user->hasPermissionTo('manage-chantier');
    }

    /**
     * Validation d'une tranche de construction (travaux terminés).
     */
    public function validate(User $user, Chantier $chantier): bool
    {
        return $user->hasPermissionTo('manage-chantier');
    }
}
