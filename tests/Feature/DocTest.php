<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Support\VerrouDossier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Documents requis (Phase 3) : dépôt client sur R2 privé + triage staff.
 */
class DocTest extends TestCase
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

    /**
     * Place une pièce requise (créée d'office avec le client) dans un état donné.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function setDocState(Client $client, string $docId, array $attrs): void
    {
        $client->requisDocs()->where('doc_id', $docId)->update($attrs);
    }

    private function agentToken(): string
    {
        /** @var User $agent */
        $agent = User::query()->where('email', 'agent@cpi.sn')->firstOrFail();

        return $agent->createToken('t')->plainTextToken;
    }

    // ─── Client ───────────────────────────────────────────────

    public function test_mine_initializes_the_three_required_docs(): void
    {
        [, , $token] = $this->makeClientUser();

        $response = $this->withToken($token)->getJson('/api/client/mes-documents');

        $response->assertOk();
        $docs = $response->json('data');
        $this->assertCount(3, $docs);
        $this->assertSame(['identite', 'revenus', 'bancaires'], array_column($docs, 'docId'));
        $this->assertSame("Pièce d'identité valide", $docs[0]['label']);
        $this->assertSame(['en-attente', 'en-attente', 'en-attente'], array_column($docs, 'status'));
    }

    public function test_deposit_uploads_to_private_r2_and_updates_doc(): void
    {
        [, $client, $token] = $this->makeClientUser();

        $file = UploadedFile::fake()->create('cni.pdf', 120, 'application/pdf');

        $response = $this->withToken($token)->postJson('/api/client/mes-documents/identite/deposit', [
            'file' => $file,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'depose')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.submittedLabel', 'cni.pdf');

        Storage::disk('r2')->assertExists("docs/{$client->id}/identite_v1.pdf");
        $this->assertDatabaseHas('requis_docs', [
            'client_id' => $client->id,
            'doc_id' => 'identite',
            'status' => 'depose',
            'file_path' => "docs/{$client->id}/identite_v1.pdf",
        ]);

        $this->assertNotNull(Activity::query()->where('event', 'doc-depose')->first());
    }

    public function test_deposited_doc_is_served_through_a_signed_url_only(): void
    {
        [, $client, $token] = $this->makeClientUser();

        $this->withToken($token)->postJson('/api/client/mes-documents/identite/deposit', [
            'file' => UploadedFile::fake()->create('cni.pdf', 10, 'application/pdf'),
        ])->assertOk();

        $docs = $this->withToken($token)->getJson('/api/client/mes-documents')->assertOk()->json('data');

        $parDocId = array_column((array) $docs, null, 'docId');
        $identite = $parDocId['identite'];
        $this->assertStringContainsString("docs/{$client->id}/identite_v1.pdf", $identite['fileUrl']);
        $this->assertStringContainsString('expires=', $identite['fileUrl']);

        // Les pièces non déposées n'ont aucun lien.
        $this->assertNull($parDocId['revenus']['fileUrl']);
    }

    public function test_second_deposit_increments_version(): void
    {
        [, $client, $token] = $this->makeClientUser();

        $this->withToken($token)->postJson('/api/client/mes-documents/identite/deposit', [
            'file' => UploadedFile::fake()->create('v1.pdf', 10, 'application/pdf'),
        ])->assertOk();

        $this->withToken($token)->postJson('/api/client/mes-documents/identite/deposit', [
            'file' => UploadedFile::fake()->create('v2.pdf', 10, 'application/pdf'),
        ])->assertOk()->assertJsonPath('data.version', 2);

        Storage::disk('r2')->assertExists("docs/{$client->id}/identite_v2.pdf");
    }

    public function test_deposit_requires_a_file(): void
    {
        [, , $token] = $this->makeClientUser();

        $this->withToken($token)->postJson('/api/client/mes-documents/identite/deposit', [])
            ->assertStatus(422);
    }

    public function test_deposit_rejects_disallowed_file_types(): void
    {
        [, , $token] = $this->makeClientUser();

        $this->withToken($token)->postJson('/api/client/mes-documents/identite/deposit', [
            'file' => UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload'),
        ])->assertStatus(422);
    }

    public function test_deposit_unknown_doc_returns_404(): void
    {
        [, , $token] = $this->makeClientUser();

        $this->withToken($token)->postJson('/api/client/mes-documents/inconnu/deposit', [
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])->assertStatus(404);
    }

    // ─── Staff ────────────────────────────────────────────────

    public function test_staff_index_lists_client_docs(): void
    {
        [, $client] = $this->makeClientUser();

        $response = $this->withToken($this->agentToken())
            ->getJson('/api/staff/clients/'.$client->id.'/docs');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_accept_sets_validation_fields_and_logs(): void
    {
        [, $client] = $this->makeClientUser();
        $this->setDocState($client, 'identite', ['status' => 'depose', 'version' => 1]);

        $response = $this->withToken($this->agentToken())
            ->postJson('/api/staff/clients/'.$client->id.'/docs/identite/accept');

        $response->assertOk()
            ->assertJsonPath('data.status', 'accepte')
            ->assertJsonPath('data.agentName', 'Agent CPI');
        $this->assertNotNull($response->json('data.dateValidation'));

        $activity = Activity::query()->where('event', 'validated')->first();
        $this->assertNotNull($activity);
        $this->assertSame('doc_accept', $activity->getExtraProperty('action'));
    }

    public function test_refuse_requires_comment(): void
    {
        [, $client] = $this->makeClientUser();
        $this->setDocState($client, 'revenus', ['status' => 'depose']);

        $this->withToken($this->agentToken())
            ->postJson('/api/staff/clients/'.$client->id.'/docs/revenus/refuse', [])
            ->assertStatus(422);
    }

    public function test_refuse_sets_status_and_comment(): void
    {
        [, $client] = $this->makeClientUser();
        $this->setDocState($client, 'revenus', ['status' => 'depose']);

        $this->withToken($this->agentToken())
            ->postJson('/api/staff/clients/'.$client->id.'/docs/revenus/refuse', [
                'comment' => 'Document illisible, merci de rescanner.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'refuse')
            ->assertJsonPath('data.commentaire', 'Document illisible, merci de rescanner.');
    }

    public function test_replace_sets_a_remplacer(): void
    {
        [, $client] = $this->makeClientUser();
        $this->setDocState($client, 'bancaires', ['status' => 'depose']);

        $this->withToken($this->agentToken())
            ->postJson('/api/staff/clients/'.$client->id.'/docs/bancaires/replace', [
                'comment' => 'Relevés trop anciens.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'a-remplacer');
    }

    public function test_verify_sets_verification(): void
    {
        [, $client] = $this->makeClientUser();
        $this->setDocState($client, 'identite', ['status' => 'refuse']);

        $this->withToken($this->agentToken())
            ->postJson('/api/staff/clients/'.$client->id.'/docs/identite/verify')
            ->assertOk()
            ->assertJsonPath('data.status', 'verification');
    }

    // ─── Séparation des rôles ─────────────────────────────────

    public function test_client_cannot_use_staff_doc_routes_even_for_another_client(): void
    {
        $other = Client::create([
            'name' => 'Autre Client',
            'ref' => Client::generateRef(),
            'date_inscription' => now(),
        ]);
        [, , $token] = $this->makeClientUser();

        // Toute route staff est fermée aux clients — y compris pour lire les docs d'un tiers.
        $this->withToken($token)->getJson('/api/staff/clients/'.$other->id.'/docs')
            ->assertStatus(403);
        $this->withToken($token)->postJson('/api/staff/clients/'.$other->id.'/docs/identite/accept')
            ->assertStatus(403);
    }

    public function test_doc_routes_require_authentication(): void
    {
        $this->getJson('/api/client/mes-documents')->assertStatus(401);
        $this->postJson('/api/client/mes-documents/identite/deposit')->assertStatus(401);
    }

    // ─── Verrou d'analyse ─────────────────────────────────────

    public function test_a_client_cannot_deposit_once_the_analysis_started(): void
    {
        // Le verrou ne couvrait que la sauvegarde de la demande. Un client
        // pouvait remplacer sa pièce d'identité ou ses relevés bancaires APRÈS
        // le début de l'instruction, sans que l'agent qui travaillait sur les
        // pièces précédentes en soit informé.
        [, $client, $token] = $this->makeClientUser();
        $client->update(['dossier_etape' => VerrouDossier::ETAPE]);

        $this->withToken($token)
            ->postJson('/api/client/mes-documents/identite/deposit', [
                'file' => UploadedFile::fake()->create('nouvelle-piece.pdf', 120, 'application/pdf'),
            ])
            ->assertStatus(409);

        $this->assertSame(0, $client->requisDocs()->where('doc_id', 'identite')->first()->version);
    }

    public function test_a_client_can_still_deposit_before_the_analysis(): void
    {
        [, $client, $token] = $this->makeClientUser();
        $client->update(['dossier_etape' => VerrouDossier::ETAPE - 1]);

        $this->withToken($token)
            ->postJson('/api/client/mes-documents/identite/deposit', [
                'file' => UploadedFile::fake()->create('piece.pdf', 120, 'application/pdf'),
            ])
            ->assertOk();
    }
}
