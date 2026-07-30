<?php

namespace App\Dto;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
#[MapInputName(SnakeCaseMapper::class)]
class NotificationData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $userId,
        public readonly ?string $clientId,
        public readonly string $titre,
        public readonly string $message,
        public readonly string $date,
        public readonly string $heure,
        public readonly bool $lu,
        public readonly string $type,
        public readonly ?string $targetPage,
        public readonly ?string $targetSub,
    ) {}
}
