<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index sur les colonnes réellement filtrées ou agrégées.
 *
 * Aucune de ces colonnes n'était indexée alors que `StatsController` les
 * groupe et les compte à chaque affichage du tableau de bord, et que les listes
 * du personnel filtrent dessus. Sur une base de démonstration cela ne se voit
 * pas ; sur un portefeuille réel, chaque écran devient un balayage complet.
 *
 * Migration purement additive : un index ne change aucune donnée et se retire
 * sans effet de bord.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const INDEXES = [
        'clients' => ['dossier_etape', 'conseiller_id'],
        'requis_docs' => ['status'],
        'cpi_docs' => ['status', 'visible_client'],
        'chantiers' => ['statut'],
        'bank_assignments' => ['status'],
        'app_notifications' => ['lu'],
        'decaissements' => ['terrain_decaisse', 'construction_active'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $colonnes) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $colonnes): void {
                foreach ($colonnes as $colonne) {
                    $blueprint->index($colonne, "{$table}_{$colonne}_index");
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $colonnes) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $colonnes): void {
                foreach ($colonnes as $colonne) {
                    $blueprint->dropIndex("{$table}_{$colonne}_index");
                }
            });
        }
    }
};
