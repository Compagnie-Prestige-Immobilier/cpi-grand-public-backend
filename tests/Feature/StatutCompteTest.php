<?php

namespace Tests\Feature;

use App\Enums\StatutCompte;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class StatutCompteTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function inscrire(string $email = 'nouveau@exemple.sn'): User
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Nouveau Client',
            'email' => $email,
            'password' => 'MotDePasse!2026',
        ])->assertCreated();

        return User::where('email', $email)->firstOrFail();
    }

    private function lienDeVerification(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);
    }

    /**
     * La vérification d'e-mail est temporairement NON bloquante (même
     * traitement que Google, dont le fournisseur a déjà prouvé l'adresse) :
     * un compte fraîchement inscrit part directement en file d'attente
     * administrative, sans passer par `EmailAVerifier`. Seule la validation
     * humaine reste un vrai barrage.
     */
    public function test_a_new_account_starts_unverified_but_already_in_the_admin_queue(): void
    {
        Notification::fake();

        $user = $this->inscrire();

        $this->assertSame(StatutCompte::EnAttenteValidation, $user->statut_compte);
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertFalse($user->compteValide());
    }

    public function test_registration_sends_the_verification_email(): void
    {
        Notification::fake();

        $user = $this->inscrire();

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    /**
     * Un compte fraîchement inscrit est déjà en file d'attente (voir le test
     * ci-dessus) : cliquer le lien ne fait donc que marquer l'adresse
     * vérifiée, sans transition de statut à opérer.
     */
    public function test_verifying_the_email_of_a_fresh_account_only_records_the_verification(): void
    {
        Notification::fake();
        $user = $this->inscrire();

        $this->get($this->lienDeVerification($user))->assertRedirect();

        $user->refresh();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame(StatutCompte::EnAttenteValidation, $user->statut_compte);
        $this->assertFalse($user->compteValide());
    }

    /**
     * Chemin hérité : un compte encore au statut `EmailAVerifier` (créé avant
     * l'assouplissement, ou par un futur retour à la vérification
     * obligatoire) doit toujours basculer en file d'attente au clic — la
     * transition elle-même n'a pas changé, seul `register()` ne l'emprunte
     * plus par défaut.
     */
    public function test_verifying_the_email_of_a_legacy_account_still_queues_it(): void
    {
        $user = User::factory()->emailAVerifier()->create();

        $this->get($this->lienDeVerification($user))->assertRedirect();

        $user->refresh();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame(StatutCompte::EnAttenteValidation, $user->statut_compte);
    }

    public function test_an_unsigned_verification_link_is_refused(): void
    {
        Notification::fake();
        $user = $this->inscrire();

        // Sans signature, l'URL est une simple adresse devinable à partir de
        // l'identifiant : le middleware `signed` doit la rejeter.
        $this->get("/api/auth/email/verify/{$user->id}/".sha1($user->email))
            ->assertForbidden();

        $this->assertFalse($user->refresh()->hasVerifiedEmail());
    }

    public function test_a_link_with_the_wrong_hash_is_refused(): void
    {
        Notification::fake();
        $user = $this->inscrire();

        $lien = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1('autre@exemple.sn'),
        ]);

        $this->get($lien)->assertRedirect();
        $this->assertFalse($user->refresh()->hasVerifiedEmail());
    }

    public function test_verification_is_journaled(): void
    {
        Notification::fake();
        $user = $this->inscrire();

        $this->get($this->lienDeVerification($user));

        $this->assertNotNull(Activity::where('event', 'email-verifie')->first());
    }

    public function test_replaying_an_old_link_does_not_reopen_a_validated_account(): void
    {
        Notification::fake();
        $user = $this->inscrire();
        $lien = $this->lienDeVerification($user);

        $this->get($lien);
        // L'administrateur valide entre-temps.
        $user->refresh()->update(['statut_compte' => StatutCompte::Valide]);

        $this->get($lien)->assertRedirect();

        // Le compte doit rester validé : rejouer un vieux lien ne le renvoie
        // pas en file d'attente.
        $this->assertSame(StatutCompte::Valide, $user->refresh()->statut_compte);
    }

    public function test_existing_accounts_are_grandfathered(): void
    {
        // Les comptes du seed (admin, agent) datent d'avant la règle : ils
        // doivent être valides, sinon la migration enfermerait tout le monde.
        $admin = User::where('email', 'admin@cpi.sn')->firstOrFail();

        $this->assertSame(StatutCompte::Valide, $admin->statut_compte);
        $this->assertTrue($admin->compteValide());
    }

    public function test_resending_requires_a_token(): void
    {
        $this->postJson('/api/auth/email/resend')->assertUnauthorized();
    }

    public function test_a_user_can_ask_for_a_new_link(): void
    {
        Notification::fake();
        $user = $this->inscrire();
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/email/resend')->assertOk();

        Notification::assertSentToTimes($user, VerifyEmail::class, 2);   // inscription + renvoi
    }

    public function test_the_account_state_is_exposed_to_the_frontend(): void
    {
        Notification::fake();
        $user = $this->inscrire();
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.statutCompte', StatutCompte::EnAttenteValidation->value)
            ->assertJsonPath('data.user.emailVerifie', false);
    }

    public function test_the_registration_response_already_carries_the_state(): void
    {
        Notification::fake();

        // Le frontend décide de l'écran à afficher à partir de CETTE réponse.
        // `create()` ne relit pas la ligne : sans statut explicite, le défaut
        // SQL n'apparaissait pas ici et la valeur arrivait à null.
        $this->postJson('/api/auth/register', [
            'name' => 'Awa Verif',
            'email' => 'awa@exemple.sn',
            'password' => 'MotDePasse!2026',
        ])
            ->assertCreated()
            ->assertJsonPath('data.user.statutCompte', StatutCompte::EnAttenteValidation->value)
            ->assertJsonPath('data.user.emailVerifie', false);
    }

    public function test_a_staff_account_is_usable_immediately(): void
    {
        $admin = User::where('email', 'admin@cpi.sn')->firstOrFail();

        $this->withToken($admin->createToken('t')->plainTextToken)
            ->postJson('/api/staff/staff/create', [
                'name' => 'Nouvel Agent',
                'email' => 'nouvel.agent@cpi.sn',
                'role' => 'agent-cpi',
            ])->assertCreated();

        $agent = User::where('email', 'nouvel.agent@cpi.sn')->firstOrFail();
        // Un agent créé par l'administrateur ne doit pas attendre d'être validé.
        $this->assertSame(StatutCompte::Valide, $agent->statut_compte);
        $this->assertTrue($agent->hasVerifiedEmail());
    }
}
