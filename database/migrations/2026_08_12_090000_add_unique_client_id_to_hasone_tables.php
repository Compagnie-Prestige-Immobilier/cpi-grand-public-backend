<?php

use App\Support\HasOneDuplicates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `Demande`, `Decaissement` et `Chantier` sont déclarés `hasOne` côté modèle,
 * mais rien ne l'imposait en base. `Client::ensureDecaissement()` et
 * `ensureChantier()` lisent puis insèrent sans verrou : deux requêtes
 * concurrentes sur un dossier fraîchement créé insèrent deux lignes, après quoi
 * `->first()` en renvoie une au hasard — aucun `orderBy` — et le dossier semble
 * changer de contenu d'un appel à l'autre.
 *
 * Cette migration ne supprime AUCUNE ligne. S'il existe des doublons, elle
 * s'arrête et les liste : décider laquelle conserver est un arbitrage métier,
 * pas un choix que du code peut faire seul (la plus ancienne n'est pas
 * forcément celle que le personnel a réellement remplie). Lancer d'abord
 * `php artisan cpi:audit-doublons` sur la base concernée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (HasOneDuplicates::total() > 0) {
            throw new RuntimeException(
                'Des doublons existent sur les relations hasOne — la contrainte '
                .'ne peut pas être posée sans arbitrage.'.PHP_EOL.PHP_EOL
                .HasOneDuplicates::resume().PHP_EOL.PHP_EOL
                .'Aucune ligne n\'a été modifiée. Arbitrer les cas ci-dessus, puis relancer.',
            );
        }

        foreach (HasOneDuplicates::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->unique('client_id', "{$table}_client_id_unique");
            });
        }
    }

    public function down(): void
    {
        foreach (HasOneDuplicates::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropUnique("{$table}_client_id_unique");
            });
        }
    }
};
