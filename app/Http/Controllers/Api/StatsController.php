<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Support\ParcoursDossier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Statistiques du personnel CPI (STEP 10.13).
 *
 * Tout est calculé en SQL — agrégats, `group by`, jointures : aucune ligne
 * métier ne remonte en PHP, un portefeuille de dix mille dossiers coûte le même
 * nombre de requêtes qu'un portefeuille de dix.
 *
 * Deux niveaux de lecture, deux niveaux d'habilitation :
 *  - `agent` : le portefeuille de dossiers (pièces, documents CPI, chantiers).
 *    Habilité par `view-clients` — agent-cpi et super-admin ;
 *  - `admin` : les totaux de la plateforme (comptes, banques, décaissements,
 *    volume d'activité). Habilité par `view-stats` — super-admin uniquement,
 *    seul rôle à détenir cette permission dans le seeder.
 *
 * `dashboard` sert le bloc correspondant au rôle de l'appelant : un agent
 * reçoit `admin: null`, l'administrateur reçoit les deux — un seul appel
 * alimente donc l'écran quel que soit le rôle.
 */
class StatsController extends Controller
{
    /**
     * Étape du parcours du dossier, calculée en SQL.
     *
     * Portage exact de ClientController::computeJourneyStep (0 = inscription,
     * 1 = dossier reçu, 2 = documents valides, puis le signal `dossier_etape`
     * piloté par l'agent, borné à [2, 5]). Écrit en `case` pur, sans `least`
     * ni `greatest` : la base de développement est PostgreSQL, les tests
     * tournent sur SQLite, et `least`/`greatest` n'existent pas sur la seconde.
     */

    /** Dernière étape du parcours — « Signature », dossier finalisé. */
    private const ETAPE_SIGNATURE = ParcoursDossier::SIGNATURE;

    /**
     * GET /staff/stats/dashboard — le tableau de bord du rôle appelant.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Client::class);

        $user = $request->user();
        $estAdmin = $user?->can('view-stats') === true;

        return response()->json(['data' => [
            'role' => $user?->getRoleNames()->first(),
            'genereLe' => now()->format('Y-m-d H:i:s'),
            'agent' => $this->agentStats(),
            'admin' => $estAdmin ? $this->adminStats() : null,
        ]]);
    }

    /**
     * GET /staff/stats/agent — portefeuille de dossiers.
     */
    public function agent(): JsonResponse
    {
        $this->authorize('viewAny', Client::class);

        return response()->json(['data' => $this->agentStats()]);
    }

    /**
     * GET /staff/stats/admin — totaux de la plateforme (super-admin).
     */
    public function admin(): JsonResponse
    {
        $this->authorize('view-stats');

        return response()->json(['data' => $this->adminStats()]);
    }

    // ─── Blocs de statistiques ────────────────────────────────

    /**
     * Portefeuille : répartition des dossiers dans le parcours, état des
     * pièces requises, des documents CPI, des chantiers et des notifications.
     *
     * @return array<string, mixed>
     */
    private function agentStats(): array
    {
        $parEtape = $this->dossiersParEtape();
        $total = array_sum($parEtape);
        $finalises = $parEtape[self::ETAPE_SIGNATURE];
        $nonSoumis = $parEtape[0];

        $docs = $this->comptesParColonne('requis_docs', 'status');
        $aVerifier = $docs['depose'] + $docs['verification'];

        $cpiDocs = $this->comptesParColonne('cpi_docs', 'status');
        $chantiers = $this->comptesParColonne('chantiers', 'statut');
        $tranches = $this->comptesParColonne('chantier_tranches', 'etat');

        return [
            'clients' => [
                'total' => $total,
                'avecCompte' => DB::table('clients')->whereNotNull('user_id')->count(),
                'nouveaux' => $this->nouveauxClients(),
            ],
            'dossiers' => [
                // Index 0-5, alignés sur TIMELINE_STEPS côté front.
                'parEtape' => array_values($parEtape),
                'finalises' => $finalises,
                'enCours' => $total - $finalises - $nonSoumis,
                'nonSoumis' => $nonSoumis,
                'tauxFinalisation' => $total > 0 ? (int) round($finalises / $total * 100) : 0,
                'avecPiecesAVerifier' => DB::table('requis_docs')
                    ->whereIn('status', ['depose', 'verification'])
                    ->distinct()->count('client_id'),
                'avecDocsASigner' => DB::table('cpi_docs')
                    ->where('status', 'a-signer')->where('visible_client', true)
                    ->distinct()->count('client_id'),
            ],
            'documents' => [
                'total' => array_sum($docs),
                'enAttente' => $docs['en-attente'],
                'deposes' => $docs['depose'],
                'verification' => $docs['verification'],
                'aRemplacer' => $docs['a-remplacer'],
                'acceptes' => $docs['accepte'],
                'refuses' => $docs['refuse'],
                'aVerifier' => $aVerifier,
            ],
            'cpiDocs' => [
                'total' => array_sum($cpiDocs),
                'brouillons' => $cpiDocs['brouillon'],
                'disponibles' => $cpiDocs['disponible'],
                'aSigner' => DB::table('cpi_docs')
                    ->where('status', 'a-signer')->where('visible_client', true)->count(),
                'signes' => $cpiDocs['signe'],
                'archives' => $cpiDocs['archive'],
            ],
            'chantiers' => [
                'total' => array_sum($chantiers),
                'nonDemarres' => $chantiers['non-demarre'],
                'enCours' => $chantiers['en-cours'],
                'enRetard' => $chantiers['en-retard'],
                'suspendus' => $chantiers['suspendu'],
                'termines' => $chantiers['termine'] + $chantiers['livre'],
                'progressionMoyenne' => (int) round((float) DB::table('chantiers')->avg('progression')),
                'tranchesTerminees' => $tranches['terminee'],
            ],
            'notifications' => $this->notificationStats(),
        ];
    }

    /**
     * Plateforme : comptes, banques, décaissements, volume du journal.
     *
     * @return array<string, mixed>
     */
    private function adminStats(): array
    {
        $assignments = $this->comptesParColonne('bank_assignments', 'status');

        return [
            'utilisateurs' => [
                'total' => User::query()->count(),
                'clients' => User::role('client')->count(),
                'agents' => User::role('agent-cpi')->count(),
                'admins' => User::role('super-admin')->count(),
            ],
            'clients' => [
                'total' => DB::table('clients')->count(),
                'sansCompte' => DB::table('clients')->whereNull('user_id')->count(),
            ],
            'banques' => [
                'total' => DB::table('banks')->count(),
                'orientations' => array_sum($assignments),
                'enAttente' => $assignments['en-attente'],
                'accords' => $assignments['accord'],
                'refus' => $assignments['refus'],
            ],
            'decaissements' => [
                'terrainsDecaisses' => DB::table('decaissements')->where('terrain_decaisse', true)->count(),
                'montantTerrain' => (float) DB::table('decaissements')
                    ->where('terrain_decaisse', true)->sum('terrain_montant'),
                'constructionsActives' => DB::table('decaissements')->where('construction_active', true)->count(),
                'montantConstruction' => (float) DB::table('decaissements')
                    ->where('construction_active', true)->sum('construction_montant'),
            ],
            'activite' => [
                'total' => Activity::query()->count(),
                'derniers7Jours' => Activity::query()->where('created_at', '>=', now()->subDays(7))->count(),
                'parEvenement' => Activity::query()
                    ->whereNotNull('event')
                    ->select('event')
                    ->selectRaw('count(*) as total')
                    ->groupBy('event')
                    ->orderByRaw('count(*) desc')
                    ->limit(10)
                    ->pluck('total', 'event')
                    ->map(fn ($total) => (int) $total)
                    ->all(),
            ],
            'notifications' => $this->notificationStats(),
        ];
    }

    // ─── Agrégats ─────────────────────────────────────────────

    /**
     * Nombre de dossiers par étape du parcours (index 0 à 5, toujours présents
     * même à zéro) — une seule requête, `group by` sur l'expression d'étape.
     *
     * @return array<int, int>
     */
    private function dossiersParEtape(): array
    {
        $parEtape = array_fill(0, 6, 0);

        $rows = DB::table('clients as c')
            ->leftJoin('demandes as d', 'd.client_id', '=', 'c.id')
            ->leftJoinSub(
                DB::table('requis_docs')
                    ->select('client_id')
                    ->selectRaw('count(*) as total')
                    ->selectRaw("sum(case when status = 'accepte' then 1 else 0 end) as acceptes")
                    ->groupBy('client_id'),
                'r',
                'r.client_id',
                '=',
                'c.id',
            )
            ->selectRaw(ParcoursDossier::sql().' as etape')
            ->selectRaw('count(*) as total')
            ->groupByRaw(ParcoursDossier::sql())
            ->pluck('total', 'etape');

        foreach ($rows as $etape => $total) {
            $index = (int) $etape;
            if (array_key_exists($index, $parEtape)) {
                $parEtape[$index] = (int) $total;
            }
        }

        return $parEtape;
    }

    /**
     * Répartition d'une table par valeur d'une colonne, avec les modalités
     * connues toujours présentes à zéro : l'interface ne doit pas avoir à
     * distinguer « aucune ligne » de « clé absente ».
     *
     * @return array<string, int>
     */
    private function comptesParColonne(string $table, string $colonne): array
    {
        $connues = array_fill_keys(self::MODALITES[$table], 0);

        $rows = DB::table($table)
            ->select($colonne)
            ->selectRaw('count(*) as total')
            ->groupBy($colonne)
            ->pluck('total', $colonne);

        foreach ($rows as $valeur => $total) {
            $connues[(string) $valeur] = (int) $total;
        }

        return $connues;
    }

    /**
     * Modalités attendues de chaque colonne agrégée — miroir des valeurs que
     * les contrôleurs écrivent et des défauts de migration. Une valeur
     * inattendue en base n'est pas perdue pour autant : elle s'ajoute au
     * tableau lors de l'agrégation.
     *
     * @var array<string, list<string>>
     */
    private const MODALITES = [
        'requis_docs' => ['en-attente', 'depose', 'verification', 'a-remplacer', 'accepte', 'refuse'],
        'cpi_docs' => ['brouillon', 'disponible', 'a-signer', 'signe', 'archive'],
        'chantiers' => ['non-demarre', 'en-cours', 'suspendu', 'en-retard', 'termine', 'livre'],
        'chantier_tranches' => ['en-attente', 'en-cours', 'terminee'],
        'bank_assignments' => ['en-attente', 'accord', 'refus'],
    ];

    /**
     * Inscriptions récentes — les trois fenêtres du sélecteur de période de
     * l'écran « Rapports & Analyses » (3 / 6 / 12 mois), plus le total.
     *
     * @return array<string, int>
     */
    private function nouveauxClients(): array
    {
        $depuis = fn (int $mois) => DB::table('clients')
            ->where('date_inscription', '>=', now()->subMonths($mois)->toDateString())
            ->count();

        return [
            'mois3' => $depuis(3),
            'mois6' => $depuis(6),
            'mois12' => $depuis(12),
            'total' => DB::table('clients')->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function notificationStats(): array
    {
        return [
            'total' => DB::table('app_notifications')->count(),
            'nonLues' => DB::table('app_notifications')->where('lu', false)->count(),
        ];
    }
}
