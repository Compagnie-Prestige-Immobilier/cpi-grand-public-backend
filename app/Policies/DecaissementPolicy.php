<?php

namespace App\Policies;

use App\Models\Decaissement;
use App\Models\User;

/**
 * État de décaissement d'un dossier : acquisition foncière (décaissement
 * unique) puis construction par tranches. Le rôle `client` ne détient ni
 * `view-decaissements` ni `manage-decaissements` — le module est purement
 * interne (routes /staff/*).
 */
class DecaissementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view-decaissements');
    }

    public function view(User $user, Decaissement $decaissement): bool
    {
        return $user->hasPermissionTo('view-decaissements');
    }

    public function update(User $user, Decaissement $decaissement): bool
    {
        return $user->hasPermissionTo('manage-decaissements');
    }

    /**
     * Validation d'une étape : décaissement du terrain, étape foncière, tranche
     * de construction. Autrement dit, le déclenchement d'un versement d'argent
     * réel.
     *
     * Contrôle à quatre yeux : cette permission est distincte de
     * `manage-decaissements` (préparation du dossier) et n'est PAS accordée au
     * rôle `agent-cpi`. Avant, un seul compte agent compromis ou malveillant
     * suffisait à préparer ET valider un versement.
     *
     * La seconde moitié du contrôle — le valideur ne peut pas être celui qui a
     * préparé — est appliquée dans DecaissementController, qui seul connaît
     * l'utilisateur courant de la requête.
     */
    public function declencherVersement(User $user, Decaissement $decaissement): bool
    {
        return $user->hasPermissionTo('validate-decaissements');
    }
}
