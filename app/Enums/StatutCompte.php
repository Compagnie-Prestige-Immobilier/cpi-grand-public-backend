<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Cycle de vie d'un compte, de l'inscription à l'accès effectif.
 *
 * Aucun compte n'accède à la plateforme avant qu'un administrateur ne l'ait
 * validé : l'inscription seule ne donne droit à rien. Deux filtres successifs,
 * qui ne répondent pas à la même question :
 *
 *   1. la vérification d'e-mail prouve que l'adresse existe et appartient bien
 *      à la personne — c'est automatique ;
 *   2. la validation administrative est un jugement humain sur les
 *      informations déclarées, qu'aucune règle ne saurait remplacer.
 *
 * Un refus n'est pas définitif : le motif est communiqué, la personne corrige
 * ses informations et repasse en file. Une faute de frappe dans un numéro de
 * téléphone ne doit pas coûter un compte.
 */
#[TypeScript]
enum StatutCompte: string implements Statut
{
    case EmailAVerifier = 'email-a-verifier';
    case EnAttenteValidation = 'en-attente-validation';
    case Valide = 'valide';
    case Rejete = 'rejete';

    public function libelle(): string
    {
        return match ($this) {
            self::EmailAVerifier => 'E-mail à vérifier',
            self::EnAttenteValidation => 'En attente de validation',
            self::Valide => 'Validé',
            self::Rejete => 'Refusé',
        };
    }

    /**
     * États atteignables depuis celui-ci.
     *
     * @return list<static>
     */
    public function suivants(): array
    {
        return match ($this) {
            // La vérification d'e-mail met d'office en file d'attente.
            self::EmailAVerifier => [self::EnAttenteValidation],
            // L'administrateur tranche.
            self::EnAttenteValidation => [self::Valide, self::Rejete],
            // Le refus est réversible : la personne corrige et resoumet.
            self::Rejete => [self::EnAttenteValidation],
            // Un compte validé le reste. Le suspendre est un autre sujet
            // (désactivation), volontairement hors de ce cycle.
            self::Valide => [],
        };
    }

    public function peutAllerVers(Statut $cible): bool
    {
        return in_array($cible, $this->suivants(), true);
    }

    /** Le compte a-t-il accès à la plateforme ? */
    public function donneAcces(): bool
    {
        return $this === self::Valide;
    }
}
