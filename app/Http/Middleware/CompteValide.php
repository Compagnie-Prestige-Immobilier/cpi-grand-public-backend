<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ferme l'espace client tant qu'un administrateur n'a pas validé le compte.
 *
 * S'inscrire ne donne droit à rien : ni consulter, ni déposer une pièce, ni
 * envoyer une demande. Le filtre est ICI, sur le groupe de routes, et pas
 * seulement dans l'interface — masquer un écran laisserait l'API ouverte à
 * qui sait envoyer une requête.
 *
 * Le personnel CPI n'est pas concerné : ces comptes sont créés par un
 * administrateur, et les routes `/staff` ont leur propre garde.
 *
 * Ce middleware ne s'applique QU'aux routes de l'espace client. Restent
 * délibérément accessibles à un compte non validé, sur d'autres groupes :
 * `/auth/me` (pour connaître son propre état), la déconnexion, la vérification
 * d'adresse, le renvoi du lien et le formulaire de support — sans quoi la
 * personne serait bloquée devant un écran sans aucun recours.
 */
class CompteValide
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->compteValide() && ! $user->estPersonnel()) {
            return response()->json([
                'message' => 'Votre compte est en attente de validation par un administrateur CPI.',
                'statut_compte' => $user->statut_compte->value,
                'motif_rejet' => $user->motif_rejet,
            ], 403);
        }

        return $next($request);
    }
}
