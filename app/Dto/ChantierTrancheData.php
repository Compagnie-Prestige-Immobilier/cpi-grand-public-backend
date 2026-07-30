<?php

namespace App\Dto;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Une tranche de construction du chantier (35 / 30 / 30 / 5 %).
 *
 * `num` est le numéro affiché (T1…T4), pas un index de tableau : c'est lui
 * que la route POST /staff/chantiers/{client}/tranche/{num}/validate reçoit.
 */
#[TypeScript]
#[MapInputName(SnakeCaseMapper::class)]
class ChantierTrancheData extends Data
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $chantierId,
        public readonly int     $num,
        public readonly string  $label,
        public readonly ?string $description,
        public readonly int     $pct,
        public readonly string  $etat,        // terminee, en-cours, en-attente
        public readonly ?string $date,
        public readonly ?string $comment,
    ) {}
}
