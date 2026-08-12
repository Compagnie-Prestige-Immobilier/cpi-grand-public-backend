<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Réponse d'une banque à laquelle un dossier a été orienté.
 *
 * Un accord ou un refus bancaire est une décision de l'établissement : il ne se
 * révise pas silencieusement côté CPI. Avant, `Rule::in` laissait repasser un
 * refus en accord, ou un accord en attente, sans aucune trace de décision.
 */
#[TypeScript]
enum BankAssignmentStatut: string implements Statut
{
    case EnAttente = 'en-attente';
    case Accord = 'accord';
    case Refus = 'refus';

    public function libelle(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::Accord => 'Accord',
            self::Refus => 'Refus',
        };
    }

    /** @return list<static> */
    public function suivants(): array
    {
        return match ($this) {
            self::EnAttente => [self::Accord, self::Refus],
            // États terminaux : revenir en arrière suppose de retirer
            // l'orientation puis de la recréer, geste qui laisse une trace.
            self::Accord, self::Refus => [],
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
