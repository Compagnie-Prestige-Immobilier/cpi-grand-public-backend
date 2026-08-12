<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Les deux colonnes JSON ne sont pas déductibles du schéma (`json` → string) :
 * on décrit ici le type réel produit par les casts, pour l'analyse statique.
 *
 * @property array<int, bool> $foncier
 * @property array<int, array<string, mixed>> $tranches
 */
class Decaissement extends Model
{
    use HasUuids, SoftDeletes;

    /**
     * Construction financée en 4 tranches (35 / 30 / 30 / 5 %) — la colonne
     * `tranches` naît donc avec quatre entrées non validées. Le pourcentage et
     * le libellé restent côté front (CONSTRUCTION_TRANCHES) : seul l'état de
     * validation est persistant.
     *
     * @return array<int, array{validated: bool}>
     */
    public static function defaultTranches(): array
    {
        return array_fill(0, 4, ['validated' => false]);
    }

    /**
     * Parcours foncier en 5 étapes ; la première (inscription sur la
     * plateforme) est acquise dès la création du dossier — c'est aussi le
     * défaut de la colonne en base.
     *
     * @return array<int, bool>
     */
    public static function defaultFoncier(): array
    {
        return [true, false, false, false, false];
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'terrain_montant',
        'terrain_decaisse',
        'terrain_date',
        'foncier',
        'construction_active',
        'construction_montant',
        'tranches',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'terrain_montant' => 'decimal:2',
            'terrain_decaisse' => 'boolean',
            'terrain_date' => 'datetime',
            'foncier' => 'array',
            'construction_active' => 'boolean',
            'construction_montant' => 'decimal:2',
            'tranches' => 'array',
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
