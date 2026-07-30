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
}
