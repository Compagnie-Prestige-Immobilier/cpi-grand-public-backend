<?php

namespace App\Policies;

use App\Models\ChantierPublication;
use App\Models\User;

/**
 * Publications du fil de chantier. Le client ne les lit qu'au travers de
 * GET /client/mon-chantier (autorisé par ChantierPolicy) : les quatre routes
 * de ce contrôleur sont réservées au personnel CPI.
 *
 * L'appartenance au bon dossier n'est pas décidée ici : le contrôleur résout
 * toujours la publication DANS le chantier de {client} (404 sinon).
 */
class ChantierPublicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view-chantier');
    }

    public function view(User $user, ChantierPublication $publication): bool
    {
        return $user->hasPermissionTo('view-chantier');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage-chantier');
    }

    public function update(User $user, ChantierPublication $publication): bool
    {
        return $user->hasPermissionTo('manage-chantier');
    }

    public function delete(User $user, ChantierPublication $publication): bool
    {
        return $user->hasPermissionTo('manage-chantier');
    }
}
