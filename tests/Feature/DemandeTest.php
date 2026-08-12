<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DemandeController;
use App\Models\Client;
use App\Models\Demande;
use App\Models\User;
use App\Support\VerrouDossier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
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

    public function test_recapitulatif_returns_a_pdf(): void
    {
        [, $client, $token] = $this->makeClientUser();
        Demande::create([
            'client_id' => $client->id,
            'type_projet' => 'financement',
            'montant' => 25000000,
            'commune' => 'Rufisque',
            'submitted' => true,
            'submitted_at' => now(),
        ]);

        $response = $this->withToken($token)->get('/api/client/ma-demande/recapitulatif');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString(
            "recapitulatif-{$client->ref}.pdf",
            $response->headers->get('content-disposition') ?? ''
        );
        // %PDF- : le corps est un PDF réel, pas une page d'erreur rendue en 200.
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_recapitulatif_works_without_a_demande(): void
    {
        // Un client fraîchement inscrit n'a pas encore de demande : le PDF doit
        // sortir malgré tout plutôt que de tomber sur un accès à null.
        [, , $token] = $this->makeClientUser();

        $this->withToken($token)->get('/api/client/ma-demande/recapitulatif')->assertOk();
    }

    public function test_recapitulatif_requires_authentication(): void
    {
        $this->getJson('/api/client/ma-demande/recapitulatif')->assertUnauthorized();
    }

    public function test_recapitulatif_is_refused_to_staff(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole('agent-cpi');

        $this->withToken($agent->createToken('t')->plainTextToken)
            ->getJson('/api/client/ma-demande/recapitulatif')
            ->assertForbidden();
    }

    public function test_the_demande_can_still_be_edited_while_only_received(): void
    {
        [, $client, $token] = $this->makeClientUser();
        // Étapes 0 à 2 : CPI n'a pas commencé l'instruction, le client corrige
        // lui-même une faute de frappe sans passer par son conseiller.
        $client->update(['dossier_etape' => 2]);

        $this->withToken($token)
            ->postJson('/api/client/ma-demande', ['montant' => 30000000])
            ->assertOk();
    }

    public function test_the_demande_is_locked_once_cpi_starts_the_analysis(): void
    {
        [, $client, $token] = $this->makeClientUser();
        Demande::create(['client_id' => $client->id, 'montant' => 25000000]);
        $client->update(['dossier_etape' => DemandeController::ETAPE_VERROUILLAGE]);

        $this->withToken($token)
            ->postJson('/api/client/ma-demande', ['montant' => 40000000])
            ->assertStatus(409);

        // Le montant d'origine doit être intact : c'est celui sur lequel
        // l'agent travaille.
        $this->assertSame(25000000.0, (float) $client->demande->refresh()->montant);
    }

    public function test_the_lock_holds_at_every_later_stage(): void
    {
        [, $client, $token] = $this->makeClientUser();

        foreach ([3, 4, 5] as $etape) {
            $client->update(['dossier_etape' => $etape]);
            $this->withToken($token)
                ->postJson('/api/client/ma-demande', ['montant' => 40000000])
                ->assertStatus(409);
        }
    }

    public function test_staff_can_correct_a_locked_demande(): void
    {
        // Le cas qui motive cet endpoint : la coquille n'est repérée qu'une fois
        // l'instruction commencée, quand le client n'a plus la main.
        [, $client] = $this->makeClientUser();
        Demande::create(['client_id' => $client->id, 'montant' => 25000000, 'commune' => 'Rufique']);
        $client->update(['dossier_etape' => DemandeController::ETAPE_VERROUILLAGE]);

        $agent = User::factory()->create();
        $agent->assignRole('agent-cpi');

        $this->withToken($agent->createToken('t')->plainTextToken)
            ->putJson("/api/staff/clients/{$client->id}/demande", ['commune' => 'Rufisque'])
            ->assertOk()
            ->assertJsonPath('data.commune', 'Rufisque');
    }

    public function test_the_correction_records_the_previous_value(): void
    {
        [, $client] = $this->makeClientUser();
        Demande::create(['client_id' => $client->id, 'commune' => 'Rufique']);
        $agent = User::factory()->create();
        $agent->assignRole('agent-cpi');

        $this->withToken($agent->createToken('t')->plainTextToken)
            ->putJson("/api/staff/clients/{$client->id}/demande", ['commune' => 'Rufisque']);

        $entree = Activity::where('event', 'demande-corrigee')->latest('id')->first();
        $this->assertNotNull($entree);
        // Sans l'ancienne valeur, le journal dirait qu'il y a eu correction sans
        // dire laquelle — inutilisable pour un litige.
        $this->assertSame('Rufique', $entree->properties['avant']['commune']);
        $this->assertSame('Rufisque', $entree->properties['apres']['commune']);
    }

    public function test_the_client_is_told_their_demande_was_corrected(): void
    {
        [$user, $client] = $this->makeClientUser();
        Demande::create(['client_id' => $client->id, 'commune' => 'Rufique']);
        $agent = User::factory()->create();
        $agent->assignRole('agent-cpi');

        $this->withToken($agent->createToken('t')->plainTextToken)
            ->putJson("/api/staff/clients/{$client->id}/demande", ['commune' => 'Rufisque']);

        $this->assertDatabaseHas('app_notifications', [   // table renommée pour ne pas heurter celle de Laravel
            'user_id' => $user->id,
            'titre' => 'Demande corrigée',
        ]);
    }

    public function test_a_staff_member_without_edit_client_cannot_correct_a_demande(): void
    {
        // Le middleware `staff` garantit le rôle, pas la permission. Cet
        // endpoint était le seul de l'API à écrire sans passer par une policy :
        // un agent privé de `edit-client` modifiait quand même la demande d'un
        // dossier verrouillé.
        [, $client] = $this->makeClientUser();
        Demande::create(['client_id' => $client->id, 'commune' => 'Rufique']);

        $agent = User::factory()->create();
        $agent->assignRole('agent-cpi');
        // La permission vient du rôle : la retirer du seul utilisateur ne
        // changerait rien, `hasPermissionTo` la retrouverait via `agent-cpi`.
        Role::findByName('agent-cpi')->revokePermissionTo('edit-client');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->withToken($agent->createToken('t')->plainTextToken)
            ->putJson("/api/staff/clients/{$client->id}/demande", ['commune' => 'Rufisque'])
            ->assertForbidden();

        $this->assertSame('Rufique', $client->demande->refresh()->commune);
    }

    public function test_a_client_cannot_correct_another_dossier(): void
    {
        [, $client, $token] = $this->makeClientUser();
        Demande::create(['client_id' => $client->id]);

        $this->withToken($token)
            ->putJson("/api/staff/clients/{$client->id}/demande", ['commune' => 'Dakar'])
            ->assertForbidden();
    }

    public function test_a_client_cannot_submit_once_the_analysis_started(): void
    {
        // Soumettre après le début de l'analyse remettrait le dossier dans un
        // état que l'agent croit figé.
        [, $client, $token] = $this->makeClientUser();
        Demande::create(['client_id' => $client->id, 'montant' => 25000000]);
        $client->update(['dossier_etape' => VerrouDossier::ETAPE]);

        $this->withToken($token)
            ->postJson('/api/client/ma-demande/submit')
            ->assertStatus(409);

        $this->assertFalse($client->demande->refresh()->submitted);
    }
}
