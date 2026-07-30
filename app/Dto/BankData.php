<?php

namespace App\Dto;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
#[MapInputName(SnakeCaseMapper::class)]
class BankData extends Data
{
    /**
     * @param string[]|null $products
     * @param BankAssignmentData[]|null $assignments
     */
    public function __construct(
        public readonly string  $id,
        public readonly string  $name,
        public readonly ?string $conventionDate,
        public readonly ?string $validity,
        public readonly ?array  $products,
        public readonly ?string $rate,
        public readonly ?string $contact,
        public readonly string  $color,
        // Orientations de dossiers vers cette banque (relation chargée par
        // GET /staff/banks) : le personnel reconstitue la carte des dossiers
        // orientés sans appel supplémentaire. Aucune imbrication en retour
        // vers BankData — cf. BankAssignmentData.
        #[DataCollectionOf(BankAssignmentData::class)]
        public readonly ?array  $assignments = null,
    ) {}
}
