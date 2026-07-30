<?php

namespace App\Dto;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
#[MapInputName(SnakeCaseMapper::class)]
class DemandeData extends Data
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $clientId,
        public readonly bool    $submitted,
        public readonly ?string $submittedAt,
        public readonly string  $typeProjet,
        public readonly string  $natureProjet,
        public readonly ?float  $montant,
        public readonly string  $duree,
        public readonly float   $apport,
        public readonly string  $region,
        public readonly ?string $commune,
        public readonly ?string $adresseProjet,
        public readonly ?string $description,
    ) {}
}
