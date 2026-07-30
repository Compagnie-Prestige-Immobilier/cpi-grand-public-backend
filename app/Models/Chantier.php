<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chantier extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'client_id',
        'projet',
        'reference',
        'localisation',
        'chef_chantier',
        'entreprise',
        'date_debut',
        'date_livraison',
        'progression',
        'etape_actuelle',
        'statut',
        'derniere_maj',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_livraison' => 'date',
            'progression' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function tranches(): HasMany
    {
        return $this->hasMany(ChantierTranche::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(ChantierPublication::class);
    }

    public function medias(): HasMany
    {
        return $this->hasMany(ChantierMedia::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ChantierEvent::class);
    }
}
