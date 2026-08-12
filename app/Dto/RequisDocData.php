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

#[TypeScript]
#[MapInputName(SnakeCaseMapper::class)]
class RequisDocData extends Data
{
    /**
     * Lien signé de courte durée vers la pièce déposée — le bucket R2 est privé,
     * `filePath` seule ne permet rien. Régénérée à chaque lecture, jamais stockée.
     */
    #[Computed]
    public ?string $fileUrl;

    public function __construct(
        public readonly string $id,
        public readonly string $clientId,
        public readonly string $docId,          // identite, revenus, bancaires
        public readonly string $label,
        public readonly string $status,
        public readonly ?string $commentaire,
        public readonly ?string $dateValidation,
        public readonly ?string $agentName,
        public readonly int $version,
        public readonly ?string $submittedLabel,
        public readonly ?string $date,
        public readonly ?string $taille,
        // Chemin interne du fichier sur R2. Il ne permet aucun accès à lui
        // seul — le bucket est privé, tout passe par `fileUrl` signée — mais il
        // révèle l'arborescence de stockage et les identifiants internes à
        // quiconque lit la réponse JSON. Les DEUX attributs sont nécessaires :
        // `Data\Hidden` retire la clé de la réponse, `TypeScript\Hidden` la
        // retire du type généré.
        #[Hidden, TypeScriptHidden]
        public readonly ?string $filePath,
    ) {
        $this->fileUrl = $filePath === null
            ? null
            : app(StorageService::class)->temporaryUrl($filePath);
    }
}
