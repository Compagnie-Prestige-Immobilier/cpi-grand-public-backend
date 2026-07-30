<?php

namespace App\Dto;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
#[MapInputName(SnakeCaseMapper::class)]
class ChantierData extends Data
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $clientId,
        public readonly ?string $projet,
        public readonly ?string $reference,
        public readonly ?string $localisation,
        public readonly ?string $chefChantier,
        public readonly ?string $entreprise,
        public readonly ?string $dateDebut,
        public readonly ?string $dateLivraison,
        public readonly int     $progression,
        public readonly string  $etapeActuelle,
        public readonly string  $statut,
        public readonly ?string $derniereMaj,
        public readonly ?array  $tranches = null,     // array of ChantierTranche rows
        public readonly ?array  $publications = null, // array of ChantierPublication rows
        public readonly ?array  $medias = null,       // array of ChantierMedia rows
        public readonly ?array  $events = null,       // array of ChantierEvent rows
    ) {}
}
