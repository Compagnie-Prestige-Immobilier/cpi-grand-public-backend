<?php

namespace App\Policies;

use App\Models\CpiDoc;
use App\Models\User;

class CpiDocPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view-cpi-docs');
    }

    public function view(User $user, CpiDoc $doc): bool
    {
        if ($user->hasAnyRole(['agent-cpi', 'super-admin'])) {
            return $user->hasPermissionTo('view-cpi-docs');
        }

        // Un client ne voit que ses documents rendus visibles.
        return $doc->visible_client && $user->id === $doc->client?->user_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create-cpi-docs');
    }

    public function update(User $user, CpiDoc $doc): bool
    {
        return $user->hasPermissionTo('create-cpi-docs');
    }

    /**
     * Suppression réservée à l'administrateur (aucune permission dédiée —
     * décision spec STEP 10.7 : « DELETE (admin only) »).
     */
    public function delete(User $user, CpiDoc $doc): bool
    {
        return $user->hasRole('super-admin');
    }

    public function publish(User $user, CpiDoc $doc): bool
    {
        return $user->hasPermissionTo('publish-cpi-docs');
    }

    public function archive(User $user, CpiDoc $doc): bool
    {
        return $user->hasPermissionTo('archive-cpi-docs');
    }

    /**
     * Signature par un agent CPI.
     */
    public function sign(User $user, CpiDoc $doc): bool
    {
        return $user->hasPermissionTo('sign-cpi-docs');
    }

    /**
     * Signature électronique par le client propriétaire.
     */
    public function signAsClient(User $user, CpiDoc $doc): bool
    {
        return $user->hasPermissionTo('sign-cpi-docs')
            && $doc->visible_client
            && $user->id === $doc->client?->user_id;
    }
}
