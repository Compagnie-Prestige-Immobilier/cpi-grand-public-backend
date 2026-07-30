<?php

namespace App\Policies;

use App\Models\Bank;
use App\Models\User;

/**
 * Registre des banques partenaires. La lecture est ouverte à tous les rôles
 * (`view-banks`) ; la gestion du registre est réservée à l'administrateur —
 * seul `super-admin` détient create-bank / edit-bank / delete-bank.
 */
class BankPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view-banks');
    }

    public function view(User $user, Bank $bank): bool
    {
        return $user->hasPermissionTo('view-banks');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('create-bank');
    }

    public function update(User $user, Bank $bank): bool
    {
        return $user->hasPermissionTo('edit-bank');
    }

    public function delete(User $user, Bank $bank): bool
    {
        return $user->hasPermissionTo('delete-bank');
    }
}
