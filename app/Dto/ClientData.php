<?php

namespace App\Dto;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
#[MapInputName(SnakeCaseMapper::class)]
class ClientData extends Data
{
    /**
     * @param  RequisDocData[]|null  $requisDocs
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $ref,
        public readonly string $statut,          // NOT NULL avec défaut en base → non nullable
        public readonly int $progression,     // NOT NULL avec défaut en base → non nullable
        public readonly ?string $projectNom,      // maps from project_nom via SnakeCaseMapper
        public readonly ?string $adresse,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?string $employer,
        public readonly ?string $fonction,
        public readonly ?string $conseiller,
        public readonly ?string $banque,
        public readonly int $dossierEtape,    // NOT NULL avec défaut en base → non nullable
        public readonly ?string $dateInscription, // colonne nullable (STEP 8.2)
        public readonly ?DemandeData $demande = null,   // nested DemandeData (relation chargée)
        #[DataCollectionOf(RequisDocData::class)]
        public readonly ?array $requisDocs = null, // array of RequisDocData (relation chargée)
    ) {}
}
