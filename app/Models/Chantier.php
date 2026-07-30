<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chantier extends Model
{
    use HasUuids;

    /** États possibles du chantier — le défaut en base est « non-demarre ». */
    public const STATUTS = ['non-demarre', 'en-cours', 'suspendu', 'en-retard', 'termine', 'livre'];

    /** États d'une tranche de construction. */
    public const TRANCHE_ETATS = ['en-attente', 'en-cours', 'terminee'];

    /**
     * Construction financée en 4 tranches (35 / 30 / 30 / 5 %) — mêmes libellés
     * que le décaissement bancaire correspondant, mais persistés ici car le
     * chantier suit l'avancement des TRAVAUX, pas celui des versements.
     *
     * @return array<int, array{num: int, label: string, description: string, pct: int}>
     */
    public static function defaultTranches(): array
    {
        return [
            ['num' => 1, 'label' => 'Avance de démarrage', 'pct' => 35,
                'description' => 'À la signature et au démarrage du chantier — mobilisation des équipes.'],
            ['num' => 2, 'label' => 'Élévation des murs, poteaux, dalle et toiture', 'pct' => 30,
                'description' => "Libéré après certification de la mise hors d'eau."],
            ['num' => 3, 'label' => 'Second œuvre', 'pct' => 30,
                'description' => 'Menuiseries, plomberie, électricité et carrelage.'],
            ['num' => 4, 'label' => 'Remise des clés', 'pct' => 5,
                'description' => 'À la réception définitive du logement.'],
        ];
    }

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

    /**
     * Tranches de construction, toujours dans l'ordre T1…T4 — l'API doit être
     * déterministe.
     *
     * @return HasMany<ChantierTranche, $this>
     */
    public function tranches(): HasMany
    {
        return $this->hasMany(ChantierTranche::class)->orderBy('num');
    }

    /**
     * Fil de chantier, la publication la plus récente en tête.
     *
     * @return HasMany<ChantierPublication, $this>
     */
    public function publications(): HasMany
    {
        return $this->hasMany(ChantierPublication::class)->orderByDesc('date');
    }

    /**
     * Photos / vidéos, la plus récente en tête.
     *
     * @return HasMany<ChantierMedia, $this>
     */
    public function medias(): HasMany
    {
        return $this->hasMany(ChantierMedia::class)->orderByDesc('date');
    }

    /**
     * Calendrier de chantier, par date croissante (le prochain rendez-vous
     * d'abord).
     *
     * @return HasMany<ChantierEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ChantierEvent::class)->orderBy('date');
    }
}
