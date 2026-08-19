<?php

namespace App\Http\Controllers\Api\Auth;

use App\Dto\UserData;
use App\Enums\StatutCompte;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * Empreinte d'un mot de passe qui n'appartient à personne.
     *
     * Comparée quand l'adresse est inconnue, pour que le temps de réponse ne
     * distingue pas « compte inexistant » de « mot de passe faux » — sinon
     * l'énumération redevient possible au chronomètre, malgré un message
     * uniforme.
     */
    private const HASH_FACTICE = '$2y$12$SGdUeIA9AmSHOOJoiTPnzOoRlXOKgLM0LnJd7ptYuGGD2kK5LSb1S';

    public function __construct(private readonly StorageService $storage) {}

    /**
     * POST /auth/register — inscription client (email + mot de passe).
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', 'string', Password::defaults()],
            'phone' => 'nullable|string|max:50',
        ]);

        // Une inscription écrit dans users, model_has_roles, clients, puis —
        // par `Client::booted()` — requis_docs, decaissements, chantiers et
        // chantier_tranches. Sans transaction, un échec à mi-parcours laissait
        // un compte sans dossier, ou un dossier sans pièces : l'utilisateur
        // pouvait se connecter dans une application qui ne savait plus quoi lui
        // montrer, et rien ne permettait de rejouer la création.
        $user = DB::transaction(function () use ($validated): User {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone' => $validated['phone'] ?? null,
                // Statut posé EXPLICITEMENT et non laissé au défaut SQL :
                // `create()` ne relit pas la ligne, l'instance en mémoire
                // ignorerait la valeur par défaut et la réponse d'inscription
                // renverrait `statutCompte: null` — le frontend ne saurait pas
                // qu'il doit afficher l'écran d'attente.
                'statut_compte' => StatutCompte::EmailAVerifier,
            ]);
            $user->assignRole('client');

            Client::create([
                'user_id' => $user->id,
                'name' => $user->name,
                'ref' => Client::generateRef(),
                'email' => $user->email,
                'phone' => $user->phone,
                'date_inscription' => now(),
            ]);

            return $user;
        });

        // Le courriel part APRÈS la transaction : l'envoi peut être lent ou
        // échouer, et il n'a aucune raison de faire échouer une inscription
        // déjà écrite en base.
        $user->sendEmailVerificationNotification();

        // Un jeton est délivré malgré tout : sans lui, la personne ne pourrait
        // même pas consulter l'écran qui lui explique ce qu'elle attend. Les
        // routes de l'espace client restent fermées tant que le compte n'est
        // pas validé (middleware `compte.valide`).
        $token = $user->createToken('api-token')->plainTextToken;

        return $this->authResponse($user, $token, 201);
    }

    /**
     * POST /auth/login — connexion unifiée (clients, agents, admins).
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        // Une seule réponse pour tous les échecs : compte inexistant, mot de
        // passe faux, ou compte Google sans mot de passe local.
        //
        // Distinguer « Ce compte utilise Google » (422) de « Identifiants
        // invalides » (401) permettait d'énumérer les comptes : sur une
        // plateforme de financement, savoir qu'une adresse a un dossier chez CPI
        // est déjà une information. Le prix est un message moins précis pour
        // l'utilisateur Google — l'écran de connexion propose le bouton Google
        // juste à côté.
        //
        // `Hash::check` est appelé même sans utilisateur pour que la durée de
        // réponse ne trahisse pas l'existence du compte.
        $empreinte = $user !== null && $user->password !== null
            ? $user->password
            : self::HASH_FACTICE;

        $motDePasseValide = Hash::check($validated['password'], $empreinte);

        if ($user === null || $user->password === null || ! $motDePasseValide) {
            return response()->json(['message' => 'Identifiants invalides.'], 401);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return $this->authResponse($user, $token);
    }

    /**
     * POST /auth/logout — révoque le token Sanctum courant.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var PersonalAccessToken $token */
        $token = $request->user()->currentAccessToken();
        $token->delete();

        return response()->json(['message' => 'Déconnecté.']);
    }

    /**
     * GET /auth/me — utilisateur courant + rôle + permissions.
     */
    public function me(Request $request): JsonResponse
    {
        return $this->authResponse($request->user());
    }

    /**
     * PUT /auth/mon-compte — corrige les informations déclarées puis
     * resoumet un compte refusé.
     *
     * Le refus donne un motif ; la personne corrige et repasse en file. Sans
     * cette route, un refus serait définitif dans les faits — il n'existerait
     * aucun moyen de revenir dessus autrement qu'en rouvrant un ticket support.
     */
    public function updateMonCompte(Request $request): JsonResponse
    {
        $user = $request->user();

        // Précondition explicite, et non un appel à TransitionStatut : ce
        // dernier traite « déjà dans cet état » comme un no-op réussi (rejouer
        // une action n'est pas une faute), ce qui aurait laissé un compte déjà
        // EN ATTENTE resoumettre sans rien y gagner. Seul un compte REFUSÉ a
        // quelque chose à corriger.
        abort_unless(
            $user->statut_compte === StatutCompte::Rejete,
            409,
            'Seul un compte refusé peut être corrigé et resoumis.',
        );

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|nullable|string|max:50',
            'employer' => 'sometimes|nullable|string|max:255',
            'profile_type' => 'sometimes|nullable|in:fonctionnaire,prive,autre',
            'revenus' => 'sometimes|nullable|string|max:50',
        ]);

        $user->update([
            ...$validated,
            'statut_compte' => StatutCompte::EnAttenteValidation,
            'motif_rejet' => null,
        ]);

        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->event('compte-resoumis')
            ->log("{$user->name} a corrigé son compte et l'a resoumis");

        return $this->authResponse($user->refresh());
    }

    /**
     * POST /auth/onboarding — complète le profil (utilisateurs Google, mais
     * applicable à tout utilisateur authentifié).
     */
    public function completeOnboarding(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'phone' => 'required|string',
            'employer' => 'required|string',
            'profile_type' => 'required|in:fonctionnaire,prive,autre',
            'revenus' => 'required|string',
        ]);

        $user->update([
            'phone' => $validated['phone'],
            'employer' => $validated['employer'],
            'profile_type' => $validated['profile_type'],
            'revenus' => $validated['revenus'],
            'needs_onboarding' => false,
        ]);

        // Met à jour la fiche Client associée
        $client = $user->client;
        if ($client) {
            $client->update([
                'phone' => $validated['phone'],
                'employer' => $validated['employer'],
            ]);
        }

        return response()->json([
            'data' => [
                'user' => UserData::from($user->refresh()),
            ],
        ]);
    }

    /**
     * POST /auth/avatar — photo de profil (multipart), tout utilisateur authentifié.
     *
     * Stockée sur R2 (privé) sous avatars/{userId}.{ext}, servie via `avatarUrl`
     * signée dans UserData. Remplace le stockage localStorage côté frontend.
     */
    public function updateAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'file' => 'required|file|max:5120|mimes:jpg,jpeg,png,webp',
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];

        // Remplacement : ne supprimer que les fichiers R2 (jamais une URL Google).
        if ($user->avatar !== null && ! str_starts_with($user->avatar, 'http')) {
            $this->storage->delete($user->avatar);
        }

        $user->update([
            'avatar' => $this->storage->uploadAvatar($user->id, $file),
        ]);

        return response()->json(['data' => UserData::from($user->refresh())]);
    }

    /**
     * DELETE /auth/avatar — retire la photo de profil.
     */
    public function removeAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar !== null && ! str_starts_with($user->avatar, 'http')) {
            $this->storage->delete($user->avatar);
        }

        $user->update(['avatar' => null]);

        return response()->json(['data' => UserData::from($user->refresh())]);
    }

    /**
     * GET /auth/onboarding-status — l'utilisateur a-t-il complété son profil ?
     */
    public function onboardingStatus(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'needsOnboarding' => (bool) $request->user()->needs_onboarding,
            ],
        ]);
    }

    /**
     * Corps de réponse unifié : { data: { user, role, permissions[, token] } }.
     */
    private function authResponse(User $user, ?string $token = null, int $status = 200): JsonResponse
    {
        $data = [
            'user' => UserData::from($user),
            'role' => $user->getRoleNames()->first(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ];

        if ($token !== null) {
            $data['token'] = $token;
        }

        return response()->json(['data' => $data], $status);
    }
}
