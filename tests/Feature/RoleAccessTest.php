<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Séparation client / staff : un token client sur /staff/* → 403,
 * un token staff sur /client/* → 403, non authentifié → 401.
 */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run the DatabaseSeeder before each test.
     */
    protected $seed = true;

    private function clientToken(): string
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return $user->createToken('t')->plainTextToken;
    }

    private function agentToken(): string
    {
        /** @var User $agent */
        $agent = User::query()->where('email', 'agent@cpi.sn')->firstOrFail();

        return $agent->createToken('t')->plainTextToken;
    }

    public function test_client_token_on_staff_routes_returns_403(): void
    {
        $token = $this->clientToken();

        $this->withToken($token)->getJson('/api/staff/clients')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Accès personnel CPI uniquement.');

        $this->withToken($token)->getJson('/api/staff/banks')->assertStatus(403);
        $this->withToken($token)->getJson('/api/staff/staff/list')->assertStatus(403);
        $this->withToken($token)->postJson('/api/staff/staff/create', [])->assertStatus(403);
    }

    public function test_staff_token_on_client_routes_returns_403(): void
    {
        $token = $this->agentToken();

        $this->withToken($token)->getJson('/api/client/profile')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Accès client uniquement.');

        $this->withToken($token)->getJson('/api/client/mes-documents')->assertStatus(403);
        $this->withToken($token)->getJson('/api/client/notifications')->assertStatus(403);
    }

    public function test_unauthenticated_requests_return_401(): void
    {
        $this->getJson('/api/staff/clients')->assertStatus(401);
        $this->getJson('/api/client/profile')->assertStatus(401);
    }

    public function test_unauthenticated_request_without_json_header_returns_401_not_500(): void
    {
        // Sans en-tête Accept: application/json, le squelette Laravel résout
        // `route('login')` — inexistante ici — et renvoie 500 au lieu de 401.
        $this->get('/api/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_staff_role_reaches_the_staff_stub_endpoints(): void
    {
        // Les phases 5-7 ne sont pas implémentées : le middleware laisse passer
        // le bon rôle et le squelette répond 501.
        // (/staff/banks est passé en Phase 4 — cf. test_client_token_on_staff_routes_returns_403
        // qui continue d'y vérifier le 403 côté client.)
        $this->withToken($this->agentToken())->getJson('/api/staff/historique')
            ->assertStatus(501)
            ->assertJsonPath('message', 'Non implémenté');
    }

    public function test_client_role_reaches_the_client_stub_endpoints(): void
    {
        $this->withToken($this->clientToken())->getJson('/api/client/notifications')
            ->assertStatus(501)
            ->assertJsonPath('message', 'Non implémenté');
    }
}
