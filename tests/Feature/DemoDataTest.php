<?php

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\BankAssignment;
use App\Models\Chantier;
use App\Models\ChantierEvent;
use App\Models\ChantierMedia;
use App\Models\ChantierPublication;
use App\Models\ChantierTranche;
use App\Models\Client;
use App\Models\CpiDoc;
use App\Models\Decaissement;
use App\Models\Demande;
use App\Models\Notification;
use App\Models\RequisDoc;
use App\Models\User;
use App\Services\DemoDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Jeu de démonstration (Phase 7) — POST /staff/demo/seed, DELETE /staff/demo/clear.
 *
 * Deux propriétés seulement, mais elles doivent tenir absolument :
 *  1. le chargement produit 30 dossiers cohérents, répartis sur tout le parcours,
 *     avec des comptes qui se connectent réellement ;
 *  2. la purge n'emporte QUE la démonstration. Le test le prouve en construisant
 *     de vrais dossiers (pièces, chantier, documents CPI, banque, notifications,
 *     journal) autour du jeu fictif, et en vérifiant qu'ils sortent indemnes.
 */
class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function adminToken(): string
    {
        /** @var User $admin */
        $admin = User::query()->where('email', 'admin@cpi.sn')->firstOrFail();

        return $admin->createToken('t')->plainTextToken;
    }

    private function agentToken(): string
    {
        /** @var User $agent */
        $agent = User::query()->where('email', 'agent@cpi.sn')->firstOrFail();

        return $agent->createToken('t')->plainTextToken;
    }

    private function forgetAuthState(): void
    {
        auth()->forgetGuards();
    }

    /** Nombre de dossiers dont la référence porte le préfixe de démonstration. */
    private function demoClients(): int
    {
        return Client::query()->where('ref', 'like', DemoDataService::PREFIX.'%')->count();
    }

    private function chargerDemo(): TestResponse
    {
        return $this->withToken($this->adminToken())->postJson('/api/staff/demo/seed');
    }

    // ─── Chargement ───────────────────────────────────────────

    public function test_the_administrator_loads_thirty_demo_dossiers(): void
    {
        $this->chargerDemo()
            ->assertOk()
            ->assertJsonPath('data.clients', 30)
            ->assertJsonPath('data.comptes', 30)
            ->assertJsonPath('data.motDePasse', DemoDataService::PASSWORD);

        $this->assertSame(30, $this->demoClients());
        $this->assertSame(30, Client::query()->count());
    }

    /**
     * Chaque dossier doit être complet : le provisionnement de Client::booted()
     * (3 pièces, décaissement, chantier + 4 tranches) plus une demande.
     */
    public function test_every_demo_dossier_is_fully_provisioned(): void
    {
        $this->chargerDemo()->assertOk();

        $this->assertSame(30, Demande::query()->count());
        $this->assertSame(90, RequisDoc::query()->count());          // 3 pièces × 30
        $this->assertSame(30, Decaissement::query()->count());
        $this->assertSame(30, Chantier::query()->count());
        $this->assertSame(120, ChantierTranche::query()->count());   // 4 tranches × 30

        foreach (Client::query()->with('demande', 'requisDocs')->get() as $client) {
            $this->assertNotNull($client->demande, "Demande manquante pour {$client->ref}");
            $this->assertCount(3, $client->requisDocs);
            $this->assertNotNull($client->user_id, "Compte manquant pour {$client->ref}");
        }
    }

    /** Le jeu doit alimenter TOUTES les cases du parcours, pas seulement une. */
    public function test_the_demo_spreads_the_dossiers_across_the_whole_journey(): void
    {
        $this->chargerDemo()->assertOk();

        $response = $this->withToken($this->adminToken())->getJson('/api/staff/stats/agent');
        $response->assertOk();

        /** @var array<int, int> $parEtape */
        $parEtape = $response->json('data.dossiers.parEtape');

        $this->assertSame(30, array_sum($parEtape));
        $this->assertSame(5, $parEtape[0]);            // inscription, demande non envoyée
        $this->assertSame(9, $parEtape[1]);            // pièces déposées, à vérifier
        $this->assertSame(8, $parEtape[3]);            // analyse bancaire
        $this->assertSame(8, $parEtape[5]);            // signature, construction, livré
        $this->assertSame(8, $response->json('data.dossiers.finalises'));

        // Les autres modules doivent avoir de quoi s'afficher.
        $this->assertSame(3, Bank::query()->count());
        $this->assertSame(16, BankAssignment::query()->count());     // analyse + signature + construction + livré
        $this->assertSame(17, CpiDoc::query()->count());             // 16 contrats + 1 PV de réception
        $this->assertSame(4, Chantier::query()->whereIn('statut', ['en-cours', 'livre'])->count());
        $this->assertSame(4, ChantierPublication::query()->count());
        $this->assertSame(4, ChantierEvent::query()->count());
        $this->assertSame(25, Notification::query()->count());       // tout sauf les 5 inscriptions
    }

    /** Le mot de passe annoncé par l'API doit réellement ouvrir une session. */
    public function test_a_demo_account_can_actually_log_in(): void
    {
        $motDePasse = $this->chargerDemo()->assertOk()->json('data.motDePasse');

        /** @var Client $client */
        $client = Client::query()->where('ref', 'like', DemoDataService::PREFIX.'%')->firstOrFail();

        $this->forgetAuthState();
        $this->postJson('/api/auth/login', ['email' => $client->email, 'password' => $motDePasse])
            ->assertOk()
            ->assertJsonPath('data.role', 'client');
    }

    /** Aucun fichier n'est déposé : le jeu de démo ne crée aucun objet R2. */
    public function test_the_demo_uploads_no_file(): void
    {
        $this->chargerDemo()->assertOk();

        $this->assertSame(0, RequisDoc::query()->whereNotNull('file_path')->count());
        $this->assertSame(0, ChantierMedia::query()->count());
    }

    // ─── Idempotence ──────────────────────────────────────────

    /**
     * Un second chargement est refusé (409) tant que la démonstration est en
     * place : jamais 60 dossiers, jamais de collision sur `clients.ref`.
     */
    public function test_seeding_twice_never_duplicates_the_demo(): void
    {
        $this->chargerDemo()->assertOk();
        $this->forgetAuthState();

        $this->chargerDemo()->assertStatus(409);

        $this->assertSame(30, $this->demoClients());
        $this->assertSame(30, User::role('client')->count());
        $this->assertSame(3, Bank::query()->count());
    }

    /** Une fois purgée, la démonstration se recharge normalement. */
    public function test_the_demo_can_be_reloaded_after_a_purge(): void
    {
        $this->chargerDemo()->assertOk();
        $this->forgetAuthState();
        $this->withToken($this->adminToken())->deleteJson('/api/staff/demo/clear')->assertOk();
        $this->forgetAuthState();

        $this->chargerDemo()->assertOk()->assertJsonPath('data.clients', 30);
        $this->assertSame(30, $this->demoClients());
    }

    // ─── Purge ────────────────────────────────────────────────

    public function test_clear_removes_every_demo_record_and_leaves_real_data_untouched(): void
    {
        // ── De VRAIS dossiers, complets, autour du jeu fictif ────────────
        $vraiUser = User::factory()->create(['email' => 'awa.reelle@exemple.sn']);
        $vraiUser->assignRole('client');

        $vrai = Client::create([
            'user_id' => $vraiUser->id,
            'name' => 'Awa Réelle',
            'ref' => Client::generateRef(),
            'email' => $vraiUser->email,
            'date_inscription' => now(),
        ])->refresh();
        $vrai->demande()->create(['submitted' => true, 'submitted_at' => now()]);
        $vrai->requisDocs()->update(['status' => 'accepte']);
        $vrai->cpiDocs()->create([
            'categorie' => 'contrats', 'nom' => 'Contrat réel',
            'auteur' => 'Agent CPI', 'status' => 'a-signer',
            'visible_client' => true, 'signature_requise' => true,
        ]);
        $vrai->ensureChantier()->update(['statut' => 'en-cours', 'progression' => 55]);
        $vrai->chantier?->publications()->create([
            'phase' => 1, 'titre' => 'Publication réelle', 'description' => 'Vraie donnée',
            'heure' => '08:00', 'auteur' => 'Agent CPI', 'type' => 'actualite',
        ]);
        $vraieBanque = Bank::create(['name' => 'CBAO']);
        BankAssignment::create(['client_id' => $vrai->id, 'bank_id' => $vraieBanque->id, 'status' => 'accord']);
        $vrai->notifications()->create([
            'user_id' => $vraiUser->id, 'titre' => 'Vraie notification',
            'message' => 'À conserver', 'heure' => '08:00', 'type' => 'info',
        ]);
        activity()->causedBy($vraiUser)->performedOn($vrai)->event('test-reel')
            ->log('Entrée de journal réelle');

        $secondVrai = Client::create([
            'name' => 'Dossier sans compte',
            'ref' => Client::generateRef(),
            'date_inscription' => now(),
        ])->refresh();

        $vraisJournal = Activity::query()
            ->where('subject_type', Client::class)
            ->whereIn('subject_id', [$vrai->id, $secondVrai->id])
            ->count();
        $this->assertGreaterThan(0, $vraisJournal);

        // ── Chargement puis purge ───────────────────────────────────────
        $this->chargerDemo()->assertOk();
        $this->assertSame(32, Client::query()->count());
        $this->forgetAuthState();

        $this->withToken($this->adminToken())->deleteJson('/api/staff/demo/clear')
            ->assertOk()
            ->assertJsonPath('data.clients', 30)
            ->assertJsonPath('data.comptes', 30)
            ->assertJsonPath('data.banques', 3);

        // ── Plus rien de fictif ─────────────────────────────────────────
        $this->assertSame(0, $this->demoClients());
        $this->assertSame(0, User::query()->where('email', 'like', DemoDataService::PREFIX.'%')->count());
        $this->assertSame(0, Bank::query()->where('name', 'like', DemoDataService::PREFIX.'%')->count());
        $this->assertSame(0, ChantierEvent::query()->count());   // seuls les chantiers de démo en avaient
        $this->assertSame(0, CpiDoc::query()->where('reference', 'like', DemoDataService::PREFIX.'%')->count());

        // ── Les vrais dossiers sont intacts, à la ligne près ────────────
        $this->assertSame(2, Client::query()->count());
        $this->assertDatabaseHas('clients', ['id' => $vrai->id, 'name' => 'Awa Réelle']);
        $this->assertDatabaseHas('clients', ['id' => $secondVrai->id]);
        $this->assertDatabaseHas('users', ['id' => $vraiUser->id, 'email' => 'awa.reelle@exemple.sn']);
        $this->assertDatabaseHas('banks', ['id' => $vraieBanque->id, 'name' => 'CBAO']);

        $this->assertSame(1, Demande::query()->count());
        $this->assertSame(6, RequisDoc::query()->count());            // 3 pièces × 2 vrais dossiers
        $this->assertSame(3, RequisDoc::query()->where('status', 'accepte')->count());
        $this->assertSame(2, Decaissement::query()->count());
        $this->assertSame(2, Chantier::query()->count());
        $this->assertSame(8, ChantierTranche::query()->count());
        $this->assertSame(1, ChantierPublication::query()->count());
        $this->assertSame(1, CpiDoc::query()->count());
        $this->assertSame(1, BankAssignment::query()->count());
        $this->assertSame(1, Notification::query()->count());
        $this->assertDatabaseHas('chantiers', ['client_id' => $vrai->id, 'statut' => 'en-cours', 'progression' => 55]);

        // Le journal des vrais dossiers n'a pas bougé d'une entrée.
        $this->assertSame($vraisJournal, Activity::query()
            ->where('subject_type', Client::class)
            ->whereIn('subject_id', [$vrai->id, $secondVrai->id])
            ->count());
        $this->assertSame(1, Activity::query()->where('event', 'test-reel')->count());
    }

    /**
     * Le rattrapage des comptes orphelins ne se contente PAS du préfixe : un
     * vrai client dont l'adresse commencerait par « demo- » sans appartenir au
     * domaine de la démonstration doit survivre à la purge.
     */
    public function test_clear_spares_a_real_account_whose_address_merely_looks_like_a_demo_one(): void
    {
        $sosie = User::factory()->create(['email' => 'demo-demba.sarr@exemple.sn']);
        $sosie->assignRole('client');

        $orphelin = User::factory()->create([
            'email' => DemoDataService::PREFIX.'orphelin99@'.DemoDataService::EMAIL_DOMAIN,
        ]);
        $orphelin->assignRole('client');

        $this->withToken($this->adminToken())->deleteJson('/api/staff/demo/clear')
            ->assertOk()
            ->assertJsonPath('data.comptes', 1);   // l'orphelin, pas le sosie

        $this->assertDatabaseHas('users', ['id' => $sosie->id]);
        $this->assertDatabaseMissing('users', ['id' => $orphelin->id]);
    }

    /** Purger une plateforme sans démonstration ne casse rien et ne supprime rien. */
    public function test_clearing_an_empty_platform_is_harmless(): void
    {
        $vrai = Client::create([
            'name' => 'Dossier réel', 'ref' => Client::generateRef(), 'date_inscription' => now(),
        ])->refresh();

        $this->withToken($this->adminToken())->deleteJson('/api/staff/demo/clear')
            ->assertOk()
            ->assertJsonPath('data.clients', 0);

        $this->assertDatabaseHas('clients', ['id' => $vrai->id]);
        $this->assertSame(1, Client::query()->count());
    }

    // ─── Contrôle d'accès ─────────────────────────────────────

    /** `manage-demo-data` n'appartient qu'au super-admin. */
    public function test_an_agent_is_refused_on_both_demo_routes(): void
    {
        $this->withToken($this->agentToken())->postJson('/api/staff/demo/seed')->assertStatus(403);
        $this->forgetAuthState();
        $this->withToken($this->agentToken())->deleteJson('/api/staff/demo/clear')->assertStatus(403);

        $this->assertSame(0, Client::query()->count());
    }

    public function test_a_client_token_is_refused_on_both_demo_routes(): void
    {
        $user = User::factory()->create();
        $user->assignRole('client');
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson('/api/staff/demo/seed')->assertStatus(403);
        $this->forgetAuthState();
        $this->withToken($token)->deleteJson('/api/staff/demo/clear')->assertStatus(403);
    }

    public function test_the_demo_routes_require_authentication(): void
    {
        $this->postJson('/api/staff/demo/seed')->assertStatus(401);
        $this->deleteJson('/api/staff/demo/clear')->assertStatus(401);
    }

    // ─── Journal ──────────────────────────────────────────────

    /** Les deux opérations laissent une trace dans l'historique. */
    public function test_both_operations_are_journalled(): void
    {
        $this->chargerDemo()->assertOk();
        $this->assertSame(1, Activity::query()->where('event', 'demo-chargee')->count());

        $this->forgetAuthState();
        $this->withToken($this->adminToken())->deleteJson('/api/staff/demo/clear')->assertOk();

        // L'entrée de chargement a été écrite par l'admin (pas par un compte de
        // démo) : la purge ne doit pas l'emporter.
        $this->assertSame(1, Activity::query()->where('event', 'demo-chargee')->count());
        $this->assertSame(1, Activity::query()->where('event', 'demo-supprimee')->count());
    }
}
