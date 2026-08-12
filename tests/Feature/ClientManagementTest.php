<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Demande;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * CRUD staff des clients (Phase 3) : policies, ref générée, étape dossier, journey.
 */
class ClientManagementTest extends TestCase
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

    private function makeClient(array $overrides = []): Client
    {
        return Client::create([
            'name' => 'Client Test',
            'ref' => Client::generateRef(),
            'email' => uniqid('c').'@example.com',
            'date_inscription' => now(),
            ...$overrides,
        ])->refresh();
    }

    public function test_agent_can_list_clients_paginated(): void
    {
        $this->makeClient(['name' => 'Alpha']);
        $this->makeClient(['name' => 'Beta']);

        $response = $this->withToken($this->agentToken())->getJson('/api/staff/clients');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertArrayHasKey('meta', $response->json());
        // Relations chargées pour l'agrégation côté staff
        $this->assertArrayHasKey('requisDocs', $response->json('data.0'));
        $this->assertArrayHasKey('demande', $response->json('data.0'));
    }

    public function test_show_returns_client_with_relations(): void
    {
        $client = $this->makeClient();
        Demande::create(['client_id' => $client->id, 'montant' => 25000000, 'submitted' => true]);

        $response = $this->withToken($this->agentToken())->getJson('/api/staff/clients/'.$client->id);

        $response->assertOk()
            ->assertJsonPath('data.id', $client->id)
            ->assertJsonPath('data.demande.submitted', true)
            ->assertJsonPath('data.requisDocs.0.docId', 'identite');
    }

    public function test_new_client_exposes_its_three_required_docs_before_the_client_ever_logs_in(): void
    {
        // Le staff doit pouvoir trier les pièces d'un dossier tout juste ouvert :
        // elles sont créées avec le client, pas à sa première visite.
        $client = $this->makeClient();

        $this->withToken($this->agentToken())->getJson('/api/staff/clients/'.$client->id)
            ->assertOk()
            ->assertJsonPath('data.requisDocs.0.docId', 'identite')
            ->assertJsonPath('data.requisDocs.1.docId', 'revenus')
            ->assertJsonPath('data.requisDocs.2.docId', 'bancaires')
            ->assertJsonPath('data.requisDocs.0.status', 'en-attente');

        $this->withToken($this->agentToken())
            ->postJson('/api/staff/clients/'.$client->id.'/docs/identite/verify')
            ->assertOk();
    }

    public function test_store_creates_client_with_generated_ref(): void
    {
        $response = $this->withToken($this->agentToken())->postJson('/api/staff/clients', [
            'name' => 'Moussa Sarr',
            'email' => 'moussa@example.com',
            'project_nom' => 'Villa Diamniadio',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.name', 'Moussa Sarr');
        $ref = $response->json('data.ref');
        $this->assertStringStartsWith('CPI-'.now()->year.'-', $ref);
        $this->assertDatabaseHas('clients', ['ref' => $ref, 'project_nom' => 'Villa Diamniadio']);
    }

    public function test_store_requires_name(): void
    {
        $this->withToken($this->agentToken())->postJson('/api/staff/clients', [
            'email' => 'sans-nom@example.com',
        ])->assertStatus(422);
    }

    public function test_update_changes_fields(): void
    {
        $client = $this->makeClient();

        $response = $this->withToken($this->agentToken())->putJson('/api/staff/clients/'.$client->id, [
            'statut' => 'Dossier en analyse',
            'progression' => 40,
            'banque' => 'CBAO',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.statut', 'Dossier en analyse')
            ->assertJsonPath('data.progression', 40)
            ->assertJsonPath('data.banque', 'CBAO');
    }

    public function test_agent_cannot_delete_client(): void
    {
        $client = $this->makeClient();

        // delete-client n'appartient qu'au super-admin
        $this->withToken($this->agentToken())->deleteJson('/api/staff/clients/'.$client->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }

    public function test_admin_can_delete_client(): void
    {
        $client = $this->makeClient();

        $this->withToken($this->adminToken())->deleteJson('/api/staff/clients/'.$client->id)
            ->assertOk();

        // Suppression douce : le dossier de financement d'un particulier
        // (pièces d'identité, montants, historique) ne doit plus pouvoir
        // disparaître définitivement sur un clic.
        $this->assertSoftDeleted('clients', ['id' => $client->id]);
    }

    public function test_summary_returns_lightweight_shape(): void
    {
        $client = $this->makeClient(['project_nom' => 'Résidence Almadies']);

        $response = $this->withToken($this->agentToken())->getJson('/api/staff/clients/'.$client->id.'/summary');

        $response->assertOk()
            ->assertJsonPath('data.projectNom', 'Résidence Almadies')
            ->assertJsonPath('data.dossierEtape', 0);
        $this->assertArrayNotHasKey('demande', $response->json('data'));
    }

    public function test_set_dossier_etape_updates_and_logs(): void
    {
        $client = $this->makeClient();

        $response = $this->withToken($this->agentToken())
            ->postJson('/api/staff/clients/'.$client->id.'/dossier-etape', ['etape' => 3]);

        $response->assertOk()->assertJsonPath('data.dossierEtape', 3);
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'dossier_etape' => 3]);

        $activity = Activity::query()->where('event', 'dossier-etape')->first();
        $this->assertNotNull($activity);
        $this->assertSame($client->id, $activity->subject_id);
    }

    public function test_set_dossier_etape_rejects_out_of_range(): void
    {
        $client = $this->makeClient();

        $this->withToken($this->agentToken())
            ->postJson('/api/staff/clients/'.$client->id.'/dossier-etape', ['etape' => 7])
            ->assertStatus(422);
    }

    public function test_dossier_journey_computation(): void
    {
        $client = $this->makeClient(['dossier_etape' => 4]);
        Demande::create(['client_id' => $client->id, 'submitted' => true, 'submitted_at' => now()]);
        // Les trois pièces requises existent déjà (créées avec le dossier).
        $client->requisDocs()->update(['status' => 'accepte']);

        $this->withToken($this->agentToken())
            ->getJson('/api/staff/clients/'.$client->id.'/dossier-journey')
            ->assertOk()
            ->assertJsonPath('data.step', 4)
            ->assertJsonPath('data.submitted', true);
    }

    public function test_dossier_journey_step_1_when_docs_incomplete(): void
    {
        $client = $this->makeClient(['dossier_etape' => 5]);
        Demande::create(['client_id' => $client->id, 'submitted' => true]);
        $client->requisDocs()->where('doc_id', 'identite')->update(['status' => 'depose']);

        $this->withToken($this->agentToken())
            ->getJson('/api/staff/clients/'.$client->id.'/dossier-journey')
            ->assertOk()
            ->assertJsonPath('data.step', 1);
    }

    public function test_client_endpoints_require_authentication(): void
    {
        $client = $this->makeClient();

        $this->getJson('/api/staff/clients')->assertStatus(401);
        $this->getJson('/api/staff/clients/'.$client->id)->assertStatus(401);
        $this->postJson('/api/staff/clients', [])->assertStatus(401);
    }

    // ─── Cloisonnement par conseiller ─────────────────────────

    public function test_an_agent_cannot_open_a_dossier_assigned_to_another_adviser(): void
    {
        // `clients.conseiller_id` existait depuis l'origine sans qu'aucune
        // policy ne le lise : tout agent voyait et modifiait TOUS les dossiers
        // — pièces d'identité, revenus, montants, documents contractuels.
        $autreConseiller = User::factory()->create();
        $autreConseiller->assignRole('agent-cpi');

        $client = Client::create([
            'name' => 'Dossier d\'un collègue',
            'ref' => Client::generateRef(),
            'conseiller_id' => $autreConseiller->id,
        ]);

        $agent = User::query()->where('email', 'agent@cpi.sn')->firstOrFail();

        $this->withToken($agent->createToken('t')->plainTextToken)
            ->getJson("/api/staff/clients/{$client->id}")
            ->assertForbidden();

        $this->withToken($agent->createToken('t2')->plainTextToken)
            ->putJson("/api/staff/clients/{$client->id}", ['name' => 'Renommé'])
            ->assertForbidden();
    }

    public function test_an_unassigned_dossier_stays_open_to_any_agent(): void
    {
        // Sinon les nouvelles demandes tomberaient dans un angle mort le temps
        // qu'un administrateur les attribue.
        $client = Client::create(['name' => 'Sans conseiller', 'ref' => Client::generateRef()]);
        $agent = User::query()->where('email', 'agent@cpi.sn')->firstOrFail();

        $this->withToken($agent->createToken('t')->plainTextToken)
            ->getJson("/api/staff/clients/{$client->id}")
            ->assertOk();
    }

    public function test_a_super_admin_sees_every_dossier(): void
    {
        $autreConseiller = User::factory()->create();
        $autreConseiller->assignRole('agent-cpi');
        $client = Client::create([
            'name' => 'Dossier attribué',
            'ref' => Client::generateRef(),
            'conseiller_id' => $autreConseiller->id,
        ]);

        $admin = User::query()->where('email', 'admin@cpi.sn')->firstOrFail();

        $this->withToken($admin->createToken('t')->plainTextToken)
            ->getJson("/api/staff/clients/{$client->id}")
            ->assertOk();
    }

    public function test_the_staff_listing_is_filtered_to_the_advisers_portfolio(): void
    {
        // La policy cloisonne dossier par dossier ; la liste doit l'être à la
        // source, sinon un agent lirait les noms, références et montants de
        // tout le portefeuille CPI avant même de cliquer.
        $autreConseiller = User::factory()->create();
        $autreConseiller->assignRole('agent-cpi');

        Client::create(['name' => 'Le mien', 'ref' => Client::generateRef()]);
        Client::create([
            'name' => 'Celui du collègue',
            'ref' => Client::generateRef(),
            'conseiller_id' => $autreConseiller->id,
        ]);

        $agent = User::query()->where('email', 'agent@cpi.sn')->firstOrFail();

        $noms = collect($this->withToken($agent->createToken('t')->plainTextToken)
            ->getJson('/api/staff/clients')->json('data'))->pluck('name');

        $this->assertTrue($noms->contains('Le mien'));
        $this->assertFalse($noms->contains('Celui du collègue'));
    }
}
