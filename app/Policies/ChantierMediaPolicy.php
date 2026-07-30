<?php

namespace App\Policies;

use App\Models\ChantierMedia;
use App\Models\User;

/**
 * Photos / vidéos de chantier. Mêmes règles que les publications : lecture
 * client uniquement via GET /client/mon-chantier, gestion réservée au personnel.
 *
 * L'appartenance au bon dossier n'est pas décidée ici : le contrôleur résout
 * toujours le média DANS le chantier de {client} (404 sinon).
 */
class ChantierMediaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view-chantier');
    }

    public function view(User $user, ChantierMedia $media): bool
    {
        return $user->hasPermissionTo('view-chantier');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage-chantier');
    }

    public function update(User $user, ChantierMedia $media): bool
    {
        return $user->hasPermissionTo('manage-chantier');
    }

    public function delete(User $user, ChantierMedia $media): bool
    {
        return $user->hasPermissionTo('manage-chantier');
    }
}
