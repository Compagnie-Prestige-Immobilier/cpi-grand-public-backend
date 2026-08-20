<?php

namespace App\Http\Controllers\Api;

use App\Dto\ActivityLogData;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Support\PortefeuilleConseiller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;
use Spatie\LaravelData\PaginatedDataCollection;

/**
 * Journal d'activité — lecture seule (STEP 10.12).
 *
 * Rien n'est écrit ici : chaque contrôleur métier journalise déjà ses propres
 * mutations via `activity()` (dépôt de pièce, validation, décaissement,
 * avancement de chantier…). Le serveur est donc la SEULE source de vérité de
 * l'historique — le journal local du front (`cpi_activity_log_v1`) disparaît
 * avec cette phase.
 *
 * Les relations morph `causer` et `subject` sont préchargées, rôles de l'auteur
 * compris : sans cela, ActivityLogData déclencherait trois requêtes par ligne
 * pour retrouver le nom de l'auteur, son rôle et le nom du dossier.
 */
class HistoriqueController extends Controller
{
    /**
     * GET /staff/historique — journal global, paginé (50 par page),
     * le plus récent en tête.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Activity::class);

        // Cloisonné comme la liste des dossiers : sans ce filtre, tout
        // agent-cpi lisait le journal COMPLET de la plateforme — identités,
        // revenus, montants de décaissement, création et suppression de
        // comptes du personnel — y compris pour des dossiers qu'il ne peut
        // même plus ouvrir depuis le cloisonnement strict. Le super-admin
        // n'est pas concerné (`PortefeuilleConseiller::voitTout`).
        $query = $this->journal();
        PortefeuilleConseiller::filtrerActivites($query, $request->user());

        $page = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')   // départage les entrées de la même seconde
            ->paginate(50);

        $this->chargerRolesDesAuteurs($page->getCollection());

        return response()->json(ActivityLogData::collect($page, PaginatedDataCollection::class));
    }

    /**
     * GET /staff/historique/{client} — journal d'un seul dossier.
     *
     * Filtre sur le couple morph (`subject_type`, `subject_id`) : seules les
     * entrées enregistrées avec `performedOn($client)` remontent, jamais celles
     * d'un autre dossier ni celles sans sujet.
     */
    public function forClient(Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        $activities = $this->journal()
            ->where('subject_type', Client::class)
            ->where('subject_id', $client->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $this->chargerRolesDesAuteurs($activities);

        return response()->json(['data' => ActivityLogData::collect($activities)]);
    }

    /**
     * Requête de base : les deux relations morph préchargées.
     *
     * @return Builder<Activity>
     */
    private function journal(): Builder
    {
        return Activity::query()->with(['subject', 'causer']);
    }

    /**
     * Rôles Spatie des auteurs, en une requête pour toute la page.
     *
     * `with('causer.roles')` ne fonctionne pas sur une relation polymorphe :
     * `loadMorph` est la forme prévue par le framework pour charger une
     * relation qui n'existe que sur certains des types visés.
     *
     * @param  Collection<int, Activity>  $activities
     */
    private function chargerRolesDesAuteurs(Collection $activities): void
    {
        $activities->loadMorph('causer', [User::class => ['roles']]);
    }
}
