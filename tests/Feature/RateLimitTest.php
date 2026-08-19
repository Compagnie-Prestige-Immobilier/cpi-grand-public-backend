<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * L'API ne posait AUCUNE limite de débit : `throttleApi()` n'était jamais
 * appelé et aucune route ne portait `throttle:`. Bourrage d'identifiants,
 * création de comptes en masse et inondation de la boîte support étaient donc
 * libres. Ces tests figent les limites pour qu'elles ne puissent pas
 * disparaître silencieusement.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_login_is_throttled_after_the_configured_number_of_attempts(): void
    {
        $limite = (int) config('rate_limits.login');

        for ($i = 0; $i < $limite; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'inconnu@example.com',
                'password' => 'mauvais-mot-de-passe',
            ])->assertStatus(401);
        }

        $this->postJson('/api/auth/login', [
            'email' => 'inconnu@example.com',
            'password' => 'mauvais-mot-de-passe',
        ])->assertStatus(429);
    }

    public function test_the_login_limit_is_scoped_to_the_email_not_only_the_ip(): void
    {
        // Sinon un client derrière une IP partagée (cybercafé, NAT d'entreprise)
        // serait bloqué par les erreurs de son voisin.
        $limite = (int) config('rate_limits.login');

        for ($i = 0; $i < $limite; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'cible@example.com', 'password' => 'x'])
                ->assertStatus(401);
        }

        $this->postJson('/api/auth/login', ['email' => 'cible@example.com', 'password' => 'x'])
            ->assertStatus(429);

        // Une autre adresse depuis la même IP passe toujours.
        $this->postJson('/api/auth/login', ['email' => 'voisin@example.com', 'password' => 'x'])
            ->assertStatus(401);
    }

    public function test_register_is_throttled(): void
    {
        $limite = (int) config('rate_limits.register');

        for ($i = 0; $i < $limite; $i++) {
            $this->postJson('/api/auth/register', [
                'name' => "Compte {$i}",
                'email' => "compte{$i}@example.com",
                'password' => 'secret1234',
            ])->assertStatus(201);
        }

        $this->postJson('/api/auth/register', [
            'name' => 'De trop',
            'email' => 'detrop@example.com',
            'password' => 'secret1234',
        ])->assertStatus(429);
    }

    public function test_support_is_throttled(): void
    {
        // Cet endpoint envoie de vrais courriels depuis le domaine CPI : sans
        // limite, un compte compromis peut le faire blacklister.
        Mail::fake();

        $user = User::factory()->create();
        $user->assignRole('client');
        Client::create(['user_id' => $user->id, 'name' => $user->name, 'ref' => Client::generateRef()]);
        $token = $user->createToken('t')->plainTextToken;

        $limite = (int) config('rate_limits.support');

        for ($i = 0; $i < $limite; $i++) {
            $this->withToken($token)
                ->postJson('/api/client/support', ['sujet' => 'Question', 'message' => "Message {$i}"])
                ->assertOk();
        }

        $this->withToken($token)
            ->postJson('/api/client/support', ['sujet' => 'Question', 'message' => 'De trop'])
            ->assertStatus(429);
    }

    public function test_every_api_route_carries_the_global_limiter(): void
    {
        // `throttleApi()` couvre le groupe entier : la présence des en-têtes
        // suffit à prouver que le limiteur est bien monté. La requête doit
        // aboutir — sur une réponse d'exception (401), les en-têtes du limiteur
        // ne sont pas posés, l'exception remontant au-dessus du middleware.
        $user = User::factory()->create();
        $user->assignRole('client');

        $this->withToken($user->createToken('t')->plainTextToken)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', (string) config('rate_limits.api'));
    }
}
