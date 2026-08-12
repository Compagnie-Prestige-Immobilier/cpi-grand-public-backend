<?php

namespace App\Support;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cloisonnement des dossiers par conseiller assigné.
 *
 * `clients.conseiller_id` existe depuis l'origine mais aucune policy ne s'en
 * servait : n'importe quel `agent-cpi` voyait et modifiait TOUS les dossiers de
 * TOUS les clients — pièces d'identité, revenus, montants, documents
 * contractuels. Sur une plateforme de financement, un agent n'a pas de raison
 * d'accéder au dossier d'un particulier qui n'est pas le sien.
 *
 * Règle retenue :
 *   - `super-admin` voit tout (supervision, reprise de dossier, statistiques) ;
 *   - `agent-cpi` voit les dossiers dont il est le conseiller, **et** ceux qui
 *     n'ont encore aucun conseiller assigné.
 *
 * Le second point est délibéré : un dossier non attribué doit rester traitable
 * par le premier agent disponible, sinon les nouvelles demandes tomberaient
 * dans un angle mort le temps qu'un administrateur les attribue.
 */
final class PortefeuilleConseiller
{
    /** Un compte qui n'est pas soumis au cloisonnement. */
    public static function voitTout(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    /** Ce dossier est-il dans le portefeuille de cet agent ? */
    public static function contient(User $user, ?Client $client): bool
    {
        if ($client === null) {
            return false;
        }

        if (self::voitTout($user)) {
            return true;
        }

        return $client->conseiller_id === null || $client->conseiller_id === $user->id;
    }

    /**
     * Restreint une requête sur `clients` au portefeuille de l'agent.
     *
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public static function filtrer(Builder $query, User $user): Builder
    {
        if (self::voitTout($user)) {
            return $query;
        }

        return $query->where(function (Builder $portefeuille) use ($user): void {
            $portefeuille->whereNull('conseiller_id')
                ->orWhere('conseiller_id', $user->id);
        });
    }
}
