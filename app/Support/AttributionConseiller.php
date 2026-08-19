<?php

namespace App\Support;

use App\Models\Client;
use App\Models\User;
use App\Services\NotifieLeClient;
use Illuminate\Support\Facades\DB;

/**
 * Attribution automatique d'un conseiller au moment où un compte est validé.
 *
 * Règle : l'agent-cpi dont le PORTEFEUILLE ACTIF est le plus léger — les
 * dossiers déjà signés (`ParcoursDossier::SIGNATURE`) ou supprimés ne comptent
 * pas. Un agent qui a mené cent dossiers à terme ne doit pas paraître plus
 * chargé qu'un agent qui vient d'en recevoir trois qui traînent — c'est la
 * charge de travail RÉELLE, pas le volume traité, que cette règle équilibre.
 *
 * `super-admin` n'entre jamais dans le tirage : ce rôle supervise, il ne porte
 * pas de portefeuille au jour le jour.
 */
final class AttributionConseiller
{
    /**
     * Attribue le dossier du client au conseiller le moins chargé.
     *
     * Renvoie l'agent choisi, ou `null` si aucun agent-cpi n'existe — le
     * dossier reste alors sans conseiller (comme un dossier ordinaire non
     * attribué) plutôt que de faire échouer la validation du compte : un
     * administrateur peut valider des comptes avant même d'avoir recruté du
     * personnel, et l'attribution manuelle reste possible ensuite.
     *
     * Transaction + verrou sur le POOL d'agents (et non sur le client) :
     * deux validations simultanées doivent se sérialiser sur « qui est le
     * moins chargé », sinon les deux liraient la même charge et
     * assigneraient au même agent, cassant l'équilibrage qu'elles sont
     * censées produire. `orderBy('id')` fixe un ordre de verrouillage stable
     * — nécessaire dès qu'une transaction verrouille plusieurs lignes, pour
     * qu'un verrouillage concurrent dans un ordre différent ne puisse pas
     * produire d'interblocage.
     */
    public static function assigner(Client $client): ?User
    {
        return DB::transaction(function () use ($client): ?User {
            $agents = User::role('agent-cpi')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($agents->isEmpty()) {
                return null;
            }

            $charges = self::chargesActives($agents->pluck('id')->all());

            // Egalité : le premier agent dans l'ordre verrouillé ci-dessus
            // l'emporte — déterministe, et non un artefact de l'ordre de
            // lecture SQL. `sortBy` est stable depuis PHP 8.0 : à charge
            // égale, l'ordre d'entrée (déjà trié par id) est conservé.
            $conseiller = $agents->sortBy(fn (User $a): int => $charges[$a->id] ?? 0)->first();

            // Deux colonnes à tenir ensemble, pour une raison qui n'a rien
            // d'évident : `conseiller_id` est la relation lue par le
            // cloisonnement (`PortefeuilleConseiller`), mais TOUT l'affichage
            // client (tableau de bord, chantier, « Ma demande », profil) lit
            // `conseiller`, une colonne texte distincte que rien ne synchronise
            // avec la première. Écrire seulement `conseiller_id` aurait
            // correctement restreint l'accès au dossier tout en laissant
            // « Non assigné » affiché partout côté client.
            $client->update([
                'conseiller_id' => $conseiller->id,
                'conseiller' => $conseiller->name,
            ]);

            return $conseiller;
        });
    }

    /**
     * Attribue, journalise et notifie — le trajet complet déclenché par une
     * décision humaine (validation d'un compte, réattribution manuelle).
     *
     * `assigner()` reste volontairement PUR (aucun journal, aucune
     * notification) : c'est ce que les tests d'équilibrage de charge
     * exercent directement, sans avoir à faire semblant d'un opérateur ou
     * d'un service de notification. Cette méthode-ci est celle que les
     * CONTRÔLEURS appellent — les deux points d'entrée (validation de compte,
     * réattribution manuelle) doivent produire exactement la même trace et le
     * même message, jamais deux versions qui divergent avec le temps.
     */
    public static function assignerEtNotifier(Client $client, User $operateur, NotifieLeClient $notifie): ?User
    {
        $conseiller = self::assigner($client);

        if ($conseiller === null) {
            // Distinct de l'événement qui a déclenché l'appel (validation ou
            // réattribution) : un administrateur qui parcourt le journal doit
            // pouvoir repérer les dossiers restés sans conseiller sans avoir à
            // recouper la liste du personnel lui-même.
            activity()
                ->causedBy($operateur)
                ->performedOn($client)
                ->event('conseiller-non-attribue')
                ->log("Aucun agent CPI disponible pour attribuer un conseiller à {$client->name}");

            return null;
        }

        activity()
            ->causedBy($operateur)
            ->performedOn($client)
            ->withProperties(['conseiller_id' => $conseiller->id])
            ->event('conseiller-attribue')
            ->log("{$conseiller->name} a été attribué comme conseiller de {$client->name}");

        $notifie->conseillerAttribue($client, $conseiller->name);

        return $conseiller;
    }

    /**
     * Nombre de dossiers ACTIFS par agent — une seule requête agrégée : sur un
     * portefeuille de plusieurs milliers de dossiers, charger chaque client en
     * mémoire pour compter serait le genre de coût qui ne se voit qu'en
     * production.
     *
     * Même jointure que `StatsController::dossiersParEtape()`, filtrée à
     * « avant Signature » et groupée par conseiller plutôt que par étape.
     *
     * @param  list<string>  $agentIds
     * @return array<string, int> conseiller_id => nombre de dossiers actifs
     */
    private static function chargesActives(array $agentIds): array
    {
        return DB::table('clients as c')
            ->whereNull('c.deleted_at')
            ->whereIn('c.conseiller_id', $agentIds)
            ->leftJoin('demandes as d', 'd.client_id', '=', 'c.id')
            ->leftJoinSub(
                DB::table('requis_docs')
                    ->select('client_id')
                    ->selectRaw('count(*) as total')
                    ->selectRaw("sum(case when status = 'accepte' then 1 else 0 end) as acceptes")
                    ->groupBy('client_id'),
                'r',
                'r.client_id',
                '=',
                'c.id',
            )
            ->whereRaw('('.ParcoursDossier::sql().') < ?', [ParcoursDossier::SIGNATURE])
            ->groupBy('c.conseiller_id')
            ->selectRaw('c.conseiller_id as conseiller_id, count(*) as total')
            ->pluck('total', 'conseiller_id')
            ->all();
    }
}
