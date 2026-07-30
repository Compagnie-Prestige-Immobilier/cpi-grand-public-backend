<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CpiDoc;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Documents CPI (Phase 3) : cycle brouillon → publication → signature → archive.
 */
class CpiDocTest extends TestCase
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

    private function makeClient(): Client
    {
        return Client::create([
            'name' => 'Client CPI Docs',
            'ref' => Client::generateRef(),
            'email' => uniqid('c').'@example.com',
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

    private function makeDoc(Client $client, array $overrides = []): CpiDoc
    {
        return CpiDoc::create([
            'client_id' => $client->id,
            'categorie' => 'contrats',
            'nom' => 'Contrat de réservation',
            'auteur' => 'Agent CPI',
            'date_creation' => now(),
            ...$overrides,
        ])->refresh();
    }

    // ─── Staff ────────────────────────────────────────────────

    public function test_store_creates_draft(): void
    {
        $client = $this->makeClient();

        $response = $this->withToken($this->agentToken())->postJson('/api/staff/cpi-docs', [
            'client_id' => $client->id,
            'categorie' => 'contrats',
            'nom' => 'Contrat de réservation',
            'signature_requise' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'brouillon')
            ->assertJsonPath('data.visibleClient', false)
            ->assertJsonPath('data.signatureRequise', true)
            ->assertJsonPath('data.auteur', 'Agent CPI')
            ->assertJsonPath('data.clientId', $client->id);
    }

    public function test_store_requires_valid_client(): void
    {
        $this->withToken($this->agentToken())->postJson('/api/staff/cpi-docs', [
            'client_id' => '00000000-0000-0000-0000-000000000000',
            'categorie' => 'contrats',
            'nom' => 'X',
        ])->assertStatus(422);
    }

    public function test_index_lists_and_filters_by_client(): void
    {
        $clientA = $this->makeClient();
        $clientB = $this->makeClient();
        $this->makeDoc($clientA, ['nom' => 'Doc A']);
        $this->makeDoc($clientB, ['nom' => 'Doc B']);

        $token = $this->agentToken();

        $all = $this->withToken($token)->getJson('/api/staff/cpi-docs');
        $all->assertOk();
        $this->assertCount(2, $all->json('data'));

        // Forme STEP 12.2 : ?client_id=…
        $bare = $this->withToken($token)->getJson('/api/staff/cpi-docs?client_id='.$clientA->id);
        $this->assertCount(1, $bare->json('data'));
        $this->assertSame('Doc A', $bare->json('data.0.nom'));

        // Forme spatie/laravel-query-builder : ?filter[client_id]=…
        $filtered = $this->withToken($token)->getJson('/api/staff/cpi-docs?filter[client_id]='.$clientB->id);
        $this->assertCount(1, $filtered->json('data'));
        $this->assertSame('Doc B', $filtered->json('data.0.nom'));
    }

    public function test_update_changes_fields(): void
    {
        $doc = $this->makeDoc($this->makeClient());

        $this->withToken($this->agentToken())->putJson('/api/staff/cpi-docs/'.$doc->id, [
            'nom' => 'Contrat définitif',
            'signature_requise' => true,
        ])->assertOk()
            ->assertJsonPath('data.nom', 'Contrat définitif')
            ->assertJsonPath('data.signatureRequise', true);
    }

    public function test_publish_without_signature_sets_disponible(): void
    {
        $doc = $this->makeDoc($this->makeClient());

        $this->withToken($this->agentToken())->postJson('/api/staff/cpi-docs/'.$doc->id.'/publish')
            ->assertOk()
            ->assertJsonPath('data.status', 'disponible')
            ->assertJsonPath('data.visibleClient', true);
        $this->assertNotNull($doc->refresh()->date_publication);
    }

    public function test_publish_with_signature_sets_a_signer(): void
    {
        $doc = $this->makeDoc($this->makeClient(), ['signature_requise' => true]);

        $this->withToken($this->agentToken())->postJson('/api/staff/cpi-docs/'.$doc->id.'/publish')
            ->assertOk()
            ->assertJsonPath('data.status', 'a-signer')
            ->assertJsonPath('data.visibleClient', true);
    }

    public function test_archive_hides_from_client(): void
    {
        $doc = $this->makeDoc($this->makeClient(), ['status' => 'disponible', 'visible_client' => true]);

        $this->withToken($this->agentToken())->postJson('/api/staff/cpi-docs/'.$doc->id.'/archive')
            ->assertOk()
            ->assertJsonPath('data.status', 'archive')
            ->assertJsonPath('data.visibleClient', false);
    }

    public function test_agent_sign_marks_signed(): void
    {
        $doc = $this->makeDoc($this->makeClient(), ['status' => 'a-signer', 'signature_requise' => true, 'visible_client' => true]);

        $this->withToken($this->agentToken())->postJson('/api/staff/cpi-docs/'.$doc->id.'/sign')
            ->assertOk()
            ->assertJsonPath('data.status', 'signe')
            ->assertJsonPath('data.signatureRequise', false);
    }

    public function test_agent_cannot_destroy_cpi_doc(): void
    {
        $doc = $this->makeDoc($this->makeClient());

        $this->withToken($this->agentToken())->deleteJson('/api/staff/cpi-docs/'.$doc->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('cpi_docs', ['id' => $doc->id]);
    }

    public function test_admin_can_destroy_cpi_doc(): void
    {
        $doc = $this->makeDoc($this->makeClient());

        $this->withToken($this->adminToken())->deleteJson('/api/staff/cpi-docs/'.$doc->id)
            ->assertOk();

        $this->assertDatabaseMissing('cpi_docs', ['id' => $doc->id]);
    }

    // ─── Client ───────────────────────────────────────────────

    public function test_client_mine_returns_only_visible_docs(): void
    {
        [, $client, $token] = $this->makeClientUser();
        $this->makeDoc($client, ['nom' => 'Visible', 'status' => 'disponible', 'visible_client' => true]);
        $this->makeDoc($client, ['nom' => 'Brouillon caché', 'status' => 'brouillon', 'visible_client' => false]);

        $response = $this->withToken($token)->getJson('/api/client/mes-documents-cpi');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Visible', $response->json('data.0.nom'));
    }

    public function test_client_can_sign_own_visible_doc(): void
    {
        [, $client, $token] = $this->makeClientUser();
        $doc = $this->makeDoc($client, ['status' => 'a-signer', 'signature_requise' => true, 'visible_client' => true]);

        $this->withToken($token)->postJson('/api/client/mes-documents-cpi/'.$doc->id.'/sign')
            ->assertOk()
            ->assertJsonPath('data.status', 'signe');
    }

    public function test_client_cannot_sign_another_clients_doc(): void
    {
        $other = $this->makeClient();
        $foreignDoc = $this->makeDoc($other, ['status' => 'a-signer', 'signature_requise' => true, 'visible_client' => true]);
        [, , $token] = $this->makeClientUser();

        $this->withToken($token)->postJson('/api/client/mes-documents-cpi/'.$foreignDoc->id.'/sign')
            ->assertStatus(403);
    }

    public function test_client_cannot_sign_hidden_doc(): void
    {
        [, $client, $token] = $this->makeClientUser();
        $doc = $this->makeDoc($client, ['status' => 'brouillon', 'visible_client' => false]);

        $this->withToken($token)->postJson('/api/client/mes-documents-cpi/'.$doc->id.'/sign')
            ->assertStatus(403);
    }

    public function test_cpi_doc_routes_require_authentication(): void
    {
        $this->getJson('/api/staff/cpi-docs')->assertStatus(401);
        $this->getJson('/api/client/mes-documents-cpi')->assertStatus(401);
    }
}
