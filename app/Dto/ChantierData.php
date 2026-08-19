<?php

namespace App\Dto;

use App\Enums\ChantierStatut;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Le chantier d'un dossier. Une ligne est provisionnée à la création du client
 * (cf. Client::ensureChantier) : le DTO n'est donc jamais null côté API, il
 * démarre simplement « non démarré » à 0 %.
 *
 * Les collections imbriquées ne sont renseignées que lorsque les relations sont
 * chargées (GET /client/mon-chantier, GET /staff/chantiers/{client}).
 */
#[TypeScript]
#[MapInputName(SnakeCaseMapper::class)]
class ChantierData extends Data
{
    /**
     * @param  ChantierTrancheData[]|null  $tranches
     * @param  ChantierPublicationData[]|null  $publications
     * @param  ChantierMediaData[]|null  $medias
     * @param  ChantierEventData[]|null  $events
     */
    public function __construct(
        public readonly string $id,
        public readonly string $clientId,
        public readonly ?string $projet,
        public readonly ?string $reference,
        public readonly ?string $localisation,
        public readonly ?string $chefChantier,
        public readonly ?string $entreprise,
        public readonly ?string $dateDebut,
        public readonly ?string $dateLivraison,
        public readonly int $progression,
        public readonly string $etapeActuelle,
        public readonly ChantierStatut $statut,
        public readonly ?string $derniereMaj,
        #[DataCollectionOf(ChantierTrancheData::class)]
        public readonly ?array $tranches = null,
        #[DataCollectionOf(ChantierPublicationData::class)]
        public readonly ?array $publications = null,
        #[DataCollectionOf(ChantierMediaData::class)]
        public readonly ?array $medias = null,
        #[DataCollectionOf(ChantierEventData::class)]
        public readonly ?array $events = null,
    ) {}
}
