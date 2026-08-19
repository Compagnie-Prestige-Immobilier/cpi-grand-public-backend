<?php

namespace Tests\Feature;

use App\Enums\StatutCompte;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CompteValidationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function admin(): User
    {
        return User::where('email', 'admin@cpi.sn')->firstOrFail();
    }

    private function jetonAdmin(): string
    {
        return $this->admin()->createToken('t')->plainTextToken;
    }

    /** @return array{0: User, 1: string} */
    private function clientEnAttente(): array
    {
        $user = User::factory()->enAttenteValidation()->create();
        $user->assignRole('client');
        Client::create([
            'user_id' => $user->id, 'name' => $user->name, 'ref' => Client::generateRef(),
            'email' => $user->email, 'date_inscription' => now(),
        ]);

        return [$user, $user->createToken('t')->plainTextToken];
    }

    // ── Le blocage lui-même ─────────────────────────────────────────────

    public function test_an_unvalidated_client_cannot_reach_client_routes(): void
    {
        [, $token] = $this->clientEnAttente();

        $this->withToken($token)->getJson('/api/client/profile')
            ->assertStatus(403)
            ->assertJsonPath('statut_compte', StatutCompte::EnAttenteValidation->value);
    }

    public function test_an_unvalidated_client_can_still_reach_the_essentials(): void
    {
        [, $token] = $this->clientEnAttente();

        // Sans recours, la personne serait bloquée devant un écran vide sans
        // même savoir pourquoi ni pouvoir en sortir.
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
    }

    public function test_the_support_form_stays_open_to_an_unvalidated_account(): void
    {
        [, $token] = $this->clientEnAttente();

        // C'est le seul recours d'une personne bloquée : lui fermer ce
        // formulaire serait le meilleur moyen de recevoir des appels.
        $reponse = $this->withToken($token)->postJson('/api/client/support', [
            'sujet' => 'Pourquoi mon compte est-il bloqué ?',
            'message' => 'Je ne comprends pas.',
        ]);
        $this->assertNotSame(403, $reponse->getStatusCode());
    }

    public function test_staff_accounts_are_never_blocked_by_this_middleware(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole('agent-cpi');

        $this->withToken($agent->createToken('t')->plainTextToken)
            ->getJson('/api/staff/clients')
            ->assertOk();
    }

    // ── La file d'attente ────────────────────────────────────────────────

    public function test_only_verified_and_pending_accounts_appear_in_the_queue(): void
    {
        [$enAttente] = $this->clientEnAttente();
        $nonVerifie = User::factory()->emailAVerifier()->create();
        $dejaValide = User::factory()->create();

        $reponse = $this->withToken($this->jetonAdmin())->getJson('/api/staff/comptes/en-attente');

        /** @var array<int, array{id: string}> $donnees */
        $donnees = $reponse->json('data');
        $ids = collect($donnees)->pluck('id');
        $this->assertTrue($ids->contains($enAttente->id));
        $this->assertFalse($ids->contains($nonVerifie->id));
        $this->assertFalse($ids->contains($dejaValide->id));
    }

    public function test_an_agent_cannot_see_the_queue(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole('agent-cpi');

        $this->withToken($agent->createToken('t')->plainTextToken)
            ->getJson('/api/staff/comptes/en-attente')
            ->assertForbidden();
    }

    // ── Approbation ──────────────────────────────────────────────────────

    public function test_approving_grants_access(): void
    {
        [$user, $token] = $this->clientEnAttente();

        $this->withToken($this->jetonAdmin())
            ->postJson("/api/staff/comptes/{$user->id}/valider")
            ->assertOk();

        $this->assertTrue($user->refresh()->compteValide());
        $this->withToken($token)->getJson('/api/client/profile')->assertOk();
    }

    public function test_approval_is_journaled(): void
    {
        [$user] = $this->clientEnAttente();

        $this->withToken($this->jetonAdmin())->postJson("/api/staff/comptes/{$user->id}/valider");

        $this->assertNotNull(Activity::where('event', 'compte-valide')->first());
    }

    public function test_a_freshly_registered_account_cannot_be_approved_directly(): void
    {
        // Sauter l'étape de vérification d'adresse serait une transition
        // illégale : `email_a_verifier -> valide` n'existe pas dans l'enum.
        $user = User::factory()->emailAVerifier()->create();

        $this->withToken($this->jetonAdmin())
            ->postJson("/api/staff/comptes/{$user->id}/valider")
            ->assertStatus(409);
    }

    // ── Refus ────────────────────────────────────────────────────────────

    public function test_rejecting_requires_a_reason(): void
    {
        [$user] = $this->clientEnAttente();

        $this->withToken($this->jetonAdmin())
            ->postJson("/api/staff/comptes/{$user->id}/rejeter", [])
            ->assertStatus(422);
    }

    public function test_rejecting_records_the_reason_and_blocks_access(): void
    {
        [$user, $token] = $this->clientEnAttente();

        $this->withToken($this->jetonAdmin())
            ->postJson("/api/staff/comptes/{$user->id}/rejeter", ['motif' => 'Numéro de téléphone invalide.'])
            ->assertOk();

        $user->refresh();
        $this->assertSame(StatutCompte::Rejete, $user->statut_compte);
        $this->assertSame('Numéro de téléphone invalide.', $user->motif_rejet);

        $this->withToken($token)->getJson('/api/client/profile')
            ->assertStatus(403)
            ->assertJsonPath('motif_rejet', 'Numéro de téléphone invalide.');
    }

    // ── Resoumission ─────────────────────────────────────────────────────

    public function test_a_rejected_user_can_correct_and_resubmit(): void
    {
        [$user, $token] = $this->clientEnAttente();
        $this->withToken($this->jetonAdmin())
            ->postJson("/api/staff/comptes/{$user->id}/rejeter", ['motif' => 'Téléphone invalide.']);

        $this->withToken($token)
            ->putJson('/api/auth/mon-compte', ['phone' => '+221771112233'])
            ->assertOk()
            ->assertJsonPath('data.user.statutCompte', StatutCompte::EnAttenteValidation->value);

        $user->refresh();
        $this->assertNull($user->motif_rejet);
        $this->assertSame('+221771112233', $user->phone);
    }

    public function test_a_pending_account_cannot_resubmit(): void
    {
        // Rien à corriger : seul un compte REFUSÉ a une transition légale vers
        // « en attente ». Un compte déjà en file ne peut pas s'y remettre lui-même.
        [, $token] = $this->clientEnAttente();

        $this->withToken($token)
            ->putJson('/api/auth/mon-compte', ['phone' => '+221770000000'])
            ->assertStatus(409);
    }

    public function test_a_validated_account_cannot_be_reopened_via_this_route(): void
    {
        $token = ($validated = User::factory()->create())->createToken('t')->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/auth/mon-compte', ['phone' => '+221770000000'])
            ->assertStatus(409);
    }
}
