<?php

namespace App\Models;

use App\Enums\RequisDocStatut;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Le statut est casté en enum : Larastan ne le déduit pas du schéma, qui ne
 * connaît qu'une colonne `varchar`.
 *
 * @property RequisDocStatut $status
 */
class RequisDoc extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * Les trois pièces exigées de tout dossier, dans l'ordre d'affichage.
     * Chaque client en reçoit une ligne « en-attente » dès sa création.
     *
     * @var array<string, string>
     */
    public const REQUIRED = [
        'identite' => "Pièce d'identité valide",
        'revenus' => 'Justificatifs de revenus',
        'bancaires' => 'Relevés bancaires',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'doc_id',
        'label',
        'status',
        'commentaire',
        'date_validation',
        'agent_name',
        'version',
        'submitted_label',
        'date',
        'taille',
        'file_path',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RequisDocStatut::class,
            'date_validation' => 'datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
