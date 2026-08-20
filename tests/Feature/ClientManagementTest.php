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

    private function adminToken(): string
    {
        /** @var User $admin */
        $admin = User::query()->where('email', 'admin@cpi.sn')->firstOrFail();

        return $admin->createToken('t')->plainTextToken;
    }

    /**
     * `conseiller_id` par défaut à l'agent SEEDÉ : la plupart de ces tests
     * portent sur une fonctionnalité (étape du dossier, résumé, journey…),
     * pas sur le cloisonnement lui-même — le dossier doit simplement être
     * atteignable par l'agent qui l'utilise. Le cloisonnement strict a rendu
     * cette hypothèse nécessaire ; avant, un dossier sans conseiller restait
     * ouvert à tout agent. Les tests qui portent SUR le cloisonnement
     * (ci-dessous) construisent leur client directement, sans ce défaut.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeClient(array $overrides = []): Client
    {
        return Client::create([
            'name' => 'Client Test',
            'ref' => Client::generateRef(),
            'email' => uniqid('c').'@example.com',
            'date_inscription' => now(),
            'conseiller_id' => $this->agent()->id,
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

        // Mettre « en vérification » une pièce que le client n'a jamais déposée
        // n'a pas de sens : il n'y a rien à examiner. Le cas passait
        // silencieusement — `Rule::in` validait la valeur sans regarder d'où
        // l'on venait.
        $this->withToken($this->agentToken())
            ->postJson('/api/staff/clients/'.$client->id.'/docs/identite/verify')
            ->assertStatus(409);
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

    public function test_an_unassigned_dossier_is_closed_to_every_agent(): void
    {
        // Cloisonnement STRICT (revu depuis) : un dossier sans conseiller
        // n'est plus un filet de sécurité ouvert à tout agent. Deux choses le
        // rendent sûr : `AttributionConseiller` en attribue un automatiquement
        // dès la validation du compte, et les cas résiduels restent
        // actionnables par le super-admin via `?non_attribues=1` et
        // `POST /clients/{client}/attribuer-conseiller` (voir
        // PortefeuilleConseiller). Sans ces deux garanties, redevenir strict
        // ici recréerait exactement l'angle mort que l'ancienne règle comblait.
        $client = Client::create(['name' => 'Sans conseiller', 'ref' => Client::generateRef()]);

        $this->withToken($this->agentToken())
            ->getJson("/api/staff/clients/{$client->id}")
            ->assertForbidden();
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

        Client::create(['name' => 'Le mien', 'ref' => Client::generateRef(), 'conseiller_id' => $this->agent()->id]);
        Client::create([
            'name' => 'Celui du collègue',
            'ref' => Client::generateRef(),
            'conseiller_id' => $autreConseiller->id,
        ]);
        // Cloisonnement STRICT : un dossier non attribué n'apparaît plus dans
        // AUCUN portefeuille d'agent, y compris celui qui consulte la liste.
        Client::create(['name' => 'Sans conseiller', 'ref' => Client::generateRef()]);

        $noms = array_column((array) $this->withToken($this->agentToken())
            ->getJson('/api/staff/clients')->json('data'), 'name');

        $this->assertContains('Le mien', $noms);
        $this->assertNotContains('Celui du collègue', $noms);
        $this->assertNotContains('Sans conseiller', $noms);
    }

    public function test_non_attribues_filter_is_admin_only_in_practice(): void
    {
        // Pas de garde-fou séparé à écrire : pour un agent, `filtrer()` a déjà
        // tout restreint à SON portefeuille (jamais nul) avant que ce filtre
        // ne s'applique — le résultat est mécaniquement vide.
        Client::create(['name' => 'Sans conseiller', 'ref' => Client::generateRef()]);
        Client::create(['name' => 'Le mien', 'ref' => Client::generateRef(), 'conseiller_id' => $this->agent()->id]);

        $nomsAgent = array_column((array) $this->withToken($this->agentToken())
            ->getJson('/api/staff/clients?non_attribues=1')->json('data'), 'name');
        $this->assertSame([], $nomsAgent);

        $nomsAdmin = array_column((array) $this->withToken($this->adminToken())
            ->getJson('/api/staff/clients?non_attribues=1')->json('data'), 'name');
        $this->assertContains('Sans conseiller', $nomsAdmin);
        $this->assertNotContains('Le mien', $nomsAdmin);
    }

    public function test_admin_can_manually_attribute_an_unassigned_dossier(): void
    {
        $client = Client::create(['name' => 'Sans conseiller', 'ref' => Client::generateRef()]);

        $reponse = $this->withToken($this->adminToken())
            ->postJson("/api/staff/clients/{$client->id}/attribuer-conseiller")
            ->assertOk();

        $this->assertSame($this->agent()->id, $reponse->json('data.conseiller.id'));
        $this->assertSame($this->agent()->id, $client->refresh()->conseiller_id);
        $this->assertSame($this->agent()->name, $client->conseiller);
    }

    public function test_agent_cannot_manually_attribute_an_unassigned_dossier(): void
    {
        // La policy `update` referme déjà cette porte : le cloisonnement
        // strict rend ce dossier invisible à TOUT agent, y compris pour
        // cette action.
        $client = Client::create(['name' => 'Sans conseiller', 'ref' => Client::generateRef()]);

        $this->withToken($this->agentToken())
            ->postJson("/api/staff/clients/{$client->id}/attribuer-conseiller")
            ->assertForbidden();
    }

    public function test_manual_attribution_requires_an_available_agent(): void
    {
        User::role('agent-cpi')->delete();
        $client = Client::create(['name' => 'Sans conseiller', 'ref' => Client::generateRef()]);

        $this->withToken($this->adminToken())
            ->postJson("/api/staff/clients/{$client->id}/attribuer-conseiller")
            ->assertStatus(409);
    }
}
