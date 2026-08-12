<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Avancement physique du chantier.
 *
 * Valeurs identiques à `Chantier::STATUTS`, qui reste défini et délègue ici.
 *
 * Avant, `Rule::in` validait les valeurs mais jamais les transitions : un
 * chantier livré pouvait redevenir « non démarré », ce que le client voyait
 * sans explication.
 */
#[TypeScript]
enum ChantierStatut: string implements Statut
{
    case NonDemarre = 'non-demarre';
    case EnCours = 'en-cours';
    case Suspendu = 'suspendu';
    case EnRetard = 'en-retard';
    case Termine = 'termine';
    case Livre = 'livre';

    public function libelle(): string
    {
        return match ($this) {
            self::NonDemarre => 'Non démarré',
            self::EnCours => 'En cours',
            self::Suspendu => 'Suspendu',
            self::EnRetard => 'En retard',
            self::Termine => 'Terminé',
            self::Livre => 'Livré',
        };
    }

    /** @return list<static> */
    public function suivants(): array
    {
        return match ($this) {
            self::NonDemarre => [self::EnCours],
            // Suspension et retard sont des états de fonctionnement du chantier
            // en cours : on y entre et on en sort.
            self::EnCours => [self::Suspendu, self::EnRetard, self::Termine],
            self::Suspendu => [self::EnCours, self::EnRetard],
            self::EnRetard => [self::EnCours, self::Suspendu, self::Termine],
            self::Termine => [self::Livre, self::EnCours],
            // Livré est terminal : un logement livré ne repart pas en travaux
            // sans un nouveau dossier.
            self::Livre => [],
        };
    }

    public function peutAllerVers(Statut $cible): bool
    {
        return in_array($cible, $this->suivants(), true);
    }

    /** @return list<string> */
    public static function valeurs(): array
    {
        return array_column(self::cases(), 'value');
    }
}
