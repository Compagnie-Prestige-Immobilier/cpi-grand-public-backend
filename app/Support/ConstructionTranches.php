<?php

namespace App\Support;

/**
 * Référentiel unique des 4 tranches de construction (35 / 30 / 30 / 5 %).
 *
 * Le même découpage métier existe aujourd'hui en deux exemplaires
 * indépendants : `chantier_tranches`, une vraie table relationnelle qui suit
 * l'avancement des TRAVAUX, et `decaissements.tranches`, un tableau JSON de
 * quatre objets `{validated, date?, comment?}` qui suit les VERSEMENTS — sans
 * montant par tranche, et sans aucun lien entre les deux.
 *
 * Rien ne garantissait que les deux listes portent les mêmes libellés ni les
 * mêmes pourcentages : elles étaient déclarées séparément, dans
 * `Chantier::defaultTranches()` d'un côté et implicitement côté frontend de
 * l'autre. Cette classe devient la source dont les deux dérivent.
 *
 * Elle ne change aucun comportement à elle seule : `Chantier` continue
 * d'exposer `defaultTranches()`, qui délègue désormais ici.
 */
final class ConstructionTranches
{
    /**
     * @return list<array{num: int, label: string, description: string, pct: int}>
     */
    public static function definitions(): array
    {
        return [
            ['num' => 1, 'label' => 'Avance de démarrage', 'pct' => 35,
                'description' => 'À la signature et au démarrage du chantier — mobilisation des équipes.'],
            ['num' => 2, 'label' => 'Élévation des murs, poteaux, dalle et toiture', 'pct' => 30,
                'description' => "Libéré après certification de la mise hors d'eau."],
            ['num' => 3, 'label' => 'Second œuvre', 'pct' => 30,
                'description' => 'Menuiseries, plomberie, électricité et carrelage.'],
            ['num' => 4, 'label' => 'Remise des clés', 'pct' => 5,
                'description' => 'À la réception définitive du logement.'],
        ];
    }

    /** Nombre de tranches — évite de coder « 4 » en dur ailleurs. */
    public static function count(): int
    {
        return count(self::definitions());
    }

    /** Somme des pourcentages : doit toujours valoir 100. */
    public static function totalPct(): int
    {
        return array_sum(array_column(self::definitions(), 'pct'));
    }

    /**
     * Définition d'une tranche par son numéro fonctionnel (1..4), celui que
     * portent déjà les routes `validate-tranche/{num}` des deux côtés.
     *
     * @return array{num: int, label: string, description: string, pct: int}|null
     */
    public static function byNum(int $num): ?array
    {
        foreach (self::definitions() as $definition) {
            if ($definition['num'] === $num) {
                return $definition;
            }
        }

        return null;
    }
}
