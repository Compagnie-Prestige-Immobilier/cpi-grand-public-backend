<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppression réversible sur tout ce qui porte l'historique financier et
 * documentaire d'un dossier.
 *
 * Aujourd'hui, `DELETE /staff/clients/{client}` efface définitivement le
 * dossier ET, par cascade SQL, sa demande, ses pièces justificatives, ses
 * documents contractuels, ses orientations bancaires, son décaissement et son
 * chantier. Une erreur de manipulation d'un agent détruit irrémédiablement le
 * dossier de financement d'un particulier — pièces d'identité et montants
 * compris — sans aucun recours.
 *
 * Les tables de configuration et de journal (chantier_events, _medias,
 * _publications, bank_assignments, app_notifications) restent en suppression
 * physique : elles ne portent pas d'engagement contractuel.
 *
 * Migration additive : une colonne nullable, aucune donnée touchée.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = [
        'clients',
        'demandes',
        'requis_docs',
        'cpi_docs',
        'decaissements',
        'chantiers',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->softDeletes();
                // Toutes les lectures passent par le scope global
                // `deleted_at is null` : sans index, chaque requête de chaque
                // écran le paie.
                $blueprint->index('deleted_at');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropIndex(['deleted_at']);
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
