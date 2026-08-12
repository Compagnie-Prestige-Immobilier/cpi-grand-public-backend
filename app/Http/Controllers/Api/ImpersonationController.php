<?php

namespace App\Http\Controllers\Api;

use App\Dto\UserData;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Prise en main du compte d'un client par le personnel CPI.
 *
 * Beaucoup de clients maîtrisent mal l'outil informatique : voir leur écran tel
 * qu'ils le voient est souvent le seul moyen de comprendre leur problème.
 *
 * Pourquoi une implémentation maison plutôt que lab404/laravel-impersonate :
 * ce paquet repose sur la SESSION et sur `quietLogin()` / `quietLogout()`, deux
 * méthodes de SessionGuard. Ici l'authentification passe par des jetons Sanctum
 * (`Illuminate\Auth\RequestGuard`), qui n'ont ni l'un ni l'autre — et son
 * `take()` avale l'exception pour renvoyer `false`, donc l'échec aurait été
 * SILENCIEUX. La prise en main émet donc un jeton dédié pour la cible.
 */
class ImpersonationController extends Controller
{
    /** Préfixe du nom de jeton : il porte aussi l'identité du vrai opérateur. */
    public const PREFIXE = 'impersonation:';

    /**
     * POST /staff/impersonate/{user} — prendre la main sur un compte client.
     */
    public function start(Request $request, User $user): JsonResponse
    {
        $operateur = $request->user();

        // Le middleware `staff` a déjà écarté les clients ; on autorise ici
        // agent ET administrateur. Le filtrage « bouton visible pour les
        // administrateurs seulement » est un choix d'interface, pas la règle de
        // sécurité — l'ouverture aux agents est prévue et ne demandera rien ici.
        abort_unless($operateur->hasAnyRole(['agent-cpi', 'super-admin']), 403, 'Action réservée au personnel CPI.');

        // ⚠️ Cible limitée aux CLIENTS, volontairement.
        //
        // Sans cette borne, un agent pourrait prendre la main sur un compte
        // administrateur et hériter de ses permissions : une élévation de
        // privilèges offerte par la fonctionnalité elle-même.
        abort_if(
            ! $user->hasRole('client'),
            403,
            'Seuls les comptes clients peuvent être consultés de cette manière.'
        );

        abort_if($user->is($operateur), 422, 'Vous ne pouvez pas prendre la main sur votre propre compte.');

        // Pas de prise en main imbriquée : sinon le jeton d'origine à restituer
        // devient impossible à déterminer.
        abort_if($this->estUneImpersonation($request), 409, 'Une prise en main est déjà en cours.');

        // Durée de vie très courte : ce jeton ouvre le dossier complet d'un
        // client (pièces d'identité, montants, documents contractuels). Il
        // n'expirait jamais — un jeton d'assistance oublié dans un
        // localStorage restait valable indéfiniment.
        $jeton = $user->createToken(
            self::PREFIXE.$operateur->getKey(),
            ['*'],
            now()->addMinutes((int) config('sanctum.impersonation_expiration')),
        );

        activity()
            ->causedBy($operateur)
            ->performedOn($user)
            ->event('impersonation-debut')
            ->log("{$operateur->name} a pris la main sur le compte de {$user->name}");

        return response()->json(['data' => [
            'token' => $jeton->plainTextToken,
            'user' => UserData::from($user->fresh()),
        ]]);
    }

    /**
     * POST /impersonate/leave — rendre la main.
     *
     * Appelée avec le jeton de prise en main (donc par un compte client) : cette
     * route ne peut PAS vivre derrière le middleware `staff`, qui la refuserait.
     * Le contrôle repose sur le jeton lui-même, qui doit être un jeton de prise
     * en main — un client ordinaire ne peut donc rien en faire.
     */
    public function leave(Request $request): JsonResponse
    {
        abort_unless($this->estUneImpersonation($request), 400, 'Aucune prise en main en cours.');

        $cible = $request->user();
        $jeton = $cible->currentAccessToken();
        // `first()` plutôt que `find()` : l'opérateur peut avoir été supprimé
        // entre le début et la fin de la prise en main, et cette écriture dit
        // explicitement que le résultat peut être nul.
        $operateur = User::query()
            ->whereKey(str_replace(self::PREFIXE, '', $jeton->name))
            ->first();

        // Le jeton de prise en main meurt ici : il ne doit pas survivre à la
        // session, sinon il resterait utilisable indéfiniment.
        $jeton->delete();

        // Le nom de l'opérateur n'est pas répété dans le texte : `causedBy` le
        // porte déjà, et l'y remettre obligeait à gérer un cas nul sans intérêt.
        activity()
            ->causedBy($operateur)
            ->performedOn($cible)
            ->event('impersonation-fin')
            ->log("Fin de prise en main sur le compte de {$cible->name}");

        return response()->json(['data' => ['message' => 'Prise en main terminée.']]);
    }

    /** Le jeton porté par cette requête est-il un jeton de prise en main ? */
    private function estUneImpersonation(Request $request): bool
    {
        $jeton = $request->user()?->currentAccessToken();

        return $jeton instanceof PersonalAccessToken
            && str_starts_with($jeton->name, self::PREFIXE);
    }
}
