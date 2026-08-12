<?php

namespace App\Dto;

use App\Enums\BankAssignmentStatut;
use App\Models\BankAssignment;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Orientation d'un dossier vers une banque partenaire.
 *
 * `bankName` est aplati volontairement (au lieu d'imbriquer BankData) : l'UI
 * n'affiche que le nom, et une imbrication Bank ↔ BankAssignment créerait un
 * cycle de sérialisation.
 */
#[TypeScript]
#[MapInputName(SnakeCaseMapper::class)]
class BankAssignmentData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $clientId,
        public readonly string $bankId,
        public readonly string $bankName,
        public readonly BankAssignmentStatut $status,   // en-attente | accord | refus
    ) {}

    /**
     * Méthode de création magique (spatie/laravel-data) : `bank_name` n'existe
     * pas en colonne, il vient de la relation.
     */
    public static function fromBankAssignment(BankAssignment $assignment): self
    {
        return new self(
            id: $assignment->id,
            clientId: $assignment->client_id,
            bankId: $assignment->bank_id,
            bankName: $assignment->bank->name,
            status: $assignment->status,
        );
    }
}
