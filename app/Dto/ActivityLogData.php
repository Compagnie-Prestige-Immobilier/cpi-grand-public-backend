<?php

namespace App\Dto;

use App\Models\Client;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Entrée du journal d'activité (table activity_log de Spatie Activity Log).
 *
 * Le journal est écrit par TOUS les contrôleurs depuis la phase 1
 * (`activity()->causedBy(...)->performedOn($client)->withProperties([...])`) :
 * ce DTO se contente de l'exposer fidèlement. Il dénormalise trois informations
 * que l'interface affiche ou filtre systématiquement et qui, sinon, coûteraient
 * une requête de plus par ligne : le nom de l'auteur (`causerName`), son rôle
 * (`causerRole`, le filtre « Client / Agent CPI / Administrateur » de l'écran
 * Historique) et le nom du dossier concerné (`clientName`). Toutes trois
 * exigent que les relations morph `causer` (avec ses rôles) et `subject`
 * soient chargées — cf. HistoriqueController.
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
        public readonly ?string $causerName,    // dénormalisé depuis la relation morph `causer`
        public readonly ?string $causerRole,    // rôle Spatie de l'auteur : client / agent-cpi / super-admin
        public readonly ?string $clientId,      // subjectId, uniquement quand le sujet est un Client
        public readonly ?string $clientName,    // dénormalisé depuis la relation morph `subject`
        public readonly ?string $event,
        public readonly ?array  $properties,
        public readonly ?string $createdAt,
    ) {}

    /**
     * Méthode de création magique de spatie/laravel-data : `ActivityLogData::from()`
     * et `::collect()` la choisissent automatiquement pour un modèle Activity.
     *
     * `properties` est une Collection côté modèle — on la remet à plat ; le
     * `created_at` est formaté comme partout ailleurs dans l'API (Y-m-d H:i:s).
     */
    public static function fromModel(Activity $activity): self
    {
        $causer = $activity->causer;
        $subject = $activity->subject;
        $subjectId = $activity->subject_id === null ? null : (string) $activity->subject_id;

        return new self(
            id: (int) $activity->id,
            logName: $activity->log_name,
            description: $activity->description,
            subjectType: $activity->subject_type,
            subjectId: $subjectId,
            causerType: $activity->causer_type,
            causerId: $activity->causer_id === null ? null : (string) $activity->causer_id,
            causerName: $causer instanceof User ? $causer->name : null,
            causerRole: $causer instanceof User ? $causer->getRoleNames()->first() : null,
            clientId: $activity->subject_type === Client::class ? $subjectId : null,
            clientName: $subject instanceof Client ? $subject->name : null,
            event: $activity->event,
            properties: $activity->properties->toArray(),
            createdAt: $activity->created_at?->format('Y-m-d H:i:s'),
        );
    }
}
