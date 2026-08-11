<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Types réels des attributs, tels que produits par `casts()`.
 *
 * Sans ces annotations, l'analyse statique retombe sur le type brut de la
 * colonne : `submitted_at` y passait pour une chaîne alors que le cast en fait
 * un Carbon, et tout appel de méthode de date était signalé à tort.
 *
 * @property string $id
 * @property string $client_id
 * @property bool $submitted
 * @property Carbon|null $submitted_at
 * @property string|null $type_projet
 * @property string|null $nature_projet
 * @property string|null $montant
 * @property string|null $duree
 * @property string|null $apport
 * @property string|null $region
 * @property string|null $commune
 * @property string|null $adresse_projet
 * @property string|null $description
 */
class Demande extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'submitted',
        'submitted_at',
        'type_projet',
        'nature_projet',
        'montant',
        'duree',
        'apport',
        'region',
        'commune',
        'adresse_projet',
        'description',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted' => 'boolean',
            'submitted_at' => 'datetime',
            'montant' => 'decimal:2',
            'apport' => 'decimal:2',
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
