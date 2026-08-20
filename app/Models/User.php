<?php

namespace App\Models;

use App\Enums\StatutCompte;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Types réels des attributs castés.
 *
 * Sans ces annotations, l'analyse statique retombe sur le type brut de la
 * colonne : `statut_compte` y passe pour une chaîne alors que le cast en fait
 * un enum, et tout appel de méthode dessus est signalé à tort.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property StatutCompte $statut_compte
 * @property string|null $motif_rejet
 * @property string|null $validated_by
 * @property Carbon|null $validated_at
 * @property bool $needs_onboarding
 * @property string|null $phone
 * @property string|null $employer
 * @property string|null $profile_type
 * @property string|null $revenus
 * @property string|null $avatar
 * @property string|null $google_id
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'employer',
        'profile_type',
        'revenus',
        'avatar',
        'google_id',
        'needs_onboarding',
        'statut_compte',
        'motif_rejet',
        // Champs posés par le SYSTÈME, jamais issus d'une saisie : aucun
        // validateur n'accepte ces clés. Ils figurent ici parce que
        // `User::create()` les ignorait silencieusement — un compte du
        // personnel ressortait alors avec une adresse non vérifiée, et le
        // compte Google se voyait redemander une vérification déjà faite.
        'email_verified_at',
        'validated_by',
        'validated_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'needs_onboarding' => 'boolean',
            'statut_compte' => StatutCompte::class,
            'validated_at' => 'datetime',
        ];
    }

    /**
     * Le profil client associé à cet utilisateur.
     *
     * @return HasOne<Client, $this>
     */
    public function client(): HasOne
    {
        return $this->hasOne(Client::class);
    }

    /**
     * Le compte peut-il accéder à la plateforme ?
     *
     * Un seul point de vérité pour le middleware, les policies et les réponses
     * d'authentification : la règle ne doit pas se réécrire à trois endroits.
     */
    public function compteValide(): bool
    {
        return $this->statut_compte->donneAcces();
    }

    /**
     * Le personnel CPI n'est pas soumis à la validation administrative : ces
     * comptes sont créés par un administrateur, qui les valide de fait en les
     * créant. Sans cette exception, un agent nouvellement créé serait bloqué
     * en attendant qu'on le valide lui-même.
     */
    public function estPersonnel(): bool
    {
        return $this->hasAnyRole(['agent-cpi', 'super-admin']);
    }
}
