<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    /** @return array{0: User, 1: string} */
    private function utilisateur(string $role): array
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        if ($role === 'client') {
            Client::create([
                'user_id' => $user->id,
                'name' => $user->name,
                'ref' => Client::generateRef(),
                'email' => $user->email,
                'date_inscription' => now(),
            ]);
        }

        return [$user, $user->createToken('t')->plainTextToken];
    }

    /**
     * Requête authentifiée par jeton.
     *
     * `forgetGuards()` est indispensable : la garde Sanctum met en cache
     * l'utilisateur résolu, si bien qu'une seconde requête dans le même test
     * repartait avec le compte de la PREMIÈRE — la prise en main semblait alors
     * ne pas fonctionner alors que seul le harnais de test était en cause.
     */
    private function avecJeton(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_admin_can_impersonate_a_client(): void
    {
        [, $tokenAdmin] = $this->utilisateur('super-admin');
        [$client] = $this->utilisateur('client');

        $response = $this->avecJeton($tokenAdmin)->postJson("/api/staff/impersonate/{$client->id}");

        $response->assertOk()
            ->assertJsonPath('data.user.id', $client->id)
            ->assertJsonPath('data.user.role', 'client');
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_the_returned_token_actually_acts_as_the_client(): void
    {
        [, $tokenAdmin] = $this->utilisateur('super-admin');
        [$client] = $this->utilisateur('client');

        $jeton = $this->avecJeton($tokenAdmin)
            ->postJson("/api/staff/impersonate/{$client->id}")
            ->json('data.token');

        // Le jeton doit ouvrir l'espace client — et lui seul.
        $this->avecJeton($jeton)->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('data.user.id', $client->id);
        $this->avecJeton($jeton)->getJson('/api/client/profile')->assertOk();
        $this->avecJeton($jeton)->getJson('/api/staff/clients')->assertForbidden();
    }

    /**
     * Le garde-fou central : sans lui, un agent prendrait la main sur un compte
     * administrateur et hériterait de ses permissions.
     */
    public function test_staff_accounts_can_never_be_impersonated(): void
    {
        [, $tokenAdmin] = $this->utilisateur('super-admin');
        [$agent] = $this->utilisateur('agent-cpi');
        [$autreAdmin] = $this->utilisateur('super-admin');

        $this->avecJeton($tokenAdmin)->postJson("/api/staff/impersonate/{$agent->id}")->assertForbidden();
        $this->avecJeton($tokenAdmin)->postJson("/api/staff/impersonate/{$autreAdmin->id}")->assertForbidden();
    }

    public function test_an_agent_may_also_impersonate_a_client(): void
    {
        // Ouvert aux agents côté serveur ; c'est l'interface qui réserve le
        // bouton aux administrateurs pour l'instant.
        [, $tokenAgent] = $this->utilisateur('agent-cpi');
        [$client] = $this->utilisateur('client');

        $this->avecJeton($tokenAgent)->postJson("/api/staff/impersonate/{$client->id}")->assertOk();
    }

    public function test_a_client_cannot_impersonate_anyone(): void
    {
        [, $tokenClient] = $this->utilisateur('client');
        [$cible] = $this->utilisateur('client');

        $this->avecJeton($tokenClient)->postJson("/api/staff/impersonate/{$cible->id}")->assertForbidden();
    }

    public function test_impersonation_requires_authentication(): void
    {
        [$client] = $this->utilisateur('client');

        $this->postJson("/api/staff/impersonate/{$client->id}")->assertUnauthorized();
    }

    public function test_impersonation_cannot_be_nested(): void
    {
        [, $tokenAdmin] = $this->utilisateur('super-admin');
        [$client] = $this->utilisateur('client');
        [$autreClient] = $this->utilisateur('client');

        $jeton = $this->avecJeton($tokenAdmin)
            ->postJson("/api/staff/impersonate/{$client->id}")->json('data.token');

        // Le jeton emprunté est celui d'un client : il n'atteint même pas /staff.
        $this->avecJeton($jeton)->postJson("/api/staff/impersonate/{$autreClient->id}")->assertForbidden();
    }

    public function test_leave_revokes_the_impersonation_token(): void
    {
        [, $tokenAdmin] = $this->utilisateur('super-admin');
        [$client] = $this->utilisateur('client');

        $jeton = $this->avecJeton($tokenAdmin)
            ->postJson("/api/staff/impersonate/{$client->id}")->json('data.token');

        $this->avecJeton($jeton)->postJson('/api/impersonate/leave')->assertOk();

        // Le jeton doit être mort : sinon il resterait utilisable pour toujours.
        $this->avecJeton($jeton)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_leave_is_rejected_for_an_ordinary_token(): void
    {
        [, $tokenClient] = $this->utilisateur('client');

        $this->avecJeton($tokenClient)->postJson('/api/impersonate/leave')->assertStatus(400);
    }

    public function test_the_admin_token_survives_the_impersonation(): void
    {
        [, $tokenAdmin] = $this->utilisateur('super-admin');
        [$client] = $this->utilisateur('client');

        $jeton = $this->avecJeton($tokenAdmin)
            ->postJson("/api/staff/impersonate/{$client->id}")->json('data.token');
        $this->avecJeton($jeton)->postJson('/api/impersonate/leave')->assertOk();

        // C'est ce jeton que le navigateur restitue : il doit rester valable.
        $this->avecJeton($tokenAdmin)->getJson('/api/staff/clients')->assertOk();
    }

    public function test_both_ends_of_the_impersonation_are_journaled(): void
    {
        [$admin, $tokenAdmin] = $this->utilisateur('super-admin');
        [$client] = $this->utilisateur('client');

        $jeton = $this->avecJeton($tokenAdmin)
            ->postJson("/api/staff/impersonate/{$client->id}")->json('data.token');
        $this->avecJeton($jeton)->postJson('/api/impersonate/leave');

        $debut = Activity::where('event', 'impersonation-debut')->latest('id')->first();
        $fin = Activity::where('event', 'impersonation-fin')->latest('id')->first();

        $this->assertNotNull($debut, 'Le début de prise en main doit être journalisé.');
        $this->assertNotNull($fin, 'La fin de prise en main doit être journalisée.');
        // Le journal doit désigner l'OPÉRATEUR, pas le compte consulté : sans
        // cela, une action faite en prise en main serait imputée au client.
        $this->assertSame($admin->id, $debut->causer_id);
        $this->assertSame($admin->id, $fin->causer_id);
    }
}
