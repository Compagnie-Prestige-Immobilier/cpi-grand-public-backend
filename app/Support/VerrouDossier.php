<?php

namespace App\Support;

use App\Models\Client;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Verrouillage du dossier à partir du début de l'instruction.
 *
 * Dès que le personnel passe le dossier à l'étape « Analyse », le client perd
 * la main : ce qui est instruit ne doit plus bouger sous les yeux de l'agent.
 *
 * Le verrou n'était appliqué qu'à la sauvegarde de la demande. Le dépôt d'une
 * pièce justificative et la soumission de la demande y échappaient : un client
 * pouvait remplacer sa pièce d'identité ou ses relevés bancaires APRÈS le début
 * de l'analyse, sans que rien ne le signale à l'agent qui instruisait le
 * dossier sur la base des pièces précédentes.
 *
 * La règle vit ici plutôt que dans un contrôleur : elle s'applique à plusieurs
 * endpoints répartis dans deux contrôleurs, et un troisième oubli aurait le
 * même effet que les deux premiers.
 */
final class VerrouDossier
{
    /**
     * Étape à partir de laquelle le dossier est figé côté client.
     *
     * 3 = « Analyse » dans le parcours en 6 étapes (0 à 5).
     */
    public const ETAPE = 3;

    public static function estVerrouille(Client $client): bool
    {
        return $client->dossier_etape >= self::ETAPE;
    }

    /**
     * Refuse une écriture du client sur un dossier en cours d'instruction.
     *
     * 409 et non 403 : ce n'est pas une question de droits — le client est bien
     * chez lui — mais d'état du dossier.
     */
    public static function refuserSiVerrouille(Client $client, string $action): void
    {
        if (! self::estVerrouille($client)) {
            return;
        }

        throw new ConflictHttpException(sprintf(
            "%s : votre dossier est en cours d'analyse et ne peut plus être modifié. "
            .'Contactez votre conseiller CPI pour toute correction.',
            $action,
        ));
    }
}
