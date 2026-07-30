<?php

namespace App\Dto;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Une publication du fil de chantier (actualité, commentaire, retard…).
 *
 * `visibleClient` = false : la publication reste interne au personnel CPI et
 * n'est jamais renvoyée par GET /client/mon-chantier.
 */
#[TypeScript]
#[MapInputName(SnakeCaseMapper::class)]
class ChantierPublicationData extends Data
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $chantierId,
        public readonly int     $phase,
        public readonly string  $titre,
        public readonly string  $description,
        public readonly ?string $date,
        public readonly string  $heure,
        public readonly string  $auteur,
        public readonly string  $type,
        public readonly bool    $visibleClient,
    ) {}
}
