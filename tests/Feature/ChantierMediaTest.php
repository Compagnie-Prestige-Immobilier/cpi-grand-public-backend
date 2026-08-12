<?php

namespace Tests\Feature;

use App\Models\ChantierMedia;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Photos / vidéos de chantier (Phase 5). Le fichier vit sur le bucket R2 PRIVÉ :
 * l'API n'expose jamais la clé de stockage, uniquement une URL signée.
 */
class ChantierMediaTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function agentToken(): string
    {
        /** @var User $agent */
        $agent = User::query()->where('email', 'agent@cpi.sn')->firstOrFail();

        return $agent->createToken('t')->plainTextToken;
    }

    private function makeClient(string $name = 'Client Média'): Client
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

    /** Média déposé directement en base (sans passer par l'upload). */
    private function seedMedia(Client $client, string $titre = 'Fondations', bool $visible = true): ChantierMedia
    {
        /** @var ChantierMedia $media */
        $media = $client->ensureChantier()->medias()->create([
            'type' => 'photo', 'titre' => $titre, 'description' => null,
            'phase' => 1, 'auteur' => 'Agent CPI', 'visible_client' => $visible,
            'url' => 'chantier/'.$client->id.'/seed.jpg', 'date' => now(),
        ]);

        return $media;
    }

    private function url(Client $client, string $suffix = ''): string
    {
        return '/api/staff/chantiers/'.$client->id.'/medias'.$suffix;
    }

    // ─── Dépôt ────────────────────────────────────────────────

    public function test_store_uploads_to_private_r2_and_returns_a_signed_url_only(): void
    {
        $client = $this->makeClient();

        $response = $this->withToken($this->agentToken())->post($this->url($client), [
            'file' => UploadedFile::fake()->image('toiture.jpg'),
            'type' => 'photo',
            'titre' => 'Toiture posée',
            'phase' => 2,
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201)
            ->assertJsonPath('data.titre', 'Toiture posée')
            ->assertJsonPath('data.type', 'photo')
            ->assertJsonPath('data.phase', 2)
            ->assertJsonPath('data.auteur', 'Agent CPI')
            ->assertJsonPath('data.visibleClient', true);

        $id = $response->json('data.id');
        Storage::disk('r2')->assertExists("chantier/{$client->id}/{$id}.jpg");

        // Seul le lien signé sort ; la clé brute reste en base.
        $fileUrl = $response->json('data.fileUrl');
        $this->assertStringContainsString("chantier/{$client->id}/{$id}.jpg", $fileUrl);
        $this->assertStringContainsString('expires=', $fileUrl);
        $this->assertArrayNotHasKey('url', $response->json('data'));
        $this->assertDatabaseHas('chantier_medias', ['id' => $id, 'url' => "chantier/{$client->id}/{$id}.jpg"]);

        $this->assertNotNull(Activity::query()->where('event', 'chantier-media-ajoute')->first());
    }

    public function test_store_requires_a_file_and_a_known_type(): void
    {
        $client = $this->makeClient();
        $token = $this->agentToken();

        $this->withToken($token)->postJson($this->url($client), [
            'type' => 'photo', 'titre' => 'Sans fichier', 'phase' => 1,
        ])->assertStatus(422);

        $this->withToken($token)->post($this->url($client), [
            'file' => UploadedFile::fake()->image('x.jpg'),
            'type' => 'hologramme', 'titre' => 'Type inconnu', 'phase' => 1,
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    // ─── Lecture ──────────────────────────────────────────────

    public function test_index_lists_the_medias_of_this_dossier_only(): void
    {
        $clientA = $this->makeClient('Dossier A');
        $clientB = $this->makeClient('Dossier B');
        $this->seedMedia($clientA, 'Photo A');
        $this->seedMedia($clientB, 'Photo B');

        $response = $this->withToken($this->agentToken())->getJson($this->url($clientA));

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titre', 'Photo A');
        $this->assertStringNotContainsString('Photo B', $response->getContent() ?: '');
    }

    public function test_index_returns_signed_urls_never_raw_paths(): void
    {
        $client = $this->makeClient();
        $this->seedMedia($client);

        $response = $this->withToken($this->agentToken())->getJson($this->url($client));

        $response->assertOk();
        $media = $response->json('data.0');
        $this->assertArrayNotHasKey('url', $media);
        $this->assertStringStartsWith('https://r2.test/', $media['fileUrl']);
        $this->assertStringContainsString('expires=', $media['fileUrl']);
    }

    // ─── Modification / suppression ───────────────────────────

    public function test_update_changes_metadata_and_logs(): void
    {
        $client = $this->makeClient();
        $media = $this->seedMedia($client);

        $this->withToken($this->agentToken())->putJson($this->url($client, '/'.$media->id), [
            'titre' => 'Fondations coulées',
            'visible_client' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.titre', 'Fondations coulées')
            ->assertJsonPath('data.visibleClient', false);

        $this->assertNotNull(Activity::query()->where('event', 'chantier-media-modifie')->first());
    }

    public function test_destroy_removes_the_row_and_the_file(): void
    {
        $client = $this->makeClient();

        $created = $this->withToken($this->agentToken())->post($this->url($client), [
            'file' => UploadedFile::fake()->image('mur.jpg'),
            'type' => 'photo', 'titre' => 'Mur', 'phase' => 1,
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $id = $created->json('data.id');
        Storage::disk('r2')->assertExists("chantier/{$client->id}/{$id}.jpg");

        $this->withToken($this->agentToken())->deleteJson($this->url($client, '/'.$id))->assertOk();

        $this->assertDatabaseMissing('chantier_medias', ['id' => $id]);
        Storage::disk('r2')->assertMissing("chantier/{$client->id}/{$id}.jpg");
        $this->assertNotNull(Activity::query()->where('event', 'chantier-media-supprime')->first());
    }

    // ─── Étanchéité entre dossiers ────────────────────────────

    /** Un média d'un autre dossier ne se lit ni ne se modifie via ce chemin. */
    public function test_a_media_from_another_dossier_is_never_reachable(): void
    {
        $clientA = $this->makeClient('Dossier A');
        $clientB = $this->makeClient('Dossier B');
        $mediaB = $this->seedMedia($clientB, 'Photo B');
        $token = $this->agentToken();

        $this->withToken($token)->getJson($this->url($clientA))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->putJson($this->url($clientA, '/'.$mediaB->id), ['titre' => 'Détourné'])
            ->assertStatus(404);
        $this->withToken($token)->deleteJson($this->url($clientA, '/'.$mediaB->id))
            ->assertStatus(404);

        $this->assertDatabaseHas('chantier_medias', ['id' => $mediaB->id, 'titre' => 'Photo B']);
    }

    public function test_an_unknown_or_malformed_id_returns_404(): void
    {
        $client = $this->makeClient();
        $token = $this->agentToken();

        $this->withToken($token)->putJson($this->url($client, '/pas-un-uuid'), ['titre' => 'X'])->assertStatus(404);
        $this->withToken($token)->deleteJson($this->url($client, '/'.Str::uuid()))->assertStatus(404);
    }

    // ─── Séparation des rôles ─────────────────────────────────

    public function test_client_token_is_refused_on_every_media_route(): void
    {
        [, $client, $token] = $this->makeClientUser();
        $media = $this->seedMedia($client);

        $this->withToken($token)->getJson($this->url($client))->assertStatus(403);
        $this->withToken($token)->postJson($this->url($client), ['type' => 'photo', 'titre' => 'X', 'phase' => 1])->assertStatus(403);
        $this->withToken($token)->putJson($this->url($client, '/'.$media->id), ['titre' => 'X'])->assertStatus(403);
        $this->withToken($token)->deleteJson($this->url($client, '/'.$media->id))->assertStatus(403);

        $this->assertDatabaseHas('chantier_medias', ['id' => $media->id, 'titre' => 'Fondations']);
    }

    /** Un client ne touche jamais aux médias d'un autre dossier. */
    public function test_client_a_cannot_touch_client_b_medias(): void
    {
        [, , $tokenA] = $this->makeClientUser();
        [, $clientB] = $this->makeClientUser();
        $mediaB = $this->seedMedia($clientB, 'Photo B');

        $this->withToken($tokenA)->getJson($this->url($clientB))->assertStatus(403);
        $this->withToken($tokenA)->putJson($this->url($clientB, '/'.$mediaB->id), ['titre' => 'Pirate'])->assertStatus(403);
        $this->withToken($tokenA)->deleteJson($this->url($clientB, '/'.$mediaB->id))->assertStatus(403);

        $this->assertDatabaseHas('chantier_medias', ['id' => $mediaB->id, 'titre' => 'Photo B']);
    }

    public function test_media_routes_require_authentication(): void
    {
        $client = $this->makeClient();
        $media = $this->seedMedia($client);

        $this->getJson($this->url($client))->assertStatus(401);
        $this->postJson($this->url($client), [])->assertStatus(401);
        $this->putJson($this->url($client, '/'.$media->id), ['titre' => 'X'])->assertStatus(401);
        $this->deleteJson($this->url($client, '/'.$media->id))->assertStatus(401);
    }
}
