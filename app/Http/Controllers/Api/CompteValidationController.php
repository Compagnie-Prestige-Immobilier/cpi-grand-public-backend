<?php

namespace App\Http\Controllers\Api;

use App\Enums\StatutCompte;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Support\AttributionConseiller;
use App\Support\TransitionStatut;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Validation des comptes par un administrateur.
 *
 * S'inscrire ne donne accès à rien (voir `CompteValide`) : un administrateur
 * examine les informations déclarées et approuve ou refuse. Réservé au
 * `super-admin` — approuver ouvre l'accès à la plateforme et, par ricochet,
 * déclenche l'attribution d'un conseiller.
 */
class CompteValidationController extends Controller
{
    /**
     * GET /staff/comptes/en-attente — file d'attente de validation.
     *
     * Uniquement les comptes dont l'e-mail est vérifié : un compte qui n'a
     * même pas encore cliqué son lien n'a rien à faire devant un administrateur.
     */
    public function enAttente(Request $request): JsonResponse
    {
        $this->autoriser($request);

        $comptes = User::query()
            ->where('statut_compte', StatutCompte::EnAttenteValidation)
            ->orderBy('created_at')   // premier arrivé, premier examiné
            ->limit(200)
            ->get();

        return response()->json(['data' => $comptes->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'employer' => $u->employer,
            'profileType' => $u->profile_type,
            'revenus' => $u->revenus,
            'createdAt' => $u->created_at,
        ])]);
    }

    /**
     * POST /staff/comptes/{user}/valider
     */
    public function valider(Request $request, User $user): JsonResponse
    {
        $this->autoriser($request);

        TransitionStatut::verifier($user->statut_compte, StatutCompte::Valide, 'Compte');

        $user->update([
            'statut_compte' => StatutCompte::Valide,
            'motif_rejet' => null,
            'validated_by' => $request->user()?->id,
            'validated_at' => now(),
        ]);

        activity()
            ->causedBy($request->user())
            ->performedOn($user)
            ->event('compte-valide')
            ->log("{$request->user()?->name} a validé le compte de {$user->name}");

        $conseiller = $this->attribuerConseiller($request, $user);

        return response()->json(['data' => [
            'message' => $conseiller !== null
                ? "Compte validé — conseiller attribué : {$conseiller->name}."
                : 'Compte validé — aucun agent CPI disponible pour l\'instant, le dossier reste sans conseiller.',
            'conseiller' => $conseiller !== null ? ['id' => $conseiller->id, 'name' => $conseiller->name] : null,
        ]]);
    }

    /**
     * Attribution automatique, déclenchée par la validation.
     *
     * Ne fait rien échouer : un dossier n'a de client à qui l'attribuer QUE si
     * `$user->client` existe — vrai pour toute inscription normale, mais un
     * compte peut en théorie atterrir ici sans dossier (jamais vu en
     * pratique ; mieux vaut un no-op silencieux qu'une 500 sur la validation
     * elle-même, qui elle a déjà réussi).
     */
    private function attribuerConseiller(Request $request, User $user): ?User
    {
        $client = $user->client;
        if ($client === null) {
            return null;
        }

        $conseiller = AttributionConseiller::assigner($client);

        if ($conseiller === null) {
            // Distinct de « compte-valide » : un administrateur qui parcourt le
            // journal doit pouvoir repérer les dossiers restés sans conseiller
            // sans avoir à recouper la liste du personnel lui-même.
            activity()
                ->causedBy($request->user())
                ->performedOn($client)
                ->event('conseiller-non-attribue')
                ->log("Aucun agent CPI disponible pour attribuer un conseiller à {$client->name}");

            return null;
        }

        activity()
            ->causedBy($request->user())
            ->performedOn($client)
            ->withProperties(['conseiller_id' => $conseiller->id])
            ->event('conseiller-attribue')
            ->log("{$conseiller->name} a été attribué comme conseiller de {$client->name}");

        if ($client->user_id !== null) {
            Notification::create([
                'client_id' => $client->id,
                'user_id' => $client->user_id,
                'titre' => 'Conseiller attribué',
                'message' => "Votre conseiller CPI est désormais {$conseiller->name}. Vous pouvez accéder à votre espace et déposer vos pièces.",
                'type' => 'validation',
                'target_page' => 'ma-demande',
                'date' => now(),
                'heure' => now()->format('H:i'),
                'lu' => false,
            ]);
        }

        return $conseiller;
    }

    /**
     * POST /staff/comptes/{user}/rejeter
     */
    public function rejeter(Request $request, User $user): JsonResponse
    {
        $this->autoriser($request);

        $validated = $request->validate([
            'motif' => 'required|string|max:1000',
        ]);

        TransitionStatut::verifier($user->statut_compte, StatutCompte::Rejete, 'Compte');

        $user->update([
            'statut_compte' => StatutCompte::Rejete,
            'motif_rejet' => $validated['motif'],
            'validated_by' => $request->user()?->id,
            'validated_at' => now(),
        ]);

        activity()
            ->causedBy($request->user())
            ->performedOn($user)
            ->withProperties(['motif' => $validated['motif']])
            ->event('compte-rejete')
            ->log("{$request->user()?->name} a refusé le compte de {$user->name}");

        return response()->json(['data' => ['message' => 'Compte refusé.']]);
    }

    private function autoriser(Request $request): void
    {
        abort_unless($request->user()?->hasPermissionTo('validate-accounts'), 403, 'Action réservée aux administrateurs.');
    }
}
