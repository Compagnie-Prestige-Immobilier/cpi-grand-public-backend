<?php

namespace App\Dto;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un événement du calendrier de chantier (visite, inspection, réception…).
 *
 * `visibleClient` = false : l'événement reste interne au personnel CPI et
 * n'est jamais renvoyé par GET /client/mon-chantier.
 */
#[TypeScript]
#[MapInputName(SnakeCaseMapper::class)]
class ChantierEventData extends Data
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $chantierId,
        public readonly string  $titre,
        public readonly string  $type,
        public readonly ?string $date,
        public readonly ?string $heure,
        public readonly string  $description,
        public readonly string  $statut,      // prevu, confirme, realise, reporte, annule
        public readonly bool    $visibleClient,
    ) {}
}
