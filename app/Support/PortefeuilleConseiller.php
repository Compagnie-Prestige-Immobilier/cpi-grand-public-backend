<?php

namespace App\Support;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

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
 *   - `agent-cpi` voit UNIQUEMENT les dossiers dont il est le conseiller.
 *
 * Un dossier non attribué n'a longtemps compté que sur la première moitié de
 * cette règle : tout agent le voyait, sans quoi une nouvelle demande serait
 * tombée dans un angle mort le temps qu'un administrateur l'attribue. Ce
 * filet de sécurité a disparu le jour où DEUX choses l'ont rendu inutile —
 * elles doivent rester vraies ensemble, sans quoi ce cloisonnement redevient
 * exactement le trou qu'il comblait :
 *
 *   1. `AttributionConseiller` attribue désormais un conseiller
 *      AUTOMATIQUEMENT dès qu'un compte est validé — un dossier n'existe plus
 *      « non attribué » qu'à la marge (aucun agent-cpi au moment de
 *      l'approbation, ou un dossier créé directement par le personnel) ;
 *   2. ces cas résiduels restent visibles et actionnables — jamais perdus,
 *      seulement réservés à l'administration — via
 *      `GET /staff/clients?non_attribues=1` et
 *      `POST /staff/clients/{client}/attribuer-conseiller`, tous deux
 *      accessibles au super-admin par le jeu normal de cette même règle
 *      (`voitTout()`).
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

        return $client->conseiller_id === $user->id;
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

        return $query->where('conseiller_id', $user->id);
    }

    /**
     * Restreint le journal d'activité au portefeuille de l'agent.
     *
     * Le sujet d'une entrée (`Activity::subject`, polymorphe) n'est pas
     * toujours un `Client` : la validation d'un compte, sa correction ou une
     * prise en main visent le `User` du client. Les deux formes sont
     * ramenées au même dossier — sans quoi un agent perdrait la trace de la
     * validation ou de l'attribution de SES PROPRES clients, l'essentiel de
     * ce qu'un historique sert à retrouver.
     *
     * Une entrée sans sujet, ou dont le sujet est un `User` sans `Client`
     * associé (personnel, données de démonstration), n'appartient au dossier
     * d'AUCUN client : elle reste réservée au super-admin, au même titre que
     * la création ou la suppression d'un compte du personnel.
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public static function filtrerActivites(Builder $query, User $user): Builder
    {
        if (self::voitTout($user)) {
            return $query;
        }

        // Le prédicat est réécrit ici plutôt que délégué à `filtrer()` : cette
        // méthode est typée pour une requête sur `clients`, alors que
        // `whereHasMorph` fournit un générateur sur le type effacé `Model` —
        // les deux formes appliquent la MÊME règle (`conseiller_id = agent`),
        // seule la façade change.
        return $query->where(function (Builder $portee) use ($user): void {
            $portee->whereHasMorph(
                'subject',
                [Client::class],
                fn (Builder $q) => $q->where('conseiller_id', $user->id),
            )->orWhereHasMorph(
                'subject',
                [User::class],
                fn (Builder $q) => $q->whereHas(
                    'client',
                    fn (Builder $cq) => $cq->where('conseiller_id', $user->id),
                ),
            );
        });
    }
}
