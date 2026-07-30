<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Demande;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class DemandeTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

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

    public function test_mine_returns_null_when_no_demande(): void
    {
        [, , $token] = $this->makeClientUser();

        $response = $this->withToken($token)->getJson('/api/client/ma-demande');

        $response->assertOk();
        $this->assertNull($response->json('data'));
    }

    public function test_save_mine_creates_demande(): void
    {
        [, $client, $token] = $this->makeClientUser();

        $response = $this->withToken($token)->postJson('/api/client/ma-demande', [
            'type_projet' => 'financement',
            'nature_projet' => 'acquisition',
            'montant' => 25000000,
            'duree' => '20',
            'apport' => 3000000,
            'region' => 'Dakar',
            'commune' => 'Rufisque',
            'adresse_projet' => 'Cité Douanes',
            'description' => 'Achat appartement F4',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.clientId', $client->id)
            ->assertJsonPath('data.commune', 'Rufisque')
            ->assertJsonPath('data.submitted', false);
        $this->assertSame(25000000.0, (float) $response->json('data.montant'));
        $this->assertDatabaseCount('demandes', 1);
    }

    public function test_save_mine_updates_existing_demande(): void
    {
        [, $client, $token] = $this->makeClientUser();
        Demande::create(['client_id' => $client->id, 'commune' => 'Pikine']);

        $this->withToken($token)->postJson('/api/client/ma-demande', ['commune' => 'Thiès'])
            ->assertOk()
            ->assertJsonPath('data.commune', 'Thiès');

        $this->assertDatabaseCount('demandes', 1);
    }

    public function test_save_mine_validates_montant(): void
    {
        [, , $token] = $this->makeClientUser();

        $this->withToken($token)->postJson('/api/client/ma-demande', ['montant' => 'beaucoup'])
            ->assertStatus(422);
    }

    public function test_submit_mine_without_demande_404(): void
    {
        [, , $token] = $this->makeClientUser();

        $this->withToken($token)->postJson('/api/client/ma-demande/submit')->assertStatus(404);
    }

    public function test_submit_mine_marks_submitted_and_logs(): void
    {
        [, $client, $token] = $this->makeClientUser();
        Demande::create(['client_id' => $client->id, 'montant' => 10000000]);

        $response = $this->withToken($token)->postJson('/api/client/ma-demande/submit');

        $response->assertOk()->assertJsonPath('data.submitted', true);
        $this->assertNotNull($response->json('data.submittedAt'));

        $activity = Activity::query()->where('event', 'demande-soumise')->first();
        $this->assertNotNull($activity);
        $this->assertSame($client->id, $activity->subject_id);
    }

    public function test_demande_requires_authentication(): void
    {
        $this->getJson('/api/client/ma-demande')->assertStatus(401);
        $this->postJson('/api/client/ma-demande', [])->assertStatus(401);
        $this->postJson('/api/client/ma-demande/submit')->assertStatus(401);
    }
}
