<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\StatutCompte;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\TransitionStatut;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Vérification de l'adresse e-mail déclarée à l'inscription.
 *
 * Le lien du courriel pointe sur l'API et non sur le frontend : la signature
 * d'une URL signée porte sur l'URL elle-même, et faire reconstituer cette URL
 * par le navigateur pour la renvoyer serait fragile. L'API vérifie donc, puis
 * REDIRIGE vers l'application avec un résultat lisible.
 *
 * La vérification ne donne aucun accès : elle prouve seulement que l'adresse
 * existe. Le compte passe ensuite en file d'attente d'un administrateur.
 */
class EmailVerificationController extends Controller
{
    /**
     * GET /auth/email/verify/{id}/{hash} — cible du lien reçu par courriel.
     *
     * Route signée : ni `auth`, ni jeton. La personne clique depuis sa boîte
     * mail, souvent sur un autre appareil que celui de l'inscription.
     */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::find($id);

        // Hash calculé sur l'adresse : un lien reste inopérant si l'adresse a
        // changé depuis l'envoi.
        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect()->away($this->retourFrontend('lien-invalide'));
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->away($this->retourFrontend('deja-verifie'));
        }

        $user->markEmailAsVerified();

        // Le passage en file d'attente n'a lieu que depuis « e-mail à
        // vérifier » : un compte déjà validé par un administrateur ne doit pas
        // y retomber parce qu'un vieux lien a été cliqué.
        if ($user->statut_compte === StatutCompte::EmailAVerifier) {
            TransitionStatut::verifier($user->statut_compte, StatutCompte::EnAttenteValidation, 'Compte');
            $user->update(['statut_compte' => StatutCompte::EnAttenteValidation]);
        }

        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->event('email-verifie')
            ->log("{$user->name} a vérifié son adresse e-mail");

        return redirect()->away($this->retourFrontend('verifie'));
    }

    /**
     * POST /auth/email/resend — renvoie le courriel de vérification.
     *
     * Limité par `throttle` côté route : l'endpoint envoie un message à une
     * adresse que l'appelant a choisie à l'inscription, il ne doit pas devenir
     * un relais d'envoi.
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['data' => ['message' => 'Votre adresse est déjà vérifiée.']]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['data' => [
            'message' => 'Un nouveau lien de vérification vient de vous être envoyé.',
        ]]);
    }

    /** URL de retour dans l'application, avec le résultat en clair. */
    private function retourFrontend(string $resultat): string
    {
        return config('app.frontend_url').'/email-verifie?resultat='.$resultat;
    }
}
