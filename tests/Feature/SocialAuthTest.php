<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run the DatabaseSeeder before each test.
     */
    protected bool $seed = true;

    private function configureGoogle(): void
    {
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'http://localhost:5173/auth/google/callback',
        ]);
    }

    private function mockGoogleUser(string $email, string $name = 'Google User'): void
    {
        $googleUser = Mockery::mock(GoogleUser::class);
        $googleUser->shouldReceive('getEmail')->andReturn($email);
        $googleUser->shouldReceive('getName')->andReturn($name);
        $googleUser->shouldReceive('getId')->andReturn('google-id-123');
        $googleUser->shouldReceive('getAvatar')->andReturn('https://lh3.googleusercontent.com/a/photo');

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturn($provider);
        $provider->shouldReceive('user')->andReturn($googleUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    // ─── Garde-fou : Google non configuré ─────────────────────

    public function test_redirect_returns_503_when_google_is_not_configured(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $this->getJson('/api/auth/google/redirect')
            ->assertStatus(503)
            ->assertJsonPath('message', "Google OAuth n'est pas configuré.");
    }

    public function test_callback_returns_503_when_google_is_not_configured(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $this->postJson('/api/auth/google/callback', ['code' => 'abc'])
            ->assertStatus(503);
    }

    // ─── Redirect ─────────────────────────────────────────────

    public function test_redirect_returns_google_oauth_url_when_configured(): void
    {
        $this->configureGoogle();

        $response = $this->getJson('/api/auth/google/redirect');

        $response->assertOk();
        $this->assertStringContainsString('accounts.google.com', (string) $response->json('url'));
    }

    // ─── Callback ─────────────────────────────────────────────

    public function test_callback_creates_new_user_with_client_role_and_needs_onboarding(): void
    {
        $this->configureGoogle();
        $this->mockGoogleUser('nouveau.google@example.com', 'Fatou Diop');

        $response = $this->postJson('/api/auth/google/callback', ['code' => 'valid-code']);

        $response->assertOk()
            ->assertJsonPath('data.role', 'client')
            ->assertJsonPath('data.user.needsOnboarding', true)
            ->assertJsonPath('data.user.email', 'nouveau.google@example.com');
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertNotEmpty($response->json('data.permissions'));

        /** @var User $user */
        $user = User::query()->where('email', 'nouveau.google@example.com')->firstOrFail();
        $this->assertNull($user->password);
        $this->assertSame('google-id-123', $user->google_id);
        $this->assertTrue($user->hasRole('client'));

        $this->assertNotNull(Client::query()->where('user_id', $user->id)->first());
    }

    public function test_callback_reuses_existing_user_without_duplicating(): void
    {
        $this->configureGoogle();

        $existing = User::factory()->create(['email' => 'deja.la@example.com', 'password' => null]);
        $existing->assignRole('client');

        $this->mockGoogleUser('deja.la@example.com');

        $response = $this->postJson('/api/auth/google/callback', ['code' => 'valid-code']);

        $response->assertOk()->assertJsonPath('data.user.id', $existing->id);
        $this->assertSame(1, User::query()->where('email', 'deja.la@example.com')->count());
    }
}
