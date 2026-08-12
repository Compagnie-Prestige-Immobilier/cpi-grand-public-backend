<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Demande;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endpoints self-service du client (profil + parcours).
 */
class ClientSelfTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

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

    public function test_my_profile_returns_own_client(): void
    {
        [, $client, $token] = $this->makeClientUser();

        $this->withToken($token)->getJson('/api/client/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $client->id)
            ->assertJsonPath('data.ref', $client->ref);
    }

    public function test_my_profile_404_when_no_client_record(): void
    {
        $user = User::factory()->create();
        $user->assignRole('client');
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/client/profile')->assertStatus(404);
    }

    public function test_update_my_profile(): void
    {
        [, , $token] = $this->makeClientUser();

        $this->withToken($token)->putJson('/api/client/profile', [
            'phone' => '+221781112233',
            'adresse' => 'Ouakam, Dakar',
        ])->assertOk()
            ->assertJsonPath('data.phone', '+221781112233')
            ->assertJsonPath('data.adresse', 'Ouakam, Dakar');
    }

    public function test_my_dossier_journey_step_0_for_new_client(): void
    {
        [, , $token] = $this->makeClientUser();

        $this->withToken($token)->getJson('/api/client/mon-dossier-journey')
            ->assertOk()
            ->assertJsonPath('data.step', 0)
            ->assertJsonPath('data.submitted', false);
    }

    public function test_my_dossier_journey_step_1_after_submission(): void
    {
        [, $client, $token] = $this->makeClientUser();
        Demande::create(['client_id' => $client->id, 'submitted' => true, 'submitted_at' => now()]);

        $this->withToken($token)->getJson('/api/client/mon-dossier-journey')
            ->assertOk()
            ->assertJsonPath('data.step', 1)
            ->assertJsonPath('data.submitted', true);
    }

    public function test_profile_requires_authentication(): void
    {
        $this->getJson('/api/client/profile')->assertStatus(401);
        $this->getJson('/api/client/mon-dossier-journey')->assertStatus(401);
    }
}
