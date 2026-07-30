<?php

namespace App\Policies;

use App\Models\RequisDoc;
use App\Models\User;

class RequisDocPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view-documents');
    }

    public function view(User $user, RequisDoc $doc): bool
    {
        // Staff : tout document ; client : uniquement les siens.
        if ($user->hasAnyRole(['agent-cpi', 'super-admin'])) {
            return $user->hasPermissionTo('view-documents');
        }

        return $user->id === $doc->client?->user_id;
    }

    /**
     * Dépôt d'un document par le client propriétaire.
     */
    public function deposit(User $user, RequisDoc $doc): bool
    {
        return $user->hasPermissionTo('upload-documents')
            && $user->id === $doc->client?->user_id;
    }

    /**
     * Validation / refus / remplacement / remise en vérification (staff).
     */
    public function validate(User $user, RequisDoc $doc): bool
    {
        return $user->hasPermissionTo('validate-documents');
    }
}
