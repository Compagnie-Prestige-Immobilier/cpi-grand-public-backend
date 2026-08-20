<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Journal d'activité (Phase 6). Lecture seule et strictement interne : les
 * entrées naissent des mutations métier des phases 1 à 5, jamais d'un appel à
 * /staff/historique.
 */
class HistoriqueTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function agent(): User
    {
        /** @var User $agent */
        $agent = User::query()->where('email', 'agent@cpi.sn')->firstOrFail();

        return $agent;
    }

    private function agentToken(): string
    {
        return $this->agent()->createToken('t')->plainTextToken;
    }

    private function admin(): User
    {
        /** @var User $admin */
        $admin = User::query()->where('email', 'admin@cpi.sn')->firstOrFail();

        return $admin;
    }

    private function adminToken(): string
    {
        return $this->admin()->createToken('t')->plainTextToken;
    }

    /**
     * `conseiller_id` par défaut à l'agent seedé : ces tests portent sur le
     * JOURNAL (pagination, sérialisation, filtrage par dossier), pas sur le
     * cloisonnement lui-même — sous le cloisonnement strict, une entrée dont
     * le sujet appartient à un dossier non attribué serait invisible pour
     * l'agent qui interroge `/staff/historique`, cassant ces tests pour une
     * raison qui n'est pas celle qu'ils vérifient.
     */
    private function makeClient(string $name = 'Dossier Historique'): Client
    {
        return Client::create([
            'name' => $name,
            'ref' => Client::generateRef(),
            'date_inscription' => now(),
            'conseiller_id' => $this->agent()->id,
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

    private function log(Client $client, string $event, string $description): void
    {
        activity()
            ->causedBy($this->agent())
            ->performedOn($client)
            ->withProperties(['doc_id' => 'identite'])
            ->event($event)
            ->log($description);
    }

    // ─── Journal global ───────────────────────────────────────

    public function test_index_returns_a_paginated_journal_newest_first(): void
    {
        $client = $this->makeClient();
        Activity::query()->delete();   // on ne teste que nos propres entrées
        $this->log($client, 'doc-depose', 'Entrée la plus ancienne');
        $this->log($client, 'validated', 'Entrée la plus récente');

        $response = $this->withToken($this->agentToken())->getJson('/api/staff/historique');

        $response->assertOk()
            ->assertJsonPath('data.0.description', 'Entrée la plus récente')
            ->assertJsonPath('data.0.event', 'validated')
            ->assertJsonPath('data.1.description', 'Entrée la plus ancienne')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 50);
    }

    /** L'auteur et le dossier sont dénormalisés : l'écran n'a rien à recouper. */
    public function test_index_exposes_the_causer_name_the_client_and_the_properties(): void
    {
        $client = $this->makeClient('Awa Ndiaye');
        Activity::query()->delete();
        $this->log($client, 'doc-depose', 'Awa Ndiaye a déposé le document Pièce d\'identité');

        $this->withToken($this->agentToken())->getJson('/api/staff/historique')
            ->assertOk()
            ->assertJsonPath('data.0.causerName', 'Agent CPI')
            ->assertJsonPath('data.0.causerType', User::class)
            ->assertJsonPath('data.0.subjectType', Client::class)
            ->assertJsonPath('data.0.subjectId', $client->id)
            ->assertJsonPath('data.0.clientId', $client->id)
            ->assertJsonPath('data.0.clientName', 'Awa Ndiaye')
            ->assertJsonPath('data.0.properties.doc_id', 'identite')
            ->assertJsonPath('data.0.logName', 'default');
    }

    /** L'horodatage suit le format de toute l'API : « AAAA-MM-JJ HH:MM:SS ». */
    public function test_index_serialises_created_at_like_the_rest_of_the_api(): void
    {
        $client = $this->makeClient();
        Activity::query()->delete();
        $this->log($client, 'validated', 'Pièce validée');

        $createdAt = $this->withToken($this->agentToken())
            ->getJson('/api/staff/historique')
            ->assertOk()
            ->json('data.0.createdAt');

        $this->assertIsString($createdAt);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $createdAt);
    }

    public function test_index_paginates_beyond_fifty_entries(): void
    {
        $client = $this->makeClient();
        Activity::query()->delete();
        for ($i = 0; $i < 55; $i++) {
            $this->log($client, 'validated', 'Entrée '.$i);
        }

        $this->withToken($this->agentToken())->getJson('/api/staff/historique')
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.total', 55)
            ->assertJsonPath('meta.last_page', 2);

        $this->withToken($this->agentToken())->getJson('/api/staff/historique?page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data');
    }

    /** Les mutations des phases précédentes alimentent le journal sans un mot de plus. */
    public function test_a_business_mutation_shows_up_in_the_journal(): void
    {
        $client = $this->makeClient();
        Activity::query()->delete();

        $this->withToken($this->agentToken())
            ->postJson('/api/staff/chantiers/'.$client->id.'/progression', ['pct' => 30])
            ->assertOk();

        $this->withToken($this->agentToken())->getJson('/api/staff/historique')
            ->assertOk()
            ->assertJsonPath('data.0.event', 'chantier-progression')
            ->assertJsonPath('data.0.clientId', $client->id)
            ->assertJsonPath('data.0.causerName', 'Agent CPI')
            ->assertJsonPath('data.0.properties.nouvelle', 30);
    }

    // ─── Journal d'un dossier ─────────────────────────────────

    public function test_for_client_returns_only_that_clients_entries(): void
    {
        $clientA = $this->makeClient('Dossier A');
        $clientB = $this->makeClient('Dossier B');
        Activity::query()->delete();
        $this->log($clientA, 'doc-depose', 'Pièce de A');
        $this->log($clientA, 'validated', 'Validation de A');
        $this->log($clientB, 'doc-depose', 'Pièce de B');
        // Entrée sans sujet : ne doit apparaître dans aucun journal de dossier.
        activity()->causedBy($this->agent())->event('client-supprime')->log('Entrée sans dossier');

        $response = $this->withToken($this->agentToken())
            ->getJson('/api/staff/historique/'.$clientA->id);

        $response->assertOk()->assertJsonCount(2, 'data');
        foreach ($response->json('data') as $entry) {
            $this->assertSame($clientA->id, $entry['clientId']);
        }
        $this->assertStringNotContainsString('Pièce de B', $response->getContent() ?: '');
        $this->assertStringNotContainsString('Entrée sans dossier', $response->getContent() ?: '');
    }

    public function test_for_client_returns_an_empty_list_for_a_quiet_dossier(): void
    {
        $client = $this->makeClient();
        Activity::query()->delete();

        $this->withToken($this->agentToken())->getJson('/api/staff/historique/'.$client->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_for_client_returns_404_for_an_unknown_dossier(): void
    {
        $this->withToken($this->agentToken())
            ->getJson('/api/staff/historique/019fb302-0098-715f-a528-56360e84eb74')
            ->assertStatus(404);
    }

    // ─── Cloisonnement du journal (STEP 4) ─────────────────────

    public function test_index_excludes_a_colleagues_dossier(): void
    {
        // Le cœur du correctif : avant, TOUT agent-cpi lisait le journal
        // COMPLET de la plateforme — identités, revenus, montants — quel que
        // soit le portefeuille auquel il appartenait réellement.
        $collegue = User::factory()->create();
        $collegue->assignRole('agent-cpi');
        $dossierDuCollegue = Client::create([
            'name' => 'Dossier du collègue', 'ref' => Client::generateRef(), 'conseiller_id' => $collegue->id,
        ]);
        Activity::query()->delete();
        activity()->causedBy($collegue)->performedOn($dossierDuCollegue)
            ->event('validated')->log('Pièce du collègue validée');

        $this->withToken($this->agentToken())->getJson('/api/staff/historique')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_index_includes_the_agents_own_client_account_events(): void
    {
        // `compte-valide`, `compte-rejete`… visent le `User` du client, pas le
        // `Client` lui-même : sans la résolution User → client, un agent
        // perdrait la trace de la validation ou de l'attribution de SES
        // PROPRES clients — l'essentiel de ce que cet historique sert à
        // retrouver.
        //
        // `makeClient()` ne relie à aucun `User` : il faut ici un client dont
        // le compte de connexion existe réellement, sujet de l'événement.
        $utilisateur = User::factory()->create();
        $utilisateur->assignRole('client');
        $client = Client::create([
            'user_id' => $utilisateur->id, 'name' => $utilisateur->name, 'ref' => Client::generateRef(),
            'email' => $utilisateur->email, 'date_inscription' => now(), 'conseiller_id' => $this->agent()->id,
        ]);
        Activity::query()->delete();
        activity()->causedBy($this->admin())
            ->performedOn($utilisateur)
            ->event('compte-valide')->log('Compte validé');

        $this->withToken($this->agentToken())->getJson('/api/staff/historique')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.event', 'compte-valide');
    }

    public function test_index_excludes_platform_level_events(): void
    {
        // Création/suppression d'un compte du personnel, données de
        // démonstration : aucun sujet, ou un sujet `User` sans `Client`
        // associé — ces événements ne concernent AUCUN dossier, ils restent
        // réservés au super-admin comme la gestion du personnel elle-même.
        $unAutreAgent = User::factory()->create();
        $unAutreAgent->assignRole('agent-cpi');
        Activity::query()->delete();
        activity()->causedBy($this->admin())
            ->performedOn($unAutreAgent)
            ->event('compte-staff-supprime')->log('Compte du personnel supprimé');

        $this->withToken($this->agentToken())->getJson('/api/staff/historique')
            ->assertOk()->assertJsonPath('meta.total', 0);

        $this->withToken($this->adminToken())->getJson('/api/staff/historique')
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_index_excludes_an_unassigned_dossier(): void
    {
        // Cohérent avec `ClientController::index` : un dossier non attribué
        // n'apparaît dans le portefeuille d'AUCUN agent, y compris dans son
        // journal.
        $sansConseiller = Client::create(['name' => 'Sans conseiller', 'ref' => Client::generateRef()]);
        Activity::query()->delete();
        activity()->causedBy($this->agent())->performedOn($sansConseiller)
            ->event('validated')->log('Entrée sur un dossier non attribué');

        $this->withToken($this->agentToken())->getJson('/api/staff/historique')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_admin_sees_every_portfolio_and_every_platform_event(): void
    {
        $collegue = User::factory()->create();
        $collegue->assignRole('agent-cpi');
        $dossierDuCollegue = Client::create([
            'name' => 'Dossier du collègue', 'ref' => Client::generateRef(), 'conseiller_id' => $collegue->id,
        ]);
        Activity::query()->delete();
        activity()->causedBy($collegue)->performedOn($dossierDuCollegue)
            ->event('validated')->log('Pièce du collègue validée');
        activity()->causedBy($this->admin())->performedOn($collegue)
            ->event('compte-staff-supprime')->log('Compte du personnel supprimé');

        $this->withToken($this->adminToken())->getJson('/api/staff/historique')
            ->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_admin_can_also_read_the_journal(): void
    {
        $client = $this->makeClient();
        Activity::query()->delete();
        $this->log($client, 'validated', 'Pièce validée');

        $this->withToken($this->adminToken())->getJson('/api/staff/historique')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
        $this->withToken($this->adminToken())->getJson('/api/staff/historique/'.$client->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ─── Séparation des rôles ─────────────────────────────────

    public function test_client_token_is_refused_on_every_historique_route(): void
    {
        [, $client, $token] = $this->makeClientUser();

        $this->withToken($token)->getJson('/api/staff/historique')->assertStatus(403);
        $this->withToken($token)->getJson('/api/staff/historique/'.$client->id)->assertStatus(403);
    }

    public function test_historique_routes_require_authentication(): void
    {
        $client = $this->makeClient();

        $this->getJson('/api/staff/historique')->assertStatus(401);
        $this->getJson('/api/staff/historique/'.$client->id)->assertStatus(401);
    }

    /** Un membre du personnel privé de `view-clients` ne lit pas le journal. */
    public function test_a_staff_account_without_view_clients_is_refused(): void
    {
        Role::findByName('agent-cpi')->revokePermissionTo('view-clients');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $client = $this->makeClient();

        $this->withToken($this->agentToken())
            ->getJson('/api/staff/historique')
            ->assertStatus(403);
        $this->withToken($this->agentToken())
            ->getJson('/api/staff/historique/'.$client->id)
            ->assertStatus(403);
    }
}
