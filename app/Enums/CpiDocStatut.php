<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Cycle de vie d'un document produit par CPI (convention, contrat, attestation).
 *
 * Valeurs identiques à celles déjà en base et déjà lues par le frontend.
 *
 * Le trou principal que cet enum ferme : `sign` ne vérifiait pas que le
 * document soit publié. Un brouillon jamais transmis au client pouvait donc
 * être marqué signé.
 */
#[TypeScript]
enum CpiDocStatut: string implements Statut
{
    case Brouillon = 'brouillon';
    case Disponible = 'disponible';
    case ASigner = 'a-signer';
    case Signe = 'signe';
    case Archive = 'archive';

    public function libelle(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Disponible => 'Disponible',
            self::ASigner => 'À signer',
            self::Signe => 'Signé',
            self::Archive => 'Archivé',
        };
    }

    /** @return list<static> */
    public function suivants(): array
    {
        return match ($this) {
            // La publication oriente vers « à signer » ou « disponible » selon
            // que le document exige une signature.
            self::Brouillon => [self::Disponible, self::ASigner, self::Archive],
            self::Disponible => [self::ASigner, self::Archive],
            self::ASigner => [self::Signe, self::Archive],
            self::Signe => [self::Archive],
            // Un document archivé peut être remis à disposition, jamais
            // re-signé sans repasser par « à signer ».
            self::Archive => [self::Disponible, self::ASigner],
        };
    }

    public function peutAllerVers(Statut $cible): bool
    {
        return in_array($cible, $this->suivants(), true);
    }

    public static function valeurs(): array
    {
        return array_column(self::cases(), 'value');
    }
}
