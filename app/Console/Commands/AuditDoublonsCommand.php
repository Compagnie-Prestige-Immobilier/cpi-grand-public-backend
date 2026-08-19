<?php

namespace App\Console\Commands;

use App\Support\HasOneDuplicates;
use Illuminate\Console\Command;

/**
 * Audit en LECTURE SEULE des relations `hasOne` sans contrainte d'unicité.
 *
 * `Demande`, `Decaissement` et `Chantier` sont déclarés `hasOne` côté modèle
 * mais rien ne l'impose en base : deux requêtes concurrentes sur un dossier
 * fraîchement créé peuvent insérer deux lignes pour le même client, après quoi
 * `->first()` en renvoie une au hasard (aucun `orderBy`), et le dossier semble
 * changer de contenu d'un appel à l'autre.
 *
 * À lancer sur la production AVANT de déployer la migration qui pose les
 * contraintes : celle-ci refuse de s'exécuter tant que des doublons subsistent,
 * et ne supprime jamais rien d'elle-même.
 */
class AuditDoublonsCommand extends Command
{
    protected $signature = 'cpi:audit-doublons';

    protected $description = 'Recense les doublons sur les relations hasOne (lecture seule, ne modifie rien)';

    public function handle(): int
    {
        $total = 0;

        foreach (HasOneDuplicates::TABLES as $table) {
            $doublons = HasOneDuplicates::pour($table);
            $total += $doublons->count();

            if ($doublons->isEmpty()) {
                $this->line(sprintf('  %-16s aucun doublon', $table));

                continue;
            }

            $this->newLine();
            $this->warn(sprintf('  %s : %d client(s) avec plusieurs lignes', $table, $doublons->count()));
            $this->table(
                ['client_id', 'nombre de lignes', 'identifiants'],
                $doublons->map(fn (object $ligne): array => [
                    $ligne->client_id,
                    $ligne->n,
                    HasOneDuplicates::identifiants($table, (string) $ligne->client_id),
                ])->all(),
            );
        }

        $this->newLine();

        if ($total === 0) {
            $this->info('Aucun doublon : la migration d\'unicité peut être appliquée.');

            return self::SUCCESS;
        }

        $this->error(sprintf(
            '%d doublon(s) à arbitrer avant de poser les contraintes d\'unicité.',
            $total,
        ));
        $this->line('Pour chaque cas, décider quelle ligne conserver — la plus ancienne n\'est pas');
        $this->line('nécessairement celle que le personnel a réellement remplie.');

        return self::FAILURE;
    }
}
