<?php

namespace Tests\Feature;

use App\Enums\RequisDocStatut;
use App\Enums\StatutCompte;
use App\Models\Client;
use App\Models\Demande;
use App\Models\Notification;
use App\Models\User;
use App\Support\AttributionConseiller;
use App\Support\ParcoursDossier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AttributionConseillerTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /**
     * Le seed crée `agent@cpi.sn` — un agent-cpi bien réel qui fausserait tous
     * les tests d'équilibrage ci-dessous, écrits pour un pool d'agents
     * entièrement sous contrôle du test. `admin@cpi.sn`, lui, reste : c'est le
     * jeton utilisé par `jetonAdmin()`.
     */
    protected function setUp(): void
    {
        parent::setUp();
        User::where('email', 'agent@cpi.sn')->delete();
    }

    private function agent(string $nom = 'Agent'): User
    {
        $u = User::factory()->create(['name' => $nom]);
        $u->assignRole('agent-cpi');

        return $u;
    }

    private function admin(): User
    {
        return User::where('email', 'admin@cpi.sn')->firstOrFail();
    }

    private function jetonAdmin(): string
    {
        return $this->admin()->createToken('t')->plainTextToken;
    }

    /** Client sans conseiller, dossier « reçu » (soumis, pièces incomplètes) — actif par défaut. */
    private function client(?User $conseiller = null): Client
    {
        $user = User::factory()->create();
        $user->assignRole('client');
        $client = Client::create([
            'user_id' => $user->id, 'name' => $user->name, 'ref' => Client::generateRef(),
            'email' => $user->email, 'date_inscription' => now(), 'conseiller_id' => $conseiller?->id,
        ]);

        return $client->refresh();
    }

    /**
     * Fait avancer un dossier jusqu'à l'étape donnée.
     *
     * `dossier_etape` seul n'a AUCUN effet sur `ParcoursDossier::etape()` :
     * l'expression SQL retombe sur INSCRIPTION tant qu'aucune demande n'est
     * soumise, et sur PIECES tant que les pièces requises (créées
     * automatiquement à la création du client) ne sont pas toutes acceptées.
     * Un dossier n'atteint réellement une étape ≥ INSTRUCTION qu'en réunissant
     * les trois : demande soumise, pièces acceptées, `dossier_etape` posé.
     */
    private function faireAvancerDossier(Client $client, int $etape): void
    {
        Demande::create(['client_id' => $client->id, 'submitted' => true, 'submitted_at' => now()]);
        $client->requisDocs()->update(['status' => RequisDocStatut::Accepte]);
        $client->update(['dossier_etape' => $etape]);
    }

    // ── Cas de base ─────────────────────────────────────────────────────

    public function test_assigns_to_the_only_agent_available(): void
    {
        $agent = $this->agent();
        $client = $this->client();

        $conseiller = AttributionConseiller::assigner($client);

        $this->assertSame($agent->id, $conseiller?->id);
        $this->assertSame($agent->id, $client->refresh()->conseiller_id);
    }

    public function test_the_display_column_is_kept_in_sync(): void
    {
        // La colonne texte `conseiller`, lue par tout l'affichage client, n'a
        // aucun lien automatique avec la relation `conseiller_id` : sans cette
        // écriture, le client verrait « Non assigné » malgré un conseiller
        // réellement attribué en base.
        $agent = $this->agent('Fatou Ndiaye');
        $client = $this->client();

        AttributionConseiller::assigner($client);

        $this->assertSame('Fatou Ndiaye', $client->refresh()->conseiller);
    }

    public function test_returns_null_when_no_agent_exists(): void
    {
        $client = $this->client();

        $conseiller = AttributionConseiller::assigner($client);

        $this->assertNull($conseiller);
        $this->assertNull($client->refresh()->conseiller_id);
    }

    // ── L'équilibrage lui-même ──────────────────────────────────────────

    public function test_picks_the_agent_with_fewer_active_dossiers(): void
    {
        $charge = $this->agent('Chargé');
        $libre = $this->agent('Libre');
        $this->client($charge);
        $this->client($charge);

        $conseiller = AttributionConseiller::assigner($this->client());

        $this->assertSame($libre->id, $conseiller?->id);
    }

    public function test_a_brand_new_agent_with_zero_dossiers_is_preferred(): void
    {
        $ancien = $this->agent('Ancien');
        $this->client($ancien);
        $nouveau = $this->agent('Nouveau');   // n'apparaît dans aucun compte de charge

        $conseiller = AttributionConseiller::assigner($this->client());

        $this->assertSame($nouveau->id, $conseiller?->id);
    }

    public function test_finished_dossiers_do_not_count_as_load(): void
    {
        // Un agent qui a mené dix dossiers jusqu'à la signature ne doit pas
        // paraître plus chargé qu'un agent qui vient d'en recevoir un seul,
        // encore en cours — c'est la charge RÉELLE que la règle équilibre.
        $veteran = $this->agent('Vétéran');
        for ($i = 0; $i < 10; $i++) {
            $this->faireAvancerDossier($this->client($veteran), ParcoursDossier::SIGNATURE);
        }
        $recent = $this->agent('Récent');
        $this->faireAvancerDossier($this->client($recent), ParcoursDossier::INSTRUCTION);

        $conseiller = AttributionConseiller::assigner($this->client());

        $this->assertSame($veteran->id, $conseiller?->id);
    }

    public function test_super_admin_is_never_picked(): void
    {
        // Aucun agent-cpi : seul un super-admin existe (en plus de celui du
        // seed). La règle ne doit jamais s'y replier.
        $client = $this->client();

        $conseiller = AttributionConseiller::assigner($client);

        $this->assertNull($conseiller);
    }

    public function test_ties_are_broken_by_ascending_id(): void
    {
        // Deux agents à charge égale (zéro) : le résultat doit être
        // déterministe — l'agent le plus ancien (id le plus bas) l'emporte —
        // et non un artefact de l'ordre de lecture SQL. Une seule décision :
        // une deuxième assignation romprait elle-même l'égalité (l'agent
        // choisi a désormais un dossier de plus), ce n'est donc pas ce que ce
        // test vérifie.
        $agentA = $this->agent('A');
        $agentB = $this->agent('B');
        $attendu = $agentA->id < $agentB->id ? $agentA : $agentB;

        $conseiller = AttributionConseiller::assigner($this->client());

        $this->assertSame($attendu->id, $conseiller?->id);
    }

    // ── Intégration avec la validation de compte ────────────────────────

    public function test_approving_an_account_assigns_a_conseiller(): void
    {
        $agent = $this->agent('Awa Sarr');
        $client = $this->client();
        $client->user->update(['statut_compte' => StatutCompte::EnAttenteValidation]);

        $reponse = $this->withToken($this->jetonAdmin())
            ->postJson("/api/staff/comptes/{$client->user_id}/valider")
            ->assertOk();

        $reponse->assertJsonPath('data.conseiller.id', $agent->id);
        $this->assertSame($agent->id, $client->refresh()->conseiller_id);
    }

    public function test_approving_without_any_agent_still_succeeds(): void
    {
        $client = $this->client();
        $client->user->update(['statut_compte' => StatutCompte::EnAttenteValidation]);

        $this->withToken($this->jetonAdmin())
            ->postJson("/api/staff/comptes/{$client->user_id}/valider")
            ->assertOk()
            ->assertJsonPath('data.conseiller', null);

        // Le compte est validé malgré tout — l'absence d'agent ne doit jamais
        // faire échouer l'approbation elle-même.
        $this->assertTrue($client->user->refresh()->compteValide());
        $this->assertNull($client->refresh()->conseiller_id);
    }

    public function test_the_unassigned_case_is_journaled_distinctly(): void
    {
        $client = $this->client();
        $client->user->update(['statut_compte' => StatutCompte::EnAttenteValidation]);

        $this->withToken($this->jetonAdmin())->postJson("/api/staff/comptes/{$client->user_id}/valider");

        $this->assertNotNull(Activity::where('event', 'conseiller-non-attribue')->first());
    }

    public function test_assignment_is_journaled_and_the_client_is_notified(): void
    {
        $agent = $this->agent();
        $client = $this->client();
        $client->user->update(['statut_compte' => StatutCompte::EnAttenteValidation]);

        $this->withToken($this->jetonAdmin())->postJson("/api/staff/comptes/{$client->user_id}/valider");

        $entree = Activity::where('event', 'conseiller-attribue')->first();
        $this->assertNotNull($entree);
        $this->assertSame($agent->id, $entree->properties['conseiller_id']);

        $this->assertDatabaseHas('app_notifications', [
            'client_id' => $client->id,
            'titre' => 'Conseiller attribué',
        ]);
    }

    // ── Réattribution manuelle (PUT /staff/clients/{client}/conseiller) ──

    public function test_admin_moves_a_dossier_from_one_agent_to_another(): void
    {
        $ancien = $this->agent('Ancien');
        $nouveau = $this->agent('Nouveau');
        $client = $this->client($ancien);

        $this->withToken($this->jetonAdmin())
            ->putJson("/api/staff/clients/{$client->id}/conseiller", ['conseiller_id' => $nouveau->id])
            ->assertOk()
            ->assertJsonPath('data.conseiller.id', $nouveau->id);

        $client->refresh();
        $this->assertSame($nouveau->id, $client->conseiller_id);
        $this->assertSame($nouveau->name, $client->conseiller);
    }

    public function test_reassignment_is_journaled_with_both_agents(): void
    {
        $ancien = $this->agent('Ancien');
        $nouveau = $this->agent('Nouveau');
        $client = $this->client($ancien);

        $this->withToken($this->jetonAdmin())
            ->putJson("/api/staff/clients/{$client->id}/conseiller", ['conseiller_id' => $nouveau->id]);

        $entree = Activity::where('event', 'conseiller-reattribue')->first();
        $this->assertNotNull($entree);
        $this->assertSame($ancien->id, $entree->properties['ancien_conseiller_id']);
        $this->assertSame($nouveau->id, $entree->properties['nouveau_conseiller_id']);
    }

    /** Les DEUX agents reçoivent une notification — l'un perd le dossier, l'autre le reçoit. */
    public function test_reassignment_notifies_both_the_former_and_the_new_agent(): void
    {
        $ancien = $this->agent('Ancien');
        $nouveau = $this->agent('Nouveau');
        $client = $this->client($ancien);

        $this->withToken($this->jetonAdmin())
            ->putJson("/api/staff/clients/{$client->id}/conseiller", ['conseiller_id' => $nouveau->id]);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $ancien->id,
            'client_id' => null,
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $nouveau->id,
            'client_id' => null,
        ]);
    }

    /**
     * Une notification de portefeuille ne doit JAMAIS porter le `client_id`
     * du dossier concerné : `NotificationController::mine()` élargit la boîte
     * d'un client par `client_id`, une notification adressée à l'agent
     * fuiterait donc dans la boîte du CLIENT lui-même.
     */
    public function test_a_staff_reassignment_notification_never_leaks_into_the_clients_own_inbox(): void
    {
        $ancien = $this->agent('Ancien');
        $nouveau = $this->agent('Nouveau');
        $client = $this->client($ancien);

        $this->withToken($this->jetonAdmin())
            ->putJson("/api/staff/clients/{$client->id}/conseiller", ['conseiller_id' => $nouveau->id]);

        $reponse = $this->withToken($client->user->createToken('t')->plainTextToken)
            ->getJson('/api/client/notifications')
            ->assertOk();

        $titres = array_column((array) $reponse->json('data'), 'titre');
        $this->assertNotContains('Portefeuille modifié', $titres);
    }

    /** L'attribution initiale d'un dossier resté sans conseiller passe par le même chemin. */
    public function test_admin_can_assign_an_unassigned_dossier_to_a_chosen_agent(): void
    {
        $agent = $this->agent();
        $client = $this->client();   // sans conseiller

        $this->withToken($this->jetonAdmin())
            ->putJson("/api/staff/clients/{$client->id}/conseiller", ['conseiller_id' => $agent->id])
            ->assertOk();

        $this->assertSame($agent->id, $client->refresh()->conseiller_id);
        // Sans conseiller AVANT : une seule notification de portefeuille (le
        // nouvel agent), aucune pour un « ancien conseiller » qui n'existait pas.
        $this->assertSame(1, Notification::whereNull('client_id')->count());
    }

    public function test_agent_cannot_reassign_a_dossier(): void
    {
        $ancien = $this->agent('Ancien');
        $nouveau = $this->agent('Nouveau');
        $client = $this->client($ancien);

        $this->withToken($ancien->createToken('t')->plainTextToken)
            ->putJson("/api/staff/clients/{$client->id}/conseiller", ['conseiller_id' => $nouveau->id])
            ->assertStatus(403);
    }

    public function test_reassignment_to_the_same_agent_is_rejected(): void
    {
        $agent = $this->agent();
        $client = $this->client($agent);

        $this->withToken($this->jetonAdmin())
            ->putJson("/api/staff/clients/{$client->id}/conseiller", ['conseiller_id' => $agent->id])
            ->assertStatus(409);
    }

    public function test_reassignment_to_a_non_agent_account_is_rejected(): void
    {
        $client = $this->client($this->agent());
        $unClient = User::factory()->create();
        $unClient->assignRole('client');

        $this->withToken($this->jetonAdmin())
            ->putJson("/api/staff/clients/{$client->id}/conseiller", ['conseiller_id' => $unClient->id])
            ->assertStatus(422);
    }
}
