<?php

namespace App\Support;

use App\Enums\Statut;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Point de passage unique pour tout changement de statut.
 *
 * Les enums de `App\Enums` décrivent les transitions légales ; cette classe les
 * fait respecter et produit un message lisible plutôt qu'une écriture
 * silencieuse. Sans elle, `Rule::in` continuerait de valider les valeurs sans
 * jamais regarder d'où l'on vient.
 */
final class TransitionStatut
{
    /** Vérifie qu'un passage est légal, sinon 409 avec les cibles possibles. */
    public static function verifier(Statut $depuis, Statut $vers, string $sujet): void
    {
        if ($depuis === $vers) {
            return;   // idempotent : rejouer une action n'est pas une faute
        }

        if ($depuis->peutAllerVers($vers)) {
            return;
        }

        $possibles = array_map(fn (Statut $etat): string => $etat->libelle(), $depuis->suivants());

        throw new ConflictHttpException(sprintf(
            '%s : passage de « %s » à « %s » impossible. %s',
            $sujet,
            $depuis->libelle(),
            $vers->libelle(),
            $possibles === []
                ? 'Cet état est terminal.'
                : 'Transitions possibles : '.implode(', ', $possibles).'.',
        ));
    }
}
