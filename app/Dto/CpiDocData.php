<?php

namespace App\Dto;

use App\Services\StorageService;
use Spatie\LaravelData\Attributes\Computed;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
#[MapInputName(SnakeCaseMapper::class)]
class CpiDocData extends Data
{
    /**
     * Lien signé de courte durée vers le fichier réel — le bucket R2 est privé,
     * `filePath` seule ne permet rien. Régénérée à chaque lecture, jamais stockée.
     */
    #[Computed]
    public ?string $fileUrl;

    public function __construct(
        public readonly string $id,
        public readonly string $clientId,
        public readonly string $categorie,
        public readonly string $nom,
        public readonly ?string $reference,
        public readonly string $dateCreation,
        public readonly ?string $datePublication,
        public readonly string $version,
        public readonly string $status,
        public readonly string $auteur,
        public readonly ?string $fichier,
        public readonly ?string $filePath,
        public readonly ?string $commentaire,
        public readonly bool $visibleClient,
        public readonly bool $signatureRequise,
        public readonly ?string $taille,
        public readonly ?string $format,
    ) {
        $this->fileUrl = $filePath === null
            ? null
            : app(StorageService::class)->temporaryUrl($filePath);
    }
}
