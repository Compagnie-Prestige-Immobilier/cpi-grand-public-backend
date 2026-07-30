<?php

namespace App\Dto;

use App\Services\StorageService;
use Spatie\LaravelData\Attributes\Computed;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\Hidden as TypeScriptHidden;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Une photo / vidéo de chantier.
 *
 * La colonne `url` contient la CLÉ de stockage R2 (bucket privé), jamais une
 * URL publique : elle est marquée #[Hidden] et ne sort donc pas du DTO. Le seul
 * accès au fichier est `fileUrl`, un lien signé de courte durée régénéré à
 * chaque lecture — exactement le motif de RequisDocData.
 */
#[TypeScript]
#[MapInputName(SnakeCaseMapper::class)]
class ChantierMediaData extends Data
{
    /** Lien signé de courte durée vers le média — jamais stocké en base. */
    #[Computed]
    public ?string $fileUrl;

    public function __construct(
        public readonly string  $id,
        public readonly string  $chantierId,
        public readonly string  $type,        // photo, video
        public readonly string  $titre,
        public readonly ?string $description,
        public readonly ?string $date,
        public readonly int     $phase,
        public readonly string  $auteur,
        public readonly ?string $bg,
        public readonly bool    $visibleClient,
        // Les DEUX attributs sont nécessaires : `Data\Hidden` retire la clé de
        // la réponse JSON, `TypeScript\Hidden` la retire du type généré. Sans
        // le second, generated.d.ts annoncerait un champ qui n'existe pas.
        #[Hidden]
        #[TypeScriptHidden]
        public readonly string  $url = '',    // clé R2 privée — jamais exposée
    ) {
        $this->fileUrl = $url === ''
            ? null
            : app(StorageService::class)->temporaryUrl($url);
    }
}
