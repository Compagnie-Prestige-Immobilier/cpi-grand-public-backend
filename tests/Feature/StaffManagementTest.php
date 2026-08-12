<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run the DatabaseSeeder before each test.
     */
    protected bool $seed = true;

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

    // ─── createStaff ──────────────────────────────────────────

    public function test_admin_can_create_staff_and_receives_temporary_password(): void
    {
        $response = $this->withToken($this->adminToken())->postJson('/api/staff/staff/create', [
            'name' => 'Nouvel Agent',
            'email' => 'nouvel.agent@cpi.sn',
            'role' => 'agent-cpi',
        ]);

        // laravel-data répond 201 pour un Data retourné sur une requête POST
        $response->assertStatus(201)
            ->assertJsonPath('data.email', 'nouvel.agent@cpi.sn')
            ->assertJsonPath('data.role', 'agent-cpi');

        $tempPassword = $response->json('temporary_password');
        $this->assertIsString($tempPassword);
        // Aligné sur la politique appliquée aux inscriptions : un compte du
        // personnel accède à tous les dossiers, il n'a pas à démarrer avec une
        // exigence plus faible qu'un compte client.
        $this->assertSame(16, strlen($tempPassword));

        /** @var User $created */
        $created = User::query()->where('email', 'nouvel.agent@cpi.sn')->firstOrFail();
        $this->assertTrue($created->hasRole('agent-cpi'));

        // Le mot de passe temporaire permet de se connecter
        $this->postJson('/api/auth/login', [
            'email' => 'nouvel.agent@cpi.sn',
            'password' => $tempPassword,
        ])->assertOk()->assertJsonPath('data.role', 'agent-cpi');
    }

    public function test_agent_cannot_create_staff(): void
    {
        $this->withToken($this->agentToken())->postJson('/api/staff/staff/create', [
            'name' => 'X',
            'email' => 'x@cpi.sn',
            'role' => 'agent-cpi',
        ])->assertStatus(403);
    }

    public function test_create_staff_rejects_invalid_role(): void
    {
        $this->withToken($this->adminToken())->postJson('/api/staff/staff/create', [
            'name' => 'X',
            'email' => 'x@cpi.sn',
            'role' => 'client',
        ])->assertStatus(422);
    }

    public function test_create_staff_requires_authentication(): void
    {
        $this->postJson('/api/staff/staff/create', [])->assertStatus(401);
    }

    // ─── listStaff ────────────────────────────────────────────

    public function test_admin_can_list_staff(): void
    {
        $response = $this->withToken($this->adminToken())->getJson('/api/staff/staff/list');

        $response->assertOk();
        $emails = array_column((array) $response->json('data'), 'email');
        $this->assertContains('agent@cpi.sn', $emails);
        $this->assertContains('admin@cpi.sn', $emails);
    }

    public function test_agent_cannot_list_staff(): void
    {
        $this->withToken($this->agentToken())->getJson('/api/staff/staff/list')
            ->assertStatus(403);
    }

    // ─── deleteStaff ──────────────────────────────────────────

    public function test_admin_can_delete_a_staff_member(): void
    {
        /** @var User $agent */
        $agent = User::query()->where('email', 'agent@cpi.sn')->firstOrFail();

        $this->withToken($this->adminToken())->deleteJson('/api/staff/staff/'.$agent->id)
            ->assertOk();

        $this->assertDatabaseMissing('users', ['email' => 'agent@cpi.sn']);
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        /** @var User $admin */
        $admin = User::query()->where('email', 'admin@cpi.sn')->firstOrFail();

        $this->withToken($this->adminToken())->deleteJson('/api/staff/staff/'.$admin->id)
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['email' => 'admin@cpi.sn']);
    }

    public function test_agent_cannot_delete_staff(): void
    {
        /** @var User $admin */
        $admin = User::query()->where('email', 'admin@cpi.sn')->firstOrFail();

        $this->withToken($this->agentToken())->deleteJson('/api/staff/staff/'.$admin->id)
            ->assertStatus(403);
    }

    public function test_delete_staff_rejects_non_staff_account(): void
    {
        $client = User::factory()->create();
        $client->assignRole('client');

        $this->withToken($this->adminToken())->deleteJson('/api/staff/staff/'.$client->id)
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $client->id]);
    }

    public function test_creating_and_deleting_a_staff_account_is_journalled(): void
    {
        // La création d'un compte capable de lire tous les dossiers clients ne
        // laissait aucune trace : le journal couvrait les gestes métier, pas
        // les mouvements de comptes du personnel.
        $reponse = $this->withToken($this->adminToken())->postJson('/api/staff/staff/create', [
            'name' => 'Agent Journalisé',
            'email' => 'journalise@cpi.sn',
            'role' => 'agent-cpi',
        ])->assertCreated();

        $this->assertDatabaseHas('activity_log', ['event' => 'compte-staff-cree']);

        $id = $reponse->json('data.id');

        $this->withToken($this->adminToken())
            ->deleteJson("/api/staff/staff/{$id}")
            ->assertOk();

        $this->assertDatabaseHas('activity_log', ['event' => 'compte-staff-supprime']);
    }
}
