<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run the DatabaseSeeder before each test.
     */
    protected $seed = true;

    // ─── Register ─────────────────────────────────────────────

    public function test_register_creates_user_and_client_with_role_client(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Awa Ndiaye',
            'email' => 'awa@example.com',
            'password' => 'secret1234',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.role', 'client')
            ->assertJsonPath('data.user.email', 'awa@example.com')
            ->assertJsonPath('data.user.needsOnboarding', false);

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertNotEmpty($response->json('data.permissions'));

        /** @var User $user */
        $user = User::query()->where('email', 'awa@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('client'));

        /** @var Client $client */
        $client = Client::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertStringStartsWith('CPI-'.now()->year.'-', $client->ref);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'X',
            'email' => 'agent@cpi.sn',
            'password' => 'secret1234',
        ])->assertStatus(422);
    }

    public function test_register_requires_min_password_length(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'X',
            'email' => 'short@example.com',
            'password' => 'short',
        ])->assertStatus(422);
    }

    // ─── Login ────────────────────────────────────────────────

    public function test_login_returns_correct_role_and_permissions_for_each_seed_role(): void
    {
        // Agent CPI
        $agent = $this->postJson('/api/auth/login', [
            'email' => 'agent@cpi.sn',
            'password' => 'agent1234',
        ]);
        $agent->assertOk()->assertJsonPath('data.role', 'agent-cpi');
        $this->assertNotEmpty($agent->json('data.permissions'));
        $this->assertNotEmpty($agent->json('data.token'));

        // Super admin
        $admin = $this->postJson('/api/auth/login', [
            'email' => 'admin@cpi.sn',
            'password' => 'admin1234',
        ]);
        $admin->assertOk()->assertJsonPath('data.role', 'super-admin');
        $this->assertNotEmpty($admin->json('data.permissions'));

        // Client (inscrit via register)
        $this->postJson('/api/auth/register', [
            'name' => 'Client Test',
            'email' => 'client@example.com',
            'password' => 'secret1234',
        ])->assertStatus(201);

        $client = $this->postJson('/api/auth/login', [
            'email' => 'client@example.com',
            'password' => 'secret1234',
        ]);
        $client->assertOk()->assertJsonPath('data.role', 'client');
        $this->assertNotEmpty($client->json('data.permissions'));
    }

    public function test_login_with_wrong_password_returns_401(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'agent@cpi.sn',
            'password' => 'mauvais-pass',
        ])->assertStatus(401);
    }

    public function test_login_with_unknown_email_returns_401(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'inconnu@example.com',
            'password' => 'whatever123',
        ])->assertStatus(401);
    }

    public function test_login_password_less_google_account_returns_422(): void
    {
        $user = User::factory()->create(['password' => null]);
        $user->assignRole('client');

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'whatever123',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Ce compte utilise Google.');
    }

    // ─── Me ───────────────────────────────────────────────────

    public function test_me_returns_user_with_role_and_permissions(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'email' => 'agent@cpi.sn',
            'password' => 'agent1234',
        ])->json('data.token');

        $me = $this->withToken($token)->getJson('/api/auth/me');

        $me->assertOk()
            ->assertJsonPath('data.role', 'agent-cpi')
            ->assertJsonPath('data.user.email', 'agent@cpi.sn');
        $this->assertNotEmpty($me->json('data.permissions'));
        $this->assertArrayNotHasKey('token', $me->json('data'));
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    // ─── Logout ───────────────────────────────────────────────

    public function test_logout_revokes_the_token(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'email' => 'agent@cpi.sn',
            'password' => 'agent1234',
        ])->json('data.token');

        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
        $this->forgetAuthState();

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->forgetAuthState();

        // Le token est révoqué en base : la requête suivante est rejetée.
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
    }

    /**
     * Le guard mémorise l'utilisateur résolu entre deux requêtes d'un même
     * test : on l'oublie pour forcer la ré-authentification du token.
     */
    private function forgetAuthState(): void
    {
        auth()->forgetGuards();
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/auth/logout')->assertStatus(401);
    }

    // ─── Onboarding ───────────────────────────────────────────

    public function test_onboarding_completes_profile_and_updates_client(): void
    {
        // Utilisateur type Google : sans mot de passe, onboarding requis
        $user = User::factory()->create(['password' => null, 'needs_onboarding' => true]);
        $user->assignRole('client');
        $client = Client::create([
            'user_id' => $user->id,
            'name' => $user->name,
            'ref' => Client::generateRef(),
            'email' => $user->email,
            'date_inscription' => now(),
        ]);
        $token = $user->createToken('client-token')->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/onboarding-status')
            ->assertOk()
            ->assertJsonPath('data.needsOnboarding', true);

        $response = $this->withToken($token)->postJson('/api/auth/onboarding', [
            'phone' => '+221771234567',
            'employer' => 'Ministère des Finances',
            'profile_type' => 'fonctionnaire',
            'revenus' => '850000',
        ]);

        $response->assertOk()->assertJsonPath('data.user.needsOnboarding', false);

        $user->refresh();
        $this->assertFalse($user->needs_onboarding);
        $this->assertSame('fonctionnaire', $user->profile_type);

        $client->refresh();
        $this->assertSame('+221771234567', $client->phone);
        $this->assertSame('Ministère des Finances', $client->employer);

        $this->withToken($token)->getJson('/api/auth/onboarding-status')
            ->assertOk()
            ->assertJsonPath('data.needsOnboarding', false);
    }

    public function test_onboarding_validates_profile_type(): void
    {
        $user = User::factory()->create(['needs_onboarding' => true]);
        $user->assignRole('client');
        $token = $user->createToken('client-token')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/onboarding', [
            'phone' => '+221771234567',
            'employer' => 'ACME',
            'profile_type' => 'invalide',
            'revenus' => '500000',
        ])->assertStatus(422);
    }

    public function test_onboarding_requires_authentication(): void
    {
        $this->postJson('/api/auth/onboarding', [])->assertStatus(401);
        $this->getJson('/api/auth/onboarding-status')->assertStatus(401);
    }
}
