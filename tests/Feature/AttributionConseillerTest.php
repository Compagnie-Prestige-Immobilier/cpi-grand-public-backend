<?php

namespace Tests\Feature;

use App\Enums\RequisDocStatut;
use App\Enums\StatutCompte;
use App\Models\Client;
use App\Models\Demande;
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
}
