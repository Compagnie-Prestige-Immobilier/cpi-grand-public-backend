<?php

namespace App\Dto;

use App\Enums\StatutCompte;
use App\Models\User;
use App\Services\StorageService;
use Spatie\LaravelData\Attributes\Computed;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
#[MapInputName(SnakeCaseMapper::class)]
class UserData extends Data
{
    /**
     * URL affichable de la photo de profil. Deux origines possibles :
     * une URL Google (stockée telle quelle, commence par http) servie
     * directement, ou un chemin R2 privé servi via lien signé.
     */
    #[Computed]
    public ?string $avatarUrl;

    /**
     * @param  string[]  $permissions
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $phone,
        public readonly ?string $employer,
        public readonly ?string $profileType,
        public readonly ?string $revenus,
        public readonly ?string $avatar,
        public readonly bool $needsOnboarding,
        public readonly ?string $role,          // nom du rôle Spatie : client / agent-cpi / super-admin
        public readonly array $permissions,   // permissions résolues côté serveur (getAllPermissions)
        public readonly ?string $clientId = null, // id de la fiche Client associée (utilisateurs clients)
        // État du compte : le frontend s'en sert pour router vers l'écran
        // d'attente ou de refus plutôt que vers l'espace client.
        public readonly ?StatutCompte $statutCompte = null,
        public readonly bool $emailVerifie = false,
        /** Motif du refus, communiqué pour que la personne sache quoi corriger. */
        public readonly ?string $motifRejet = null,
    ) {
        $this->avatarUrl = match (true) {
            $avatar === null => null,
            str_starts_with($avatar, 'http') => $avatar,
            default => app(StorageService::class)->temporaryUrl($avatar, 60),
        };
    }

    public static function fromModel(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            phone: $user->phone,
            employer: $user->employer,
            profileType: $user->profile_type,
            revenus: $user->revenus,
            avatar: $user->avatar,
            needsOnboarding: (bool) $user->needs_onboarding,
            role: $user->getRoleNames()->first(),
            permissions: $user->getAllPermissions()->pluck('name')->all(),
            clientId: $user->client?->id,
            statutCompte: $user->statut_compte,
            emailVerifie: $user->hasVerifiedEmail(),
            motifRejet: $user->motif_rejet,
        );
    }
}
