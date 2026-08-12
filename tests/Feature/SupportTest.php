<?php

namespace Tests\Feature;

use App\Mail\SupportMessage;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class SupportTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    /** @return array{0: User, 1: Client, 2: string} */
    private function clientConnecte(): array
    {
        $user = User::factory()->create();
        $user->assignRole('client');
        $client = Client::create([
            'user_id' => $user->id,
            'name' => $user->name,
            'ref' => Client::generateRef(),
            'email' => $user->email,
            'phone' => '+221770000000',
            'date_inscription' => now(),
        ])->refresh();

        return [$user, $client, $user->createToken('t')->plainTextToken];
    }

    public function test_a_support_request_is_actually_sent(): void
    {
        Mail::fake();
        [$user, $client, $token] = $this->clientConnecte();

        $this->withToken($token)->postJson('/api/client/support', [
            'sujet' => 'Problème de dépôt',
            'message' => "Je n'arrive pas à envoyer ma pièce d'identité.",
        ])->assertOk();

        // Le cœur du correctif : un message part réellement. L'ancien formulaire
        // affichait « Ticket envoyé ! » sans rien émettre.
        //
        // `assertQueued` et non `assertSent` : le Mailable implémente désormais
        // ShouldQueue, pour que la lenteur ou la panne du serveur SMTP ne fasse
        // plus traîner puis échouer la requête HTTP du client. Avec
        // QUEUE_CONNECTION=sync le message part quand même immédiatement.
        Mail::assertQueued(SupportMessage::class, function (SupportMessage $mail) use ($client, $user) {
            // Sujet et adresse de réponse sont portés par l'enveloppe, pas par
            // des propriétés du Mailable : les lire ailleurs donne null.
            $enveloppe = $mail->envelope();

            return $mail->hasTo(config('mail.support_address'))
                && str_contains($enveloppe->subject, $client->ref)
                && collect($enveloppe->replyTo)->contains(fn ($a) => $a->address === $user->email);
        });
    }

    public function test_the_request_is_journaled(): void
    {
        Mail::fake();
        [, , $token] = $this->clientConnecte();

        $this->withToken($token)->postJson('/api/client/support', [
            'sujet' => 'Question sur mon dossier',
            'message' => 'Quand aurai-je une réponse ?',
        ])->assertOk();

        $this->assertNotNull(Activity::where('event', 'support-demande')->first());
    }

    public function test_subject_and_message_are_required(): void
    {
        Mail::fake();
        [, , $token] = $this->clientConnecte();

        $this->withToken($token)->postJson('/api/client/support', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sujet', 'message']);

        Mail::assertNothingSent();
    }

    public function test_support_requires_authentication(): void
    {
        $this->postJson('/api/client/support', ['sujet' => 'x', 'message' => 'y'])
            ->assertUnauthorized();
    }

    public function test_staff_cannot_use_the_client_support_form(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole('agent-cpi');

        $this->withToken($agent->createToken('t')->plainTextToken)
            ->postJson('/api/client/support', ['sujet' => 'x', 'message' => 'y'])
            ->assertForbidden();
    }

    public function test_a_mail_failure_returns_a_clear_message_not_a_500(): void
    {
        // La configuration mail peut être en panne (elle l'était : MAIL_MAILER
        // valait « smtps », qui n'est pas un mailer). Le client doit alors
        // recevoir une consigne utile, pas une erreur serveur muette.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP indisponible'));
        [, , $token] = $this->clientConnecte();

        $this->withToken($token)->postJson('/api/client/support', [
            'sujet' => 'Test',
            'message' => 'Message',
        ])->assertStatus(503);
    }
}
