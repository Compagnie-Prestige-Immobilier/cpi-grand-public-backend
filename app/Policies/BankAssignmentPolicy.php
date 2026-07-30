<?php

namespace App\Policies;

use App\Models\BankAssignment;
use App\Models\User;

/**
 * Orientation d'un dossier vers une banque. Toutes les écritures passent par
 * `assign-bank` (agent CPI et administrateur) ; le client ne dispose que de la
 * lecture de SES propres orientations.
 */
class BankAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view-banks');
    }

    /**
     * Lecture par le client de ses propres orientations (/client/mes-banques) :
     * la propriété est garantie par la requête, qui part de $user->client.
     */
    public function viewOwn(User $user): bool
    {
        return $user->hasPermissionTo('view-banks');
    }

    public function view(User $user, BankAssignment $assignment): bool
    {
        if ($user->hasAnyRole(['agent-cpi', 'super-admin'])) {
            return $user->hasPermissionTo('view-banks');
        }

        return $user->hasPermissionTo('view-banks')
            && $user->id === $assignment->client?->user_id;
    }

    /**
     * Création d'une orientation (staff).
     */
    public function assign(User $user): bool
    {
        return $user->hasPermissionTo('assign-bank');
    }

    /**
     * Réponse de la banque : en-attente / accord / refus (staff).
     */
    public function setStatus(User $user): bool
    {
        return $user->hasPermissionTo('assign-bank');
    }

    /**
     * Retrait d'une orientation (staff).
     */
    public function remove(User $user): bool
    {
        return $user->hasPermissionTo('assign-bank');
    }
}
