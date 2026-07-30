<?php

namespace Tests\Feature;

use App\Models\ChantierPublication;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Fil de chantier (Phase 5) : publications du personnel CPI, éventuellement
 * internes. Module strictement staff — le client les lit via /client/mon-chantier.
 */
class ChantierPublicationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function agentToken(): string
    {
        /** @var User $agent */
        $agent = User::query()->where('email', 'agent@cpi.sn')->firstOrFail();

        return $agent->createToken('t')->plainTextToken;
    }

    private function makeClient(string $name = 'Client Publication'): Client
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

    private function seedPublication(Client $client, string $titre = 'Fondations coulées'): ChantierPublication
    {
        /** @var ChantierPublication $publication */
        $publication = $client->ensureChantier()->publications()->create([
            'phase' => 1, 'titre' => $titre, 'description' => 'Béton pris.',
            'heure' => '09:30', 'auteur' => 'Agent CPI', 'type' => 'actualite',
            'visible_client' => true, 'date' => now(),
        ]);

        return $publication;
    }

    private function url(Client $client, string $suffix = ''): string
    {
        return '/api/staff/chantiers/'.$client->id.'/publications'.$suffix;
    }

    // ─── Création ─────────────────────────────────────────────

    public function test_store_creates_a_publication_and_logs(): void
    {
        $client = $this->makeClient();

        $response = $this->withToken($this->agentToken())->postJson($this->url($client), [
            'phase' => 2,
            'titre' => 'Toiture posée',
            'description' => 'Mise hors d\'eau réalisée.',
            'type' => 'etape-validee',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.titre', 'Toiture posée')
            ->assertJsonPath('data.type', 'etape-validee')
            ->assertJsonPath('data.phase', 2)
            ->assertJsonPath('data.auteur', 'Agent CPI')
            ->assertJsonPath('data.visibleClient', true);
        $this->assertNotNull($response->json('data.date'));
        $this->assertNotEmpty($response->json('data.heure'));

        $this->assertNotNull(Activity::query()->where('event', 'chantier-publication-ajoutee')->first());
    }

    public function test_store_accepts_an_internal_publication(): void
    {
        $client = $this->makeClient();

        $this->withToken($this->agentToken())->postJson($this->url($client), [
            'phase' => 0, 'titre' => 'Note interne', 'type' => 'commentaire',
            'visible_client' => false,
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.visibleClient', false)
            ->assertJsonPath('data.description', '');
    }

    public function test_store_rejects_a_missing_title_or_unknown_type(): void
    {
        $client = $this->makeClient();
        $token = $this->agentToken();

        $this->withToken($token)->postJson($this->url($client), ['phase' => 1, 'type' => 'actualite'])
            ->assertStatus(422);
        $this->withToken($token)->postJson($this->url($client), ['phase' => 1, 'titre' => 'X', 'type' => 'telepathie'])
            ->assertStatus(422);
    }

    // ─── Lecture ──────────────────────────────────────────────

    public function test_index_lists_this_dossier_publications_only(): void
    {
        $clientA = $this->makeClient('Dossier A');
        $clientB = $this->makeClient('Dossier B');
        $this->seedPublication($clientA, 'Publication A');
        $this->seedPublication($clientB, 'Publication B');

        $response = $this->withToken($this->agentToken())->getJson($this->url($clientA));

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titre', 'Publication A');
        $this->assertStringNotContainsString('Publication B', $response->getContent() ?: '');
    }

    /** Le staff voit aussi les publications internes. */
    public function test_index_includes_internal_publications_for_staff(): void
    {
        $client = $this->makeClient();
        $client->ensureChantier()->publications()->create([
            'phase' => 0, 'titre' => 'Interne', 'description' => 'Note',
            'heure' => '08:00', 'auteur' => 'Agent CPI', 'type' => 'commentaire',
            'visible_client' => false, 'date' => now(),
        ]);

        $this->withToken($this->agentToken())->getJson($this->url($client))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.visibleClient', false);
    }

    // ─── Modification / suppression ───────────────────────────

    public function test_update_changes_the_publication_and_logs(): void
    {
        $client = $this->makeClient();
        $publication = $this->seedPublication($client);

        $this->withToken($this->agentToken())->putJson($this->url($client, '/'.$publication->id), [
            'titre' => 'Fondations validées',
            'visible_client' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.titre', 'Fondations validées')
            ->assertJsonPath('data.visibleClient', false);

        $this->assertNotNull(Activity::query()->where('event', 'chantier-publication-modifiee')->first());
    }

    public function test_destroy_removes_the_publication_and_logs(): void
    {
        $client = $this->makeClient();
        $publication = $this->seedPublication($client);

        $this->withToken($this->agentToken())->deleteJson($this->url($client, '/'.$publication->id))
            ->assertOk()
            ->assertJsonPath('message', 'Publication supprimée.');

        $this->assertDatabaseMissing('chantier_publications', ['id' => $publication->id]);
        $this->assertNotNull(Activity::query()->where('event', 'chantier-publication-supprimee')->first());
    }

    // ─── Étanchéité entre dossiers ────────────────────────────

    public function test_a_publication_from_another_dossier_is_never_reachable(): void
    {
        $clientA = $this->makeClient('Dossier A');
        $clientB = $this->makeClient('Dossier B');
        $publicationB = $this->seedPublication($clientB, 'Publication B');
        $token = $this->agentToken();

        $this->withToken($token)->getJson($this->url($clientA))->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->putJson($this->url($clientA, '/'.$publicationB->id), ['titre' => 'Détournée'])
            ->assertStatus(404);
        $this->withToken($token)->deleteJson($this->url($clientA, '/'.$publicationB->id))
            ->assertStatus(404);

        $this->assertDatabaseHas('chantier_publications', ['id' => $publicationB->id, 'titre' => 'Publication B']);
    }

    public function test_an_unknown_or_malformed_id_returns_404(): void
    {
        $client = $this->makeClient();
        $token = $this->agentToken();

        $this->withToken($token)->putJson($this->url($client, '/pas-un-uuid'), ['titre' => 'X'])->assertStatus(404);
        $this->withToken($token)->deleteJson($this->url($client, '/'.Str::uuid()))->assertStatus(404);
    }

    // ─── Séparation des rôles ─────────────────────────────────

    public function test_client_token_is_refused_on_every_publication_route(): void
    {
        [, $client, $token] = $this->makeClientUser();
        $publication = $this->seedPublication($client);

        $this->withToken($token)->getJson($this->url($client))->assertStatus(403);
        $this->withToken($token)->postJson($this->url($client), ['phase' => 1, 'titre' => 'X', 'type' => 'actualite'])->assertStatus(403);
        $this->withToken($token)->putJson($this->url($client, '/'.$publication->id), ['titre' => 'X'])->assertStatus(403);
        $this->withToken($token)->deleteJson($this->url($client, '/'.$publication->id))->assertStatus(403);

        $this->assertDatabaseHas('chantier_publications', ['id' => $publication->id, 'titre' => 'Fondations coulées']);
    }

    public function test_client_a_cannot_touch_client_b_publications(): void
    {
        [, , $tokenA] = $this->makeClientUser();
        [, $clientB] = $this->makeClientUser();
        $publicationB = $this->seedPublication($clientB, 'Publication B');

        $this->withToken($tokenA)->getJson($this->url($clientB))->assertStatus(403);
        $this->withToken($tokenA)->postJson($this->url($clientB), ['phase' => 1, 'titre' => 'Pirate', 'type' => 'actualite'])->assertStatus(403);
        $this->withToken($tokenA)->putJson($this->url($clientB, '/'.$publicationB->id), ['titre' => 'Pirate'])->assertStatus(403);
        $this->withToken($tokenA)->deleteJson($this->url($clientB, '/'.$publicationB->id))->assertStatus(403);

        $this->assertDatabaseHas('chantier_publications', ['id' => $publicationB->id, 'titre' => 'Publication B']);
    }

    public function test_publication_routes_require_authentication(): void
    {
        $client = $this->makeClient();
        $publication = $this->seedPublication($client);

        $this->getJson($this->url($client))->assertStatus(401);
        $this->postJson($this->url($client), [])->assertStatus(401);
        $this->putJson($this->url($client, '/'.$publication->id), ['titre' => 'X'])->assertStatus(401);
        $this->deleteJson($this->url($client, '/'.$publication->id))->assertStatus(401);
    }
}
