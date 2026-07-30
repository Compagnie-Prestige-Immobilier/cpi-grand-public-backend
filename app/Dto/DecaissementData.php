<?php

namespace App\Dto;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
#[MapInputName(SnakeCaseMapper::class)]
class DecaissementData extends Data
{
    /**
     * @param  bool[]  $foncier
     * @param  array<int, array<string, mixed>>  $tranches
     */
    public function __construct(
        public readonly string $id,
        public readonly string $clientId,
        public readonly float $terrainMontant,
        public readonly bool $terrainDecaisse,
        public readonly ?string $terrainDate,
        public readonly array $foncier,
        public readonly bool $constructionActive,
        public readonly float $constructionMontant,
        public readonly array $tranches,
    ) {}
}
