<?php

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\Client;
use App\Models\CpiDoc;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Statistiques du personnel CPI (Phase 6).
 *
 * Chaque chiffre est vérifié contre un jeu de données construit à la main :
 * l'objectif n'est pas seulement que la route réponde 200, c'est que les
 * agrégats SQL disent la vérité sur les dossiers.
 */
class StatsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function agentToken(): string
    {
        /** @var User $agent */
        $agent = User::query()->where('email', 'agent@cpi.sn')->firstOrFail();

        return $agent->createToken('t')->plainTextToken;
    }

    private function adminToken(): string
    {
        /** @var User $admin */
        $admin = User::query()->where('email', 'admin@cpi.sn')->firstOrFail();

        return $admin->createToken('t')->plainTextToken;
    }

    private function forgetAuthState(): void
    {
        auth()->forgetGuards();
    }

    private function makeClient(string $name = 'Dossier'): Client
    {
        return Client::create([
            'name' => $name,
            'ref' => Client::generateRef(),
            'date_inscription' => now(),
        ])->refresh();
    }

    /**
     * @return array{0: User, 1: Client, 2: string}
     */
    private function makeClientUser(): array
    {
        $user = User::factory()->create();
        $user->assignRole('client');
        $client = Client::create([
            'user_id' => $user->id,
            'name' => $user->name,
            'ref' => Client::generateRef(),
            'email' => $user->email,
            'date_inscription' => now(),
        ])->refresh();

        return [$user, $client, $user->createToken('t')->plainTextToken];
    }

    /**
     * Amène un dossier à une étape donnée du parcours (0 à 5), exactement
     * comme le ferait l'usage réel : demande soumise, pièces acceptées, puis
     * signal `dossier_etape` de l'agent.
     */
    private function amenerAEtape(Client $client, int $etape): Client
    {
        $client->demande()->create(['submitted' => $etape >= 1, 'submitted_at' => now()]);

        if ($etape >= 2) {
            $client->requisDocs()->update(['status' => 'accepte']);
        }
        $client->update(['dossier_etape' => max(0, $etape)]);

        return $client->refresh();
    }

    // ─── Portefeuille (agent) ─────────────────────────────────

    public function test_agent_stats_count_the_dossiers_at_each_step(): void
    {
        $this->amenerAEtape($this->makeClient('Inscrit'), 0);
        $this->amenerAEtape($this->makeClient('Reçu 1'), 1);
        $this->amenerAEtape($this->makeClient('Reçu 2'), 1);
        $this->amenerAEtape($this->makeClient('Pièces OK'), 2);
        $this->amenerAEtape($this->makeClient('Signature'), 5);

        $this->withToken($this->agentToken())->getJson('/api/staff/stats/agent')
            ->assertOk()
            ->assertJsonPath('data.clients.total', 5)
            ->assertJsonPath('data.dossiers.parEtape', [1, 2, 1, 0, 0, 1])
            ->assertJsonPath('data.dossiers.nonSoumis', 1)
            ->assertJsonPath('data.dossiers.finalises', 1)
            ->assertJsonPath('data.dossiers.enCours', 3)
            ->assertJsonPath('data.dossiers.tauxFinalisation', 20);
    }

    /** Le signal `dossier_etape` ne compte que si les pièces sont conformes. */
    public function test_an_agent_step_signal_is_ignored_while_documents_are_pending(): void
    {
        $client = $this->makeClient('Pressé');
        $client->demande()->create(['submitted' => true, 'submitted_at' => now()]);
        $client->update(['dossier_etape' => 5]);   // l'agent avance, les pièces non

        $this->withToken($this->agentToken())->getJson('/api/staff/stats/agent')
            ->assertOk()
            ->assertJsonPath('data.dossiers.parEtape', [0, 1, 0, 0, 0, 0])
            ->assertJsonPath('data.dossiers.finalises', 0);
    }

    public function test_agent_stats_count_the_required_documents_by_status(): void
    {
        $client = $this->makeClient();   // 3 pièces « en-attente » à la création
        $client->requisDocs()->where('doc_id', 'identite')->update(['status' => 'depose']);
        $client->requisDocs()->where('doc_id', 'revenus')->update(['status' => 'accepte']);

        $this->withToken($this->agentToken())->getJson('/api/staff/stats/agent')
            ->assertOk()
            ->assertJsonPath('data.documents.total', 3)
            ->assertJsonPath('data.documents.enAttente', 1)
            ->assertJsonPath('data.documents.deposes', 1)
            ->assertJsonPath('data.documents.acceptes', 1)
            ->assertJsonPath('data.documents.refuses', 0)
            ->assertJsonPath('data.documents.aVerifier', 1)
            ->assertJsonPath('data.dossiers.avecPiecesAVerifier', 1);
    }

    public function test_agent_stats_count_the_cpi_documents_awaiting_signature(): void
    {
        $client = $this->makeClient();
        CpiDoc::create([
            'client_id' => $client->id, 'categorie' => 'contrat', 'nom' => 'Convention',
            'auteur' => 'Agent CPI', 'status' => 'a-signer',
            'visible_client' => true, 'signature_requise' => true,
        ]);
        CpiDoc::create([
            'client_id' => $client->id, 'categorie' => 'contrat', 'nom' => 'Brouillon interne',
            'auteur' => 'Agent CPI', 'status' => 'brouillon', 'visible_client' => false,
        ]);

        $this->withToken($this->agentToken())->getJson('/api/staff/stats/agent')
            ->assertOk()
            ->assertJsonPath('data.cpiDocs.total', 2)
            ->assertJsonPath('data.cpiDocs.aSigner', 1)
            ->assertJsonPath('data.cpiDocs.brouillons', 1)
            ->assertJsonPath('data.dossiers.avecDocsASigner', 1);
    }

    public function test_agent_stats_summarise_the_chantiers(): void
    {
        $a = $this->makeClient('Chantier A');
        $b = $this->makeClient('Chantier B');
        $a->ensureChantier()->update(['statut' => 'en-cours', 'progression' => 60]);
        $b->ensureChantier()->update(['statut' => 'livre', 'progression' => 100]);
        $a->chantier?->tranches()->where('num', 1)->update(['etat' => 'terminee']);

        $this->withToken($this->agentToken())->getJson('/api/staff/stats/agent')
            ->assertOk()
            ->assertJsonPath('data.chantiers.total', 2)
            ->assertJsonPath('data.chantiers.enCours', 1)
            ->assertJsonPath('data.chantiers.termines', 1)
            ->assertJsonPath('data.chantiers.progressionMoyenne', 80)
            ->assertJsonPath('data.chantiers.tranchesTerminees', 1);
    }

    public function test_agent_stats_on_an_empty_platform_are_all_zero(): void
    {
        $response = $this->withToken($this->agentToken())->getJson('/api/staff/stats/agent');

        $response->assertOk()
            ->assertJsonPath('data.clients.total', 0)
            ->assertJsonPath('data.dossiers.parEtape', [0, 0, 0, 0, 0, 0])
            ->assertJsonPath('data.dossiers.tauxFinalisation', 0)
            ->assertJsonPath('data.documents.total', 0)
            ->assertJsonPath('data.chantiers.progressionMoyenne', 0)
            ->assertJsonPath('data.notifications.nonLues', 0);
    }

    // ─── Plateforme (admin) ───────────────────────────────────

    public function test_admin_stats_count_accounts_banks_and_disbursements(): void
    {
        [, $client] = $this->makeClientUser();
        $this->makeClient('Sans compte');
        Bank::create(['name' => 'CBAO']);
        $client->ensureDecaissement()->update([
            'terrain_decaisse' => true,
            'terrain_montant' => 12_000_000,
            'construction_active' => true,
            'construction_montant' => 30_000_000,
        ]);

        $this->withToken($this->adminToken())->getJson('/api/staff/stats/admin')
            ->assertOk()
            ->assertJsonPath('data.utilisateurs.total', 3)   // agent + admin + le client créé
            ->assertJsonPath('data.utilisateurs.clients', 1)
            ->assertJsonPath('data.utilisateurs.agents', 1)
            ->assertJsonPath('data.utilisateurs.admins', 1)
            ->assertJsonPath('data.clients.total', 2)
            ->assertJsonPath('data.clients.sansCompte', 1)
            ->assertJsonPath('data.banques.total', 1)
            ->assertJsonPath('data.decaissements.terrainsDecaisses', 1)
            ->assertJsonPath('data.decaissements.montantTerrain', 12000000)
            ->assertJsonPath('data.decaissements.constructionsActives', 1)
            ->assertJsonPath('data.decaissements.montantConstruction', 30000000);
    }

    public function test_admin_stats_measure_the_activity_volume(): void
    {
        $client = $this->makeClient();

        $this->withToken($this->agentToken())
            ->postJson('/api/staff/chantiers/'.$client->id.'/progression', ['pct' => 20])
            ->assertOk();
        $this->withToken($this->agentToken())
            ->postJson('/api/staff/chantiers/'.$client->id.'/progression', ['pct' => 40])
            ->assertOk();

        $this->forgetAuthState();
        $response = $this->withToken($this->adminToken())->getJson('/api/staff/stats/admin');

        $response->assertOk();
        $this->assertGreaterThanOrEqual(2, $response->json('data.activite.total'));
        $this->assertSame($response->json('data.activite.total'), $response->json('data.activite.derniers7Jours'));
        $this->assertSame(2, $response->json('data.activite.parEvenement.chantier-progression'));
    }

    // ─── Tableau de bord selon le rôle ────────────────────────

    public function test_dashboard_serves_the_admin_block_only_to_the_admin(): void
    {
        $this->makeClient();

        $this->withToken($this->adminToken())->getJson('/api/staff/stats/dashboard')
            ->assertOk()
            ->assertJsonPath('data.role', 'super-admin')
            ->assertJsonPath('data.agent.clients.total', 1)
            ->assertJsonPath('data.admin.utilisateurs.agents', 1);

        $this->forgetAuthState();
        $this->withToken($this->agentToken())->getJson('/api/staff/stats/dashboard')
            ->assertOk()
            ->assertJsonPath('data.role', 'agent-cpi')
            ->assertJsonPath('data.agent.clients.total', 1)
            ->assertJsonPath('data.admin', null);
    }

    /** `view-stats` n'appartient qu'au super-admin : l'agent n'a pas les totaux. */
    public function test_agent_is_refused_on_the_admin_stats(): void
    {
        $this->withToken($this->agentToken())->getJson('/api/staff/stats/admin')
            ->assertStatus(403);
    }

    // ─── Séparation des rôles ─────────────────────────────────

    public function test_client_token_is_refused_on_every_stats_route(): void
    {
        [, , $token] = $this->makeClientUser();

        $this->withToken($token)->getJson('/api/staff/stats/dashboard')->assertStatus(403);
        $this->withToken($token)->getJson('/api/staff/stats/agent')->assertStatus(403);
        $this->withToken($token)->getJson('/api/staff/stats/admin')->assertStatus(403);
    }

    public function test_stats_routes_require_authentication(): void
    {
        $this->getJson('/api/staff/stats/dashboard')->assertStatus(401);
        $this->getJson('/api/staff/stats/agent')->assertStatus(401);
        $this->getJson('/api/staff/stats/admin')->assertStatus(401);
    }
}
