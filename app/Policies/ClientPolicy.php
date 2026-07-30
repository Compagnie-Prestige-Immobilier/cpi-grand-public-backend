<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view-clients');
    }

    public function view(User $user, Client $client): bool
    {
        // Staff can view any client; clients can only view themselves
        if ($user->hasAnyRole(['agent-cpi', 'super-admin'])) {
            return $user->hasPermissionTo('view-clients');
        }
        return $user->id === $client->user_id;
    }

    public function create(User $user): bool
    {
        // Any authenticated user can create their own client profile
        return true;
    }

    public function update(User $user, Client $client): bool
    {
        if ($user->hasAnyRole(['agent-cpi', 'super-admin'])) {
            return $user->hasPermissionTo('edit-client');
        }
        return $user->id === $client->user_id;
    }

    public function delete(User $user, Client $client): bool
    {
        return $user->hasPermissionTo('delete-client');
    }

    /**
     * Chargement / purge du jeu de démonstration (POST /staff/demo/seed,
     * DELETE /staff/demo/clear).
     *
     * Capacité de PLATEFORME, pas de dossier : elle s'autorise donc sur la
     * classe (`authorize('manageDemo', Client::class)`), sans instance. Seul le
     * super-admin détient `manage-demo-data` — un agent CPI reçoit 403.
     */
    public function manageDemo(User $user): bool
    {
        return $user->hasPermissionTo('manage-demo-data');
    }
}
