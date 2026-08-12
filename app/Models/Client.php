<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Client extends Model
{
    use HasUuids, LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'ref',
        'statut',
        'progression',
        'project_nom',
        'adresse',
        'email',
        'phone',
        'employer',
        'fonction',
        'conseiller',
        'conseiller_id',
        'banque',
        'dossier_etape',
        'date_inscription',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'progression' => 'integer',
            'dossier_etape' => 'integer',
            'date_inscription' => 'date',
        ];
    }

    /**
     * Tout dossier naît avec ses trois pièces requises « en-attente » : le staff
     * doit les voir dès l'ouverture du dossier, sans attendre que le client ait
     * visité son espace documents. Même logique pour l'état de décaissement et
     * pour le chantier : les modules correspondants doivent trouver une ligne
     * dès l'ouverture du dossier.
     */
    protected static function booted(): void
    {
        static::created(function (Client $client): void {
            $client->ensureRequiredDocs();
            $client->ensureDecaissement();
            $client->ensureChantier();
        });
    }

    /**
     * Crée les lignes manquantes parmi les trois pièces requises et les renvoie
     * dans l'ordre canonique. Idempotent.
     *
     * @return Collection<int, RequisDoc>
     */
    public function ensureRequiredDocs(): Collection
    {
        foreach (RequisDoc::REQUIRED as $docId => $label) {
            RequisDoc::firstOrCreate(
                ['client_id' => $this->id, 'doc_id' => $docId],
                ['label' => $label, 'status' => 'en-attente', 'version' => 0],
            );
        }

        return $this->requisDocs()->get();
    }

    /**
     * Rattrapage pour les dossiers antérieurs à la création automatique de
     * l'état de décaissement. Idempotent.
     */
    public function ensureDecaissement(): Decaissement
    {
        // `first()` puis `create()` laissait passer deux insertions concurrentes
        // sur un dossier fraîchement créé : le dossier se retrouvait avec deux
        // lignes de décaissement, et `first()` en renvoyait une au hasard.
        // `firstOrCreate` rattrape la violation d'unicité et relit la ligne
        // gagnante au lieu de remonter une 500.
        // create() ne remonte pas les défauts SQL (foncier) : refresh() obligatoire.
        return $this->decaissement()->firstOrCreate([], [
            'tranches' => Decaissement::defaultTranches(),
        ])->refresh();
    }

    /**
     * Le chantier du dossier, avec ses quatre tranches de construction.
     * Rattrapage pour les dossiers antérieurs au module. Idempotent.
     *
     * Un chantier existe pour TOUT dossier : il démarre « non démarré » à 0 %.
     * L'espace client sait alors toujours quoi afficher, et l'entrée de menu
     * « Mon chantier » se décide sur le statut, pas sur l'existence de la ligne.
     */
    public function ensureChantier(): Chantier
    {
        // Même course que pour le décaissement (voir ensureDecaissement).
        // create() ne remonte pas les défauts SQL (progression, statut,
        // etape_actuelle) : refresh() obligatoire.
        $chantier = $this->chantier()->firstOrCreate([])->refresh();

        foreach (Chantier::defaultTranches() as $tranche) {
            ChantierTranche::firstOrCreate(
                ['chantier_id' => $chantier->id, 'num' => $tranche['num']],
                $tranche,
            );
        }

        return $chantier;
    }

    /**
     * Génère une référence de dossier unique : CPI-{year}-{suffix}.
     */
    public static function generateRef(): string
    {
        do {
            $ref = 'CPI-'.now()->year.'-'.strtoupper(Str::random(5));
        } while (static::query()->where('ref', $ref)->exists());

        return $ref;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * L'utilisateur (compte de connexion) associé à ce client.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Le conseiller CPI assigné à ce client.
     */
    public function conseillerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conseiller_id');
    }

    /**
     * La demande de financement du client.
     *
     * @return HasOne<Demande, $this>
     */
    public function demande(): HasOne
    {
        return $this->hasOne(Demande::class);
    }

    /**
     * Les documents requis du client, toujours dans l'ordre d'affichage
     * (identite, revenus, bancaires) — l'API doit être déterministe.
     *
     * @return HasMany<RequisDoc, $this>
     */
    public function requisDocs(): HasMany
    {
        $cases = '';
        foreach (array_keys(RequisDoc::REQUIRED) as $rank => $docId) {
            $cases .= " when '{$docId}' then {$rank}";
        }

        return $this->hasMany(RequisDoc::class)
            ->orderByRaw('case doc_id'.$cases.' else 99 end');
    }

    /**
     * Les documents CPI du client.
     *
     * @return HasMany<CpiDoc, $this>
     */
    public function cpiDocs(): HasMany
    {
        return $this->hasMany(CpiDoc::class);
    }

    /**
     * Les affectations bancaires du client.
     *
     * @return HasMany<BankAssignment, $this>
     */
    public function bankAssignments(): HasMany
    {
        return $this->hasMany(BankAssignment::class);
    }

    /**
     * L'état de décaissement du client.
     *
     * @return HasOne<Decaissement, $this>
     */
    public function decaissement(): HasOne
    {
        return $this->hasOne(Decaissement::class);
    }

    /**
     * Le chantier du client.
     *
     * @return HasOne<Chantier, $this>
     */
    public function chantier(): HasOne
    {
        return $this->hasOne(Chantier::class);
    }

    /**
     * Les notifications adressées à ce client.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
