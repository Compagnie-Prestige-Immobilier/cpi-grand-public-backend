<?php

namespace Tests\Feature;

use App\Models\Chantier;
use App\Models\ChantierTranche;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Chantier (Phase 5) : avancement des travaux d'un dossier. Le client consulte
 * SON chantier (/client/mon-chantier) ; le personnel CPI le pilote (/staff/*).
 */
class ChantierTest extends TestCase
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
            'name' => 'Client Chantier',
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

    // ─── Provisionnement ──────────────────────────────────────

    public function test_client_creation_provisions_the_chantier_and_its_four_tranches(): void
    {
        $client = $this->makeClient();

        $this->assertDatabaseHas('chantiers', [
            'client_id' => $client->id,
            'statut' => 'non-demarre',
            'progression' => 0,
        ]);
        $this->assertSame(4, ChantierTranche::query()->count());
        $this->assertSame(
            [1, 2, 3, 4],
            $client->chantier->tranches()->pluck('num')->all(),
        );
    }

    /** Un dossier antérieur au module (aucune ligne) ne doit jamais faire 500. */
    public function test_show_creates_the_row_on_demand_for_a_legacy_client(): void
    {
        $client = $this->makeClient();
        // `forceDelete` : depuis l'introduction des suppressions douces, un
        // `delete()` laisserait la ligne en base avec un `deleted_at` — ce que
        // ce test veut simuler, c'est un dossier qui n'a JAMAIS eu de chantier.
        Chantier::query()->where('client_id', $client->id)->forceDelete();
        $this->assertDatabaseMissing('chantiers', ['client_id' => $client->id]);

        $this->withToken($this->agentToken())->getJson('/api/staff/chantiers/'.$client->id)
            ->assertOk()
            ->assertJsonPath('data.clientId', $client->id)
            ->assertJsonPath('data.statut', 'non-demarre')
            ->assertJsonPath('data.progression', 0)
            ->assertJsonCount(4, 'data.tranches');

        $this->assertDatabaseHas('chantiers', ['client_id' => $client->id]);
    }

    // ─── Lecture client ───────────────────────────────────────

    public function test_mine_returns_a_renderable_chantier_even_before_any_work(): void
    {
        [, $client, $token] = $this->makeClientUser();

        $this->withToken($token)->getJson('/api/client/mon-chantier')
            ->assertOk()
            ->assertJsonPath('data.clientId', $client->id)
            ->assertJsonPath('data.statut', 'non-demarre')
            ->assertJsonPath('data.progression', 0)
            ->assertJsonPath('data.etapeActuelle', 'Non démarré')
            ->assertJsonCount(4, 'data.tranches')
            ->assertJsonCount(0, 'data.publications')
            ->assertJsonCount(0, 'data.medias')
            ->assertJsonCount(0, 'data.events');
    }

    /** Les contenus internes du personnel ne sortent jamais côté client. */
    public function test_mine_hides_internal_publications_medias_and_events(): void
    {
        [, $client, $token] = $this->makeClientUser();
        $chantier = $client->ensureChantier();

        $chantier->publications()->create([
            'phase' => 1, 'titre' => 'Interne', 'description' => 'Note interne',
            'heure' => '10:00', 'auteur' => 'Agent CPI', 'type' => 'commentaire',
            'visible_client' => false, 'date' => now(),
        ]);
        $chantier->publications()->create([
            'phase' => 1, 'titre' => 'Publique', 'description' => 'Toiture posée',
            'heure' => '11:00', 'auteur' => 'Agent CPI', 'type' => 'actualite',
            'visible_client' => true, 'date' => now(),
        ]);
        $chantier->events()->create([
            'titre' => 'Réunion interne', 'type' => 'inspection', 'date' => now(),
            'description' => 'Point équipe', 'statut' => 'prevu', 'visible_client' => false,
        ]);
        $chantier->medias()->create([
            'type' => 'photo', 'titre' => 'Photo interne', 'phase' => 1,
            'auteur' => 'Agent CPI', 'url' => 'chantier/x/y.jpg',
            'visible_client' => false, 'date' => now(),
        ]);

        $response = $this->withToken($token)->getJson('/api/client/mon-chantier');

        $response->assertOk()
            ->assertJsonCount(1, 'data.publications')
            ->assertJsonPath('data.publications.0.titre', 'Publique')
            ->assertJsonCount(0, 'data.events')
            ->assertJsonCount(0, 'data.medias');
    }

    // ─── Mise à jour ──────────────────────────────────────────

    public function test_update_sets_the_project_sheet_and_logs(): void
    {
        $client = $this->makeClient();

        $this->withToken($this->agentToken())->putJson('/api/staff/chantiers/'.$client->id, [
            'projet' => 'Villa Almadies',
            'reference' => 'CH-2026-001',
            'localisation' => 'Almadies, Dakar',
            'chef_chantier' => 'Moussa Diop',
            'entreprise' => 'BTP Sénégal',
            'date_debut' => '2026-08-01',
            'date_livraison' => '2027-03-15',
        ])
            ->assertOk()
            ->assertJsonPath('data.projet', 'Villa Almadies')
            ->assertJsonPath('data.chefChantier', 'Moussa Diop')
            ->assertJsonPath('data.entreprise', 'BTP Sénégal');

        $this->assertNotNull(Activity::query()->where('event', 'chantier-modifie')->first());
        $this->assertNotNull($client->chantier()->first()?->derniere_maj);
    }

    public function test_update_rejects_an_unknown_statut(): void
    {
        $client = $this->makeClient();

        $this->withToken($this->agentToken())
            ->putJson('/api/staff/chantiers/'.$client->id, ['statut' => 'inconnu'])
            ->assertStatus(422);
    }

    public function test_update_progression_clamps_and_logs(): void
    {
        $client = $this->makeClient();

        $this->withToken($this->agentToken())
            ->postJson('/api/staff/chantiers/'.$client->id.'/progression', ['pct' => 42])
            ->assertOk()
            ->assertJsonPath('data.progression', 42);

        $activity = Activity::query()->where('event', 'chantier-progression')->first();
        $this->assertNotNull($activity);
        $this->assertSame(0, $activity->getExtraProperty('ancienne'));
        $this->assertSame(42, $activity->getExtraProperty('nouvelle'));

        $this->withToken($this->agentToken())
            ->postJson('/api/staff/chantiers/'.$client->id.'/progression', ['pct' => 101])
            ->assertStatus(422);
        $this->withToken($this->agentToken())
            ->postJson('/api/staff/chantiers/'.$client->id.'/progression', ['pct' => -1])
            ->assertStatus(422);
    }

    public function test_update_etape_and_statut_log_their_change(): void
    {
        $client = $this->makeClient();
        $token = $this->agentToken();

        $this->withToken($token)
            ->postJson('/api/staff/chantiers/'.$client->id.'/etape', ['etape' => 'Gros œuvre'])
            ->assertOk()
            ->assertJsonPath('data.etapeActuelle', 'Gros œuvre');

        $this->withToken($token)
            ->postJson('/api/staff/chantiers/'.$client->id.'/statut', ['statut' => 'en-cours'])
            ->assertOk()
            ->assertJsonPath('data.statut', 'en-cours');

        $this->assertNotNull(Activity::query()->where('event', 'chantier-etape')->first());
        $this->assertNotNull(Activity::query()->where('event', 'chantier-statut')->first());
    }

    public function test_update_statut_rejects_an_unknown_value(): void
    {
        $client = $this->makeClient();

        $this->withToken($this->agentToken())
            ->postJson('/api/staff/chantiers/'.$client->id.'/statut', ['statut' => 'demoli'])
            ->assertStatus(422);
    }

    // ─── Validation d'une tranche ─────────────────────────────

    public function test_validate_tranche_marks_only_that_tranche(): void
    {
        $client = $this->makeClient();

        $response = $this->withToken($this->agentToken())
            ->postJson('/api/staff/chantiers/'.$client->id.'/tranche/2/validate');

        $response->assertOk()
            ->assertJsonPath('data.tranches.0.etat', 'en-attente')
            ->assertJsonPath('data.tranches.1.etat', 'terminee')
            ->assertJsonPath('data.tranches.2.etat', 'en-attente');
        $this->assertNotNull($response->json('data.tranches.1.date'));

        $activity = Activity::query()->where('event', 'chantier-tranche-terminee')->first();
        $this->assertNotNull($activity);
        $this->assertSame(2, $activity->getExtraProperty('tranche'));
    }

    public function test_validate_tranche_out_of_range_returns_404(): void
    {
        $client = $this->makeClient();
        $token = $this->agentToken();

        $this->withToken($token)->postJson('/api/staff/chantiers/'.$client->id.'/tranche/9/validate')
            ->assertStatus(404);
        $this->withToken($token)->postJson('/api/staff/chantiers/'.$client->id.'/tranche/abc/validate')
            ->assertStatus(404);
    }

    public function test_admin_can_also_drive_the_chantier(): void
    {
        $client = $this->makeClient();

        $this->withToken($this->adminToken())->getJson('/api/staff/chantiers/'.$client->id)->assertOk();

        // Le chantier suit son cours : non démarré → en cours → terminé →
        // livré. Sauter directement à « livré » depuis « non démarré » décrit
        // une réalité impossible et est désormais refusé.
        foreach (['en-cours', 'termine', 'livre'] as $etape) {
            $this->withToken($this->adminToken())
                ->postJson('/api/staff/chantiers/'.$client->id.'/statut', ['statut' => $etape])
                ->assertOk()
                ->assertJsonPath('data.statut', $etape);
        }
    }

    public function test_a_chantier_cannot_jump_straight_to_delivered(): void
    {
        // `Rule::in` validait la valeur, jamais la transition : un chantier
        // pouvait passer de « non démarré » à « livré », ou revenir en arrière
        // après livraison, ce que le client voyait sans explication.
        $client = $this->makeClient();

        $this->withToken($this->adminToken())
            ->postJson('/api/staff/chantiers/'.$client->id.'/statut', ['statut' => 'livre'])
            ->assertStatus(409);

        $this->assertSame('non-demarre', $client->chantier->refresh()->statut->value);
    }

    public function test_a_delivered_chantier_cannot_go_back(): void
    {
        $client = $this->makeClient();
        $client->chantier->update(['statut' => 'livre']);

        $this->withToken($this->adminToken())
            ->postJson('/api/staff/chantiers/'.$client->id.'/statut', ['statut' => 'en-cours'])
            ->assertStatus(409);
    }

    // ─── Séparation des rôles ─────────────────────────────────

    public function test_client_token_is_refused_on_every_staff_chantier_route(): void
    {
        [, $client, $token] = $this->makeClientUser();

        $this->withToken($token)->getJson('/api/staff/chantiers/'.$client->id)->assertStatus(403);
        $this->withToken($token)->putJson('/api/staff/chantiers/'.$client->id, ['projet' => 'X'])->assertStatus(403);
        $this->withToken($token)->postJson('/api/staff/chantiers/'.$client->id.'/progression', ['pct' => 50])->assertStatus(403);
        $this->withToken($token)->postJson('/api/staff/chantiers/'.$client->id.'/etape', ['etape' => 'X'])->assertStatus(403);
        $this->withToken($token)->postJson('/api/staff/chantiers/'.$client->id.'/statut', ['statut' => 'en-cours'])->assertStatus(403);
        $this->withToken($token)->postJson('/api/staff/chantiers/'.$client->id.'/tranche/1/validate')->assertStatus(403);

        // Rien n'a bougé.
        $this->assertDatabaseHas('chantiers', ['client_id' => $client->id, 'progression' => 0, 'statut' => 'non-demarre']);
    }

    public function test_staff_token_is_refused_on_the_client_chantier_route(): void
    {
        $this->withToken($this->agentToken())->getJson('/api/client/mon-chantier')->assertStatus(403);
        $this->withToken($this->adminToken())->getJson('/api/client/mon-chantier')->assertStatus(403);
    }

    /** Un client ne lit ni ne modifie le chantier d'un autre dossier. */
    public function test_client_a_cannot_touch_client_b_chantier(): void
    {
        [, , $tokenA] = $this->makeClientUser();
        [, $clientB] = $this->makeClientUser();

        $this->withToken($tokenA)->getJson('/api/staff/chantiers/'.$clientB->id)->assertStatus(403);
        $this->withToken($tokenA)->putJson('/api/staff/chantiers/'.$clientB->id, ['projet' => 'Pirate'])->assertStatus(403);
        $this->withToken($tokenA)->postJson('/api/staff/chantiers/'.$clientB->id.'/progression', ['pct' => 100])->assertStatus(403);
        $this->withToken($tokenA)->postJson('/api/staff/chantiers/'.$clientB->id.'/tranche/1/validate')->assertStatus(403);

        $this->assertDatabaseHas('chantiers', [
            'client_id' => $clientB->id,
            'projet' => null,
            'progression' => 0,
        ]);
    }

    /** /client/mon-chantier ne renvoie jamais que le dossier du porteur du jeton. */
    public function test_mine_only_ever_returns_the_callers_own_chantier(): void
    {
        [, $clientA, $tokenA] = $this->makeClientUser();
        [, $clientB] = $this->makeClientUser();
        // Chantier de B rempli : rien n'en doit fuiter dans la réponse de A.
        $chantierB = $clientB->ensureChantier();
        $chantierB->update(['projet' => 'Villa de B', 'progression' => 80]);

        $response = $this->withToken($tokenA)->getJson('/api/client/mon-chantier');

        $response->assertOk()
            ->assertJsonPath('data.clientId', $clientA->id)
            ->assertJsonPath('data.id', $clientA->chantier?->id)
            ->assertJsonPath('data.progression', 0);
        $this->assertStringNotContainsString($chantierB->id, $response->getContent() ?: '');
        $this->assertStringNotContainsString('Villa de B', $response->getContent() ?: '');
    }

    public function test_chantier_routes_require_authentication(): void
    {
        $client = $this->makeClient();

        $this->getJson('/api/client/mon-chantier')->assertStatus(401);
        $this->getJson('/api/staff/chantiers/'.$client->id)->assertStatus(401);
        $this->putJson('/api/staff/chantiers/'.$client->id, ['projet' => 'X'])->assertStatus(401);
        $this->postJson('/api/staff/chantiers/'.$client->id.'/progression', ['pct' => 10])->assertStatus(401);
        $this->postJson('/api/staff/chantiers/'.$client->id.'/etape', ['etape' => 'X'])->assertStatus(401);
        $this->postJson('/api/staff/chantiers/'.$client->id.'/statut', ['statut' => 'en-cours'])->assertStatus(401);
        $this->postJson('/api/staff/chantiers/'.$client->id.'/tranche/1/validate')->assertStatus(401);
    }

    /** Un compte sans dossier (staff démis de son rôle, par ex.) ne fait pas 500. */
    public function test_mine_returns_404_when_the_account_has_no_dossier(): void
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        $this->withToken($user->createToken('t')->plainTextToken)
            ->getJson('/api/client/mon-chantier')
            ->assertStatus(404);
    }
}
