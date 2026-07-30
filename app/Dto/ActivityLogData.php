<?php

namespace App\Dto;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Entrée du journal d'activité (table activity_log de Spatie Activity Log).
 * Utilisée par HistoriqueController dans une phase ultérieure.
 */
#[TypeScript]
#[MapInputName(SnakeCaseMapper::class)]
class ActivityLogData extends Data
{
    /**
     * @param array<string, mixed>|null $properties
     */
    public function __construct(
        public readonly int     $id,            // la table du package garde une PK auto-increment
        public readonly ?string $logName,
        public readonly string  $description,
        public readonly ?string $subjectType,
        public readonly ?string $subjectId,
        public readonly ?string $causerType,
        public readonly ?string $causerId,
        public readonly ?string $event,
        public readonly ?array  $properties,
        public readonly ?string $createdAt,
    ) {}
}
