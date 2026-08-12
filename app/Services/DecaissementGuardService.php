<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Decaissement;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Garde-fous du décaissement.
 *
 * Rien ne reliait l'argent versé au financement accordé : on pouvait décaisser
 * un montant supérieur à la `Demande`, valider la tranche 4 avant la 1, ou
 * franchir une étape du parcours foncier sans avoir franchi les précédentes.
 * L'audit relève ce point (DOM-06, DOM-07, DOM-08) comme le manque fonctionnel
 * le plus structurant de la plateforme : sur un produit de financement, c'est
 * la seule chose qui empêche un versement erroné de partir.
 *
 * Ces contrôles s'appuient sur les colonnes existantes. Ils n'attendent pas le
 * remodelage des tranches en table relationnelle : mieux vaut une garde
 * imparfaite aujourd'hui qu'une garde parfaite dans trois semaines.
 */
class DecaissementGuardService
{
    /**
     * Le total engagé (terrain + construction) ne peut pas dépasser le montant
     * de la demande de financement.
     *
     * Silencieux si la demande n'a pas de montant chiffré : le dossier n'est
     * alors pas encore instruit, et bloquer figerait les dossiers en cours.
     */
    public function verifierEnveloppe(Client $client, Decaissement $decaissement): void
    {
        $accorde = $client->demande?->montant;

        if ($accorde === null) {
            return;
        }

        $engage = (float) $decaissement->terrain_montant + (float) $decaissement->construction_montant;

        if ($engage > (float) $accorde + 0.001) {
            throw new ConflictHttpException(sprintf(
                'Le total engagé (%s) dépasse le montant accordé (%s). Corriger la demande ou les montants du décaissement.',
                number_format($engage, 0, ',', ' '),
                number_format((float) $accorde, 0, ',', ' '),
            ));
        }
    }

    /**
     * Une tranche de construction ne se décaisse qu'après la précédente.
     *
     * @param  array<int, array<string, mixed>>  $tranches
     */
    public function verifierOrdreTranche(array $tranches, int $index): void
    {
        if ($index === 0) {
            return;
        }

        $precedente = $tranches[$index - 1] ?? null;

        if (! is_array($precedente) || ($precedente['validated'] ?? false) !== true) {
            throw new ConflictHttpException(sprintf(
                'La tranche %d doit être décaissée avant la tranche %d.',
                $index,
                $index + 1,
            ));
        }
    }

    /**
     * Une étape du parcours foncier ne se valide qu'après les précédentes.
     *
     * @param  array<int, bool>  $foncier
     */
    public function verifierOrdreFoncier(array $foncier, int $index): void
    {
        for ($precedent = 0; $precedent < $index; $precedent++) {
            if (($foncier[$precedent] ?? false) !== true) {
                throw new ConflictHttpException(sprintf(
                    "L'étape foncière %d doit être validée avant l'étape %d.",
                    $precedent + 1,
                    $index + 1,
                ));
            }
        }
    }

    /**
     * Le décaissement du terrain suppose un montant renseigné : verser zéro
     * franc n'a aucun sens et masquerait une saisie oubliée.
     */
    public function verifierMontantTerrain(Decaissement $decaissement): void
    {
        if ((float) $decaissement->terrain_montant <= 0.0) {
            throw new ConflictHttpException(
                'Le montant du terrain doit être renseigné avant son décaissement.',
            );
        }
    }
}
