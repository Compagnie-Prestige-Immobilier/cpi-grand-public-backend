<?php

namespace App\Policies;

use App\Models\ChantierEvent;
use App\Models\User;

/**
 * Calendrier de chantier. Mêmes règles que les publications : lecture client
 * uniquement via GET /client/mon-chantier, gestion réservée au personnel.
 *
 * L'appartenance au bon dossier n'est pas décidée ici : le contrôleur résout
 * toujours l'événement DANS le chantier de {client} (404 sinon).
 */
class ChantierEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view-chantier');
    }

    public function view(User $user, ChantierEvent $event): bool
    {
        return $user->hasPermissionTo('view-chantier');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage-chantier');
    }

    public function update(User $user, ChantierEvent $event): bool
    {
        return $user->hasPermissionTo('manage-chantier');
    }

    public function delete(User $user, ChantierEvent $event): bool
    {
        return $user->hasPermissionTo('manage-chantier');
    }
}
