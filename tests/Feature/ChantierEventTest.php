<?php

namespace Tests\Feature;

use App\Models\ChantierEvent;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Calendrier de chantier (Phase 5) : visites, inspections, réception. Module
 * staff — le client voit les événements « visible client » via /client/mon-chantier.
 */
class ChantierEventTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function agentToken(): string
    {
        /** @var User $agent */
        $agent = User::query()->where('email', 'agent@cpi.sn')->firstOrFail();

        return $agent->createToken('t')->plainTextToken;
    }

    private function makeClient(string $name = 'Client Événement'): Client
    {
        return Client::create([
            'name' => $name,
            'ref' => Client::generateRef(),
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

    private function seedEvent(Client $client, string $titre = 'Visite de chantier'): ChantierEvent
    {
        /** @var ChantierEvent $event */
        $event = $client->ensureChantier()->events()->create([
            'titre' => $titre, 'type' => 'visite', 'date' => '2026-09-10',
            'heure' => '10:00', 'description' => 'Point d\'avancement.',
            'statut' => 'prevu', 'visible_client' => true,
        ]);

        return $event;
    }

    private function url(Client $client, string $suffix = ''): string
    {
        return '/api/staff/chantiers/'.$client->id.'/events'.$suffix;
    }

    // ─── Création ─────────────────────────────────────────────

    public function test_store_schedules_an_event_and_logs(): void
    {
        $client = $this->makeClient();

        $this->withToken($this->agentToken())->postJson($this->url($client), [
            'titre' => 'Inspection structure',
            'type' => 'inspection',
            'date' => '2026-10-02',
            'heure' => '09:00',
            'description' => 'Contrôle des poteaux.',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.titre', 'Inspection structure')
            ->assertJsonPath('data.type', 'inspection')
            ->assertJsonPath('data.statut', 'prevu')
            ->assertJsonPath('data.visibleClient', true);

        $this->assertNotNull(Activity::query()->where('event', 'chantier-event-planifie')->first());
    }

    public function test_store_rejects_a_missing_date_or_unknown_type(): void
    {
        $client = $this->makeClient();
        $token = $this->agentToken();

        $this->withToken($token)->postJson($this->url($client), ['titre' => 'X', 'type' => 'visite'])
            ->assertStatus(422);
        $this->withToken($token)->postJson($this->url($client), ['titre' => 'X', 'type' => 'seance-photo', 'date' => '2026-10-02'])
            ->assertStatus(422);
        $this->withToken($token)->postJson($this->url($client), ['titre' => 'X', 'type' => 'visite', 'date' => '2026-10-02', 'statut' => 'peut-etre'])
            ->assertStatus(422);
    }

    // ─── Lecture ──────────────────────────────────────────────

    public function test_index_lists_this_dossier_events_only(): void
    {
        $clientA = $this->makeClient('Dossier A');
        $clientB = $this->makeClient('Dossier B');
        $this->seedEvent($clientA, 'Événement A');
        $this->seedEvent($clientB, 'Événement B');

        $response = $this->withToken($this->agentToken())->getJson($this->url($clientA));

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titre', 'Événement A');
        $this->assertStringNotContainsString('Événement B', $response->getContent() ?: '');
    }

    // ─── Modification / suppression ───────────────────────────

    public function test_update_changes_the_event_and_logs(): void
    {
        $client = $this->makeClient();
        $event = $this->seedEvent($client);

        $this->withToken($this->agentToken())->putJson($this->url($client, '/'.$event->id), [
            'statut' => 'realise',
            'heure' => '14:30',
        ])
            ->assertOk()
            ->assertJsonPath('data.statut', 'realise')
            ->assertJsonPath('data.heure', '14:30');

        $this->assertNotNull(Activity::query()->where('event', 'chantier-event-modifie')->first());
    }

    public function test_destroy_removes_the_event_and_logs(): void
    {
        $client = $this->makeClient();
        $event = $this->seedEvent($client);

        $this->withToken($this->agentToken())->deleteJson($this->url($client, '/'.$event->id))
            ->assertOk()
            ->assertJsonPath('message', 'Événement supprimé.');

        $this->assertDatabaseMissing('chantier_events', ['id' => $event->id]);
        $this->assertNotNull(Activity::query()->where('event', 'chantier-event-supprime')->first());
    }

    // ─── Étanchéité entre dossiers ────────────────────────────

    public function test_an_event_from_another_dossier_is_never_reachable(): void
    {
        $clientA = $this->makeClient('Dossier A');
        $clientB = $this->makeClient('Dossier B');
        $eventB = $this->seedEvent($clientB, 'Événement B');
        $token = $this->agentToken();

        $this->withToken($token)->getJson($this->url($clientA))->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->putJson($this->url($clientA, '/'.$eventB->id), ['titre' => 'Détourné'])
            ->assertStatus(404);
        $this->withToken($token)->deleteJson($this->url($clientA, '/'.$eventB->id))
            ->assertStatus(404);

        $this->assertDatabaseHas('chantier_events', ['id' => $eventB->id, 'titre' => 'Événement B']);
    }

    public function test_an_unknown_or_malformed_id_returns_404(): void
    {
        $client = $this->makeClient();
        $token = $this->agentToken();

        $this->withToken($token)->putJson($this->url($client, '/pas-un-uuid'), ['titre' => 'X'])->assertStatus(404);
        $this->withToken($token)->deleteJson($this->url($client, '/'.Str::uuid()))->assertStatus(404);
    }

    // ─── Séparation des rôles ─────────────────────────────────

    public function test_client_token_is_refused_on_every_event_route(): void
    {
        [, $client, $token] = $this->makeClientUser();
        $event = $this->seedEvent($client);

        $this->withToken($token)->getJson($this->url($client))->assertStatus(403);
        $this->withToken($token)->postJson($this->url($client), ['titre' => 'X', 'type' => 'visite', 'date' => '2026-10-02'])->assertStatus(403);
        $this->withToken($token)->putJson($this->url($client, '/'.$event->id), ['titre' => 'X'])->assertStatus(403);
        $this->withToken($token)->deleteJson($this->url($client, '/'.$event->id))->assertStatus(403);

        $this->assertDatabaseHas('chantier_events', ['id' => $event->id, 'titre' => 'Visite de chantier']);
    }

    public function test_client_a_cannot_touch_client_b_events(): void
    {
        [, , $tokenA] = $this->makeClientUser();
        [, $clientB] = $this->makeClientUser();
        $eventB = $this->seedEvent($clientB, 'Événement B');

        $this->withToken($tokenA)->getJson($this->url($clientB))->assertStatus(403);
        $this->withToken($tokenA)->postJson($this->url($clientB), ['titre' => 'Pirate', 'type' => 'visite', 'date' => '2026-10-02'])->assertStatus(403);
        $this->withToken($tokenA)->putJson($this->url($clientB, '/'.$eventB->id), ['titre' => 'Pirate'])->assertStatus(403);
        $this->withToken($tokenA)->deleteJson($this->url($clientB, '/'.$eventB->id))->assertStatus(403);

        $this->assertDatabaseHas('chantier_events', ['id' => $eventB->id, 'titre' => 'Événement B']);
    }

    public function test_event_routes_require_authentication(): void
    {
        $client = $this->makeClient();
        $event = $this->seedEvent($client);

        $this->getJson($this->url($client))->assertStatus(401);
        $this->postJson($this->url($client), [])->assertStatus(401);
        $this->putJson($this->url($client, '/'.$event->id), ['titre' => 'X'])->assertStatus(401);
        $this->deleteJson($this->url($client, '/'.$event->id))->assertStatus(401);
    }
}
