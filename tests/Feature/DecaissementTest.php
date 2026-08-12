<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Decaissement;
use App\Models\Demande;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Décaissements bancaires (Phase 4) : acquisition foncière puis construction
 * par tranches. Module strictement interne — aucune route client.
 */
class DecaissementTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function agentToken(): string
    {
        /** @var User $agent */
        $agent = User::query()->where('email', 'agent@cpi.sn')->firstOrFail();

        return $agent->createToken('t')->plainTextToken;
    }

    private function adminToken(): string
    {
        /** @var User $admin */
        $admin = User::query()->where('email', 'admin@cpi.sn')->firstOrFail();

        return $admin->createToken('t')->plainTextToken;
    }

    private function makeClient(): Client
    {
        return Client::create([
            'name' => 'Client Décaissement',
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

    // ─── Lecture ──────────────────────────────────────────────

    public function test_client_creation_provisions_the_decaissement_row(): void
    {
        $client = $this->makeClient();

        $this->assertDatabaseHas('decaissements', ['client_id' => $client->id]);
    }

    public function test_show_returns_the_default_state(): void
    {
        $client = $this->makeClient();

        $response = $this->withToken($this->agentToken())->getJson('/api/staff/decaissements/'.$client->id);

        $response->assertOk()
            ->assertJsonPath('data.clientId', $client->id)
            ->assertJsonPath('data.terrainMontant', 0)
            ->assertJsonPath('data.terrainDecaisse', false)
            ->assertJsonPath('data.foncier', [true, false, false, false, false])
            ->assertJsonPath('data.constructionActive', false);

        $this->assertCount(4, $response->json('data.tranches'));
    }

    /** Un dossier antérieur au module (aucune ligne) doit fonctionner sans erreur. */
    public function test_show_creates_the_row_on_demand_for_a_legacy_client(): void
    {
        $client = $this->makeClient();
        // `forceDelete` : voir ChantierTest — on simule un dossier antérieur au
        // module, pas un décaissement mis à la corbeille.
        Decaissement::query()->where('client_id', $client->id)->forceDelete();
        $this->assertDatabaseMissing('decaissements', ['client_id' => $client->id]);

        $this->withToken($this->agentToken())->getJson('/api/staff/decaissements/'.$client->id)
            ->assertOk()
            ->assertJsonPath('data.clientId', $client->id)
            ->assertJsonPath('data.foncier', [true, false, false, false, false]);

        $this->assertDatabaseHas('decaissements', ['client_id' => $client->id]);
    }

    // ─── Mise à jour ──────────────────────────────────────────

    public function test_update_sets_amounts_and_logs(): void
    {
        $client = $this->makeClient();

        $this->withToken($this->agentToken())->putJson('/api/staff/decaissements/'.$client->id, [
            'terrain_montant' => 15000000,
            'construction_active' => true,
            'construction_montant' => 42000000,
        ])
            ->assertOk()
            ->assertJsonPath('data.terrainMontant', 15000000)
            ->assertJsonPath('data.constructionActive', true)
            ->assertJsonPath('data.constructionMontant', 42000000);

        $this->assertNotNull(Activity::query()->where('event', 'decaissement-modifie')->first());
    }

    public function test_update_stores_tranche_comments(): void
    {
        $client = $this->makeClient();

        $response = $this->withToken($this->agentToken())->putJson('/api/staff/decaissements/'.$client->id, [
            'tranches' => [
                ['validated' => false, 'comment' => 'Après inspection du chantier.'],
                ['validated' => false],
                ['validated' => false],
                ['validated' => false],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.tranches.0.comment', 'Après inspection du chantier.');
    }

    public function test_update_rejects_a_negative_amount(): void
    {
        $client = $this->makeClient();

        $this->withToken($this->agentToken())->putJson('/api/staff/decaissements/'.$client->id, [
            'terrain_montant' => -1,
        ])->assertStatus(422);
    }

    // ─── Validations ──────────────────────────────────────────
    //
    // Le déclenchement d'un versement d'argent réel exige désormais la
    // permission `validate-decaissements`, que le rôle `agent-cpi` n'a PAS :
    // l'agent prépare le dossier, un administrateur valide. Ces tests utilisent
    // donc un jeton admin là où ils utilisaient un jeton agent.

    public function test_validate_terrain_marks_the_disbursement_and_the_first_foncier_steps(): void
    {
        $client = $this->makeClient();
        $client->decaissement()->update(['terrain_montant' => 12000000]);

        $response = $this->withToken($this->adminToken())
            ->postJson('/api/staff/decaissements/'.$client->id.'/validate-terrain');

        $response->assertOk()
            ->assertJsonPath('data.terrainDecaisse', true)
            ->assertJsonPath('data.foncier', [true, true, false, false, false]);
        $this->assertNotNull($response->json('data.terrainDate'));

        $activity = Activity::query()->where('event', 'terrain-decaisse')->first();
        $this->assertNotNull($activity);
        $this->assertEquals(12000000, $activity->getExtraProperty('montant'));
    }

    public function test_validate_foncier_step_sets_only_that_index(): void
    {
        $client = $this->makeClient();
        // Le parcours foncier est séquentiel : les étapes 2 et 3 doivent être
        // franchies avant la 4 (l'étape 1 est acquise à la création du dossier).
        $client->decaissement->update(['foncier' => [true, true, true, false, false]]);

        $this->withToken($this->adminToken())
            ->postJson('/api/staff/decaissements/'.$client->id.'/validate-foncier/3')
            ->assertOk()
            ->assertJsonPath('data.foncier', [true, true, true, true, false]);

        $activity = Activity::query()->where('event', 'foncier-valide')->first();
        $this->assertNotNull($activity);
        $this->assertSame(3, $activity->getExtraProperty('etape'));
    }

    public function test_validate_foncier_step_out_of_range_returns_404(): void
    {
        $client = $this->makeClient();
        $token = $this->adminToken();

        $this->withToken($token)->postJson('/api/staff/decaissements/'.$client->id.'/validate-foncier/9')
            ->assertStatus(404);
        $this->withToken($token)->postJson('/api/staff/decaissements/'.$client->id.'/validate-foncier/abc')
            ->assertStatus(404);
    }

    public function test_validate_tranche_marks_it_and_dates_it(): void
    {
        $client = $this->makeClient();
        // Les tranches se décaissent dans l'ordre : la première doit l'être
        // avant la deuxième.
        $client->decaissement->update(['tranches' => [
            ['validated' => true], ['validated' => false], ['validated' => false], ['validated' => false],
        ]]);

        $response = $this->withToken($this->adminToken())
            ->postJson('/api/staff/decaissements/'.$client->id.'/validate-tranche/1');

        $response->assertOk()
            ->assertJsonPath('data.tranches.0.validated', true)
            ->assertJsonPath('data.tranches.1.validated', true);
        $this->assertNotNull($response->json('data.tranches.1.date'));

        $activity = Activity::query()->where('event', 'tranche-decaissee')->first();
        $this->assertNotNull($activity);
        $this->assertSame(1, $activity->getExtraProperty('tranche'));
    }

    public function test_validate_tranche_keeps_the_existing_comment(): void
    {
        $client = $this->makeClient();

        // Parcours nominal du contrôle à quatre yeux : l'agent prépare,
        // l'administrateur valide. Préparer et valider avec le même compte
        // renvoie désormais 409 (voir le test dédié plus bas).
        $this->withToken($this->agentToken())->putJson('/api/staff/decaissements/'.$client->id, [
            'tranches' => [
                ['validated' => false, 'comment' => 'Mobilisation des équipes.'],
                ['validated' => false],
                ['validated' => false],
                ['validated' => false],
            ],
        ])->assertOk();

        $this->withToken($this->adminToken())->postJson('/api/staff/decaissements/'.$client->id.'/validate-tranche/0')
            ->assertOk()
            ->assertJsonPath('data.tranches.0.validated', true)
            ->assertJsonPath('data.tranches.0.comment', 'Mobilisation des équipes.');
    }

    public function test_validate_tranche_out_of_range_returns_404(): void
    {
        $client = $this->makeClient();

        $this->withToken($this->adminToken())
            ->postJson('/api/staff/decaissements/'.$client->id.'/validate-tranche/7')
            ->assertStatus(404);
    }

    public function test_admin_can_also_drive_the_decaissement(): void
    {
        $client = $this->makeClient();
        // Décaisser un terrain sans montant renseigné n'a pas de sens et
        // masquerait une saisie oubliée.
        $client->decaissement->update(['terrain_montant' => 12000000]);

        $this->withToken($this->adminToken())->getJson('/api/staff/decaissements/'.$client->id)->assertOk();
        $this->withToken($this->adminToken())
            ->postJson('/api/staff/decaissements/'.$client->id.'/validate-terrain')
            ->assertOk()
            ->assertJsonPath('data.terrainDecaisse', true);
    }

    // ─── Séparation des rôles ─────────────────────────────────

    public function test_client_token_is_refused_on_every_decaissement_route(): void
    {
        [, $client, $token] = $this->makeClientUser();

        $this->withToken($token)->getJson('/api/staff/decaissements/'.$client->id)->assertStatus(403);
        $this->withToken($token)->putJson('/api/staff/decaissements/'.$client->id, ['terrain_montant' => 1])->assertStatus(403);
        $this->withToken($token)->postJson('/api/staff/decaissements/'.$client->id.'/validate-terrain')->assertStatus(403);
        $this->withToken($token)->postJson('/api/staff/decaissements/'.$client->id.'/validate-foncier/1')->assertStatus(403);
        $this->withToken($token)->postJson('/api/staff/decaissements/'.$client->id.'/validate-tranche/0')->assertStatus(403);
    }

    /** Un client ne peut ni lire ni modifier le décaissement d'un autre dossier. */
    public function test_client_a_cannot_touch_client_b_decaissement(): void
    {
        [, , $tokenA] = $this->makeClientUser();
        [, $clientB] = $this->makeClientUser();

        $this->withToken($tokenA)->getJson('/api/staff/decaissements/'.$clientB->id)->assertStatus(403);
        $this->withToken($tokenA)->putJson('/api/staff/decaissements/'.$clientB->id, ['terrain_montant' => 999])->assertStatus(403);
        $this->withToken($tokenA)->postJson('/api/staff/decaissements/'.$clientB->id.'/validate-terrain')->assertStatus(403);

        $this->assertDatabaseHas('decaissements', [
            'client_id' => $clientB->id,
            'terrain_decaisse' => false,
        ]);
    }

    public function test_decaissement_routes_require_authentication(): void
    {
        $client = $this->makeClient();

        $this->getJson('/api/staff/decaissements/'.$client->id)->assertStatus(401);
        $this->putJson('/api/staff/decaissements/'.$client->id, ['terrain_montant' => 1])->assertStatus(401);
        $this->postJson('/api/staff/decaissements/'.$client->id.'/validate-terrain')->assertStatus(401);
        $this->postJson('/api/staff/decaissements/'.$client->id.'/validate-foncier/1')->assertStatus(401);
        $this->postJson('/api/staff/decaissements/'.$client->id.'/validate-tranche/0')->assertStatus(401);
    }

    // ─── Contrôle à quatre yeux ───────────────────────────────

    public function test_an_agent_cannot_trigger_a_disbursement(): void
    {
        // Un seul compte agent compromis suffisait à préparer ET déclencher un
        // versement d'argent réel : la validation partageait la permission
        // `manage-decaissements` avec une modification de routine.
        $client = $this->makeClient();

        $this->withToken($this->agentToken())
            ->postJson('/api/staff/decaissements/'.$client->id.'/validate-terrain')
            ->assertForbidden();

        $this->assertFalse($client->decaissement->refresh()->terrain_decaisse);
    }

    public function test_whoever_prepared_the_disbursement_cannot_validate_it(): void
    {
        $client = $this->makeClient();
        $token = $this->adminToken();

        $this->withToken($token)->putJson('/api/staff/decaissements/'.$client->id, [
            'terrain_montant' => 12000000,
        ])->assertOk();

        $this->withToken($token)
            ->postJson('/api/staff/decaissements/'.$client->id.'/validate-terrain')
            ->assertStatus(409);

        $this->assertFalse($client->decaissement->refresh()->terrain_decaisse);
    }

    public function test_a_second_authorised_person_can_validate_what_someone_else_prepared(): void
    {
        $client = $this->makeClient();

        $this->withToken($this->agentToken())->putJson('/api/staff/decaissements/'.$client->id, [
            'terrain_montant' => 12000000,
        ])->assertOk();

        $this->withToken($this->adminToken())
            ->postJson('/api/staff/decaissements/'.$client->id.'/validate-terrain')
            ->assertOk();

        $this->assertTrue($client->decaissement->refresh()->terrain_decaisse);
    }

    public function test_a_legacy_disbursement_without_a_known_preparer_stays_validatable(): void
    {
        // Les décaissements antérieurs à la règle n'ont pas de préparateur
        // connu : rien ne permet de le reconstituer, et les bloquer figerait
        // les dossiers en cours.
        $client = $this->makeClient();
        $client->decaissement->update(['terrain_montant' => 12000000, 'prepared_by' => null]);

        $this->withToken($this->adminToken())
            ->postJson('/api/staff/decaissements/'.$client->id.'/validate-terrain')
            ->assertOk();
    }

    // ─── Garde-fous arithmétiques et d'ordre ──────────────────

    public function test_the_committed_total_cannot_exceed_the_granted_amount(): void
    {
        // Rien ne reliait l'argent versé au financement accordé : on pouvait
        // engager plus que le montant de la demande sans aucun contrôle.
        $client = $this->makeClient();
        Demande::create(['client_id' => $client->id, 'montant' => 20000000]);

        $this->withToken($this->agentToken())->putJson('/api/staff/decaissements/'.$client->id, [
            'terrain_montant' => 12000000,
            'construction_montant' => 15000000,
        ])->assertStatus(409);
    }

    public function test_a_total_within_the_granted_amount_is_accepted(): void
    {
        $client = $this->makeClient();
        Demande::create(['client_id' => $client->id, 'montant' => 30000000]);

        $this->withToken($this->agentToken())->putJson('/api/staff/decaissements/'.$client->id, [
            'terrain_montant' => 12000000,
            'construction_montant' => 15000000,
        ])->assertOk();
    }

    public function test_a_dossier_without_a_granted_amount_is_not_blocked(): void
    {
        // Le dossier n'est pas encore instruit : bloquer figerait les dossiers
        // en cours.
        $client = $this->makeClient();

        $this->withToken($this->agentToken())->putJson('/api/staff/decaissements/'.$client->id, [
            'terrain_montant' => 99000000,
        ])->assertOk();
    }

    public function test_tranches_must_be_disbursed_in_order(): void
    {
        $client = $this->makeClient();

        $this->withToken($this->adminToken())
            ->postJson('/api/staff/decaissements/'.$client->id.'/validate-tranche/2')
            ->assertStatus(409);

        $this->assertFalse($client->decaissement->refresh()->tranches[2]['validated']);
    }

    public function test_foncier_steps_must_be_validated_in_order(): void
    {
        $client = $this->makeClient();

        $this->withToken($this->adminToken())
            ->postJson('/api/staff/decaissements/'.$client->id.'/validate-foncier/4')
            ->assertStatus(409);
    }

    public function test_the_land_cannot_be_disbursed_without_an_amount(): void
    {
        $client = $this->makeClient();

        $this->withToken($this->adminToken())
            ->postJson('/api/staff/decaissements/'.$client->id.'/validate-terrain')
            ->assertStatus(409);

        $this->assertFalse($client->decaissement->refresh()->terrain_decaisse);
    }
}
