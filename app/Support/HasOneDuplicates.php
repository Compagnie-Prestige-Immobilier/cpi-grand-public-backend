<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Détection des doublons sur les relations `hasOne` qui n'ont jamais eu de
 * contrainte d'unicité en base.
 *
 * Partagé entre la commande d'audit (`cpi:audit-doublons`) et la migration qui
 * pose les contraintes : les deux doivent compter exactement de la même façon,
 * sinon l'audit pourrait dire « rien à faire » là où la migration échoue.
 */
final class HasOneDuplicates
{
    /** @var list<string> */
    public const TABLES = ['demandes', 'decaissements', 'chantiers'];

    /**
     * Clients porteurs de plusieurs lignes dans la table donnée.
     *
     * @return Collection<int, object{client_id: string, n: int}>
     */
    public static function pour(string $table): Collection
    {
        /** @var Collection<int, object{client_id: string, n: int}> */
        return DB::table($table)
            ->select('client_id', DB::raw('count(*) as n'))
            ->groupBy('client_id')
            ->having(DB::raw('count(*)'), '>', 1)
            ->orderBy('client_id')
            ->get();
    }

    /** Identifiants des lignes en doublon, de la plus ancienne à la plus récente. */
    public static function identifiants(string $table, string $clientId): string
    {
        return DB::table($table)
            ->where('client_id', $clientId)
            ->orderBy('created_at')
            ->pluck('id')
            ->implode(', ');
    }

    /** Nombre total de clients en doublon, toutes tables confondues. */
    public static function total(): int
    {
        $total = 0;

        foreach (self::TABLES as $table) {
            $total += self::pour($table)->count();
        }

        return $total;
    }

    /** Résumé lisible, destiné au message d'erreur de la migration. */
    public static function resume(): string
    {
        $lignes = [];

        foreach (self::TABLES as $table) {
            foreach (self::pour($table) as $doublon) {
                $lignes[] = sprintf(
                    '%s : client %s porte %d lignes (%s)',
                    $table,
                    $doublon->client_id,
                    $doublon->n,
                    self::identifiants($table, (string) $doublon->client_id),
                );
            }
        }

        return implode(PHP_EOL, $lignes);
    }
}
