<?php

namespace App\Support;

use App\Enums\RequisDocStatut;
use App\Models\RequisDoc;
use Illuminate\Support\Collection;

/**
 * Étape du parcours d'un dossier, de 0 (inscription) à 5 (signature).
 *
 * Le même calcul existait en TROIS exemplaires indépendants :
 * `ClientController::computeJourneyStep`, `DemandeController::etapeParcours` et
 * `StatsController::ETAPE_SQL`. Les deux premiers étaient identiques à la
 * formulation près ; le troisième, écrit en SQL pour agréger, appliquait la
 * même logique dans un autre langage. Rien ne garantissait qu'ils restent
 * d'accord — et un tableau de bord qui compte les dossiers par étape doit
 * compter exactement ce que chaque dossier affiche.
 *
 * La version PHP fait autorité. La version SQL reste nécessaire pour
 * l'agrégation (on ne charge pas tous les dossiers en mémoire pour les
 * compter) : elle vit ici aussi, juste à côté, pour que les deux se lisent
 * ensemble et se modifient ensemble.
 */
final class ParcoursDossier
{
    /** Première étape : le dossier existe, la demande n'est pas soumise. */
    public const INSCRIPTION = 0;

    /** Demande soumise, pièces incomplètes ou non validées. */
    public const PIECES = 1;

    /** Première étape pilotée par le personnel une fois les pièces validées. */
    public const INSTRUCTION = 2;

    /** Dernière étape : dossier signé. */
    public const SIGNATURE = 5;

    /**
     * @param  Collection<int, RequisDoc>  $pieces
     */
    public static function etape(bool $soumise, Collection $pieces, int $etapeCpi = self::INSTRUCTION): int
    {
        if (! $soumise) {
            return self::INSCRIPTION;
        }

        $toutesValidees = $pieces->isNotEmpty()
            && $pieces->every(fn (RequisDoc $piece): bool => $piece->status === RequisDocStatut::Accepte);

        if (! $toutesValidees) {
            return self::PIECES;
        }

        // Au-delà des pièces, c'est le personnel qui fait avancer le dossier ;
        // on borne pour qu'une valeur aberrante en base ne sorte pas telle
        // quelle vers l'interface.
        return min(self::SIGNATURE, max(self::INSTRUCTION, $etapeCpi));
    }

    /**
     * Traduction SQL du calcul ci-dessus, pour l'agrégation par étape.
     *
     * Suppose les alias `d` (demandes), `r` (agrégat des requis_docs :
     * `total`, `acceptes`) et `c` (clients).
     *
     * Toute modification de `etape()` doit être répercutée ici, et
     * réciproquement — c'est la raison d'être de ce voisinage.
     */
    public static function sql(): string
    {
        return sprintf(<<<'SQL'
            case
                when d.submitted is null or d.submitted = false then %d
                when coalesce(r.total, 0) = 0 or coalesce(r.acceptes, 0) < r.total then %d
                when c.dossier_etape < %d then %d
                when c.dossier_etape > %d then %d
                else c.dossier_etape
            end
            SQL,
            self::INSCRIPTION,
            self::PIECES,
            self::INSTRUCTION, self::INSTRUCTION,
            self::SIGNATURE, self::SIGNATURE,
        );
    }
}
