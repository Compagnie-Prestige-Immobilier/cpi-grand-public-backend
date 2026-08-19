<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Notifications applicatives (Phase 6). Le client lit et marque lue SA boîte ;
 * le personnel CPI lit le flux complet et est seul à pouvoir émettre.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

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
     * Le guard mémorise l'utilisateur résolu entre deux requêtes d'un même
     * test : on l'oublie pour forcer la ré-authentification du token.
     */
    private function forgetAuthState(): void
    {
        auth()->forgetGuards();
    }

    private function makeNotification(Client $client, string $titre = 'Pièce validée'): Notification
    {
        return Notification::create([
            'client_id' => $client->id,
            'user_id' => $client->user_id,
            'titre' => $titre,
            'message' => 'Votre pièce a été validée.',
            'type' => 'validation',
            'date' => now(),
            'heure' => now()->format('H:i'),
            'lu' => false,
        ])->refresh();
    }

    // ─── Lecture client ───────────────────────────────────────

    public function test_mine_returns_the_callers_notifications_newest_first(): void
    {
        [, $client, $token] = $this->makeClientUser();
        $ancienne = $this->makeNotification($client, 'Ancienne');
        $ancienne->update(['date' => now()->subDays(2)]);
        $this->makeNotification($client, 'Récente');

        $this->withToken($token)->getJson('/api/client/notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.titre', 'Récente')
            ->assertJsonPath('data.1.titre', 'Ancienne')
            ->assertJsonPath('data.0.lu', false)
            ->assertJsonPath('data.0.clientId', $client->id);
    }

    public function test_mine_returns_an_empty_list_when_the_box_is_empty(): void
    {
        [, , $token] = $this->makeClientUser();

        $this->withToken($token)->getJson('/api/client/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** Cœur du sujet : la boîte d'un client ne contient jamais celle d'un autre. */
    public function test_client_a_never_sees_client_b_notifications(): void
    {
        [, , $tokenA] = $this->makeClientUser();
        [, $clientB] = $this->makeClientUser();
        $notifB = $this->makeNotification($clientB, 'Secret de B');

        $response = $this->withToken($tokenA)->getJson('/api/client/notifications');

        $response->assertOk()->assertJsonCount(0, 'data');
        $this->assertStringNotContainsString($notifB->id, $response->getContent() ?: '');
        $this->assertStringNotContainsString('Secret de B', $response->getContent() ?: '');
    }

    // ─── Marquage comme lue ───────────────────────────────────

    public function test_mark_read_flags_the_notification(): void
    {
        [, $client, $token] = $this->makeClientUser();
        $notif = $this->makeNotification($client);

        $this->withToken($token)->postJson('/api/client/notifications/'.$notif->id.'/read')
            ->assertOk()
            ->assertJsonPath('data.id', $notif->id)
            ->assertJsonPath('data.lu', true);

        $this->assertTrue($notif->refresh()->lu);
    }

    /** Un client ne marque pas lue la notification d'un autre dossier. */
    public function test_client_a_cannot_mark_client_b_notification_as_read(): void
    {
        [, , $tokenA] = $this->makeClientUser();
        [, $clientB] = $this->makeClientUser();
        $notifB = $this->makeNotification($clientB);

        $this->withToken($tokenA)->postJson('/api/client/notifications/'.$notifB->id.'/read')
            ->assertStatus(403);

        $this->assertFalse($notifB->refresh()->lu);
    }

    public function test_mark_read_on_an_unknown_id_returns_404(): void
    {
        [, , $token] = $this->makeClientUser();

        $this->withToken($token)
            ->postJson('/api/client/notifications/019fb302-0098-715f-a528-56360e84eb74/read')
            ->assertStatus(404);
    }

    // ─── Flux du personnel ────────────────────────────────────

    public function test_staff_index_lists_every_notification(): void
    {
        [, $clientA] = $this->makeClientUser();
        [, $clientB] = $this->makeClientUser();
        $this->makeNotification($clientA, 'Pour A');
        $this->makeNotification($clientB, 'Pour B');

        $this->withToken($this->agentToken())->getJson('/api/staff/notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['titre' => 'Pour A'])
            ->assertJsonFragment(['titre' => 'Pour B']);
    }

    public function test_send_creates_the_notification_and_logs_the_activity(): void
    {
        [, $client] = $this->makeClientUser();

        $this->withToken($this->agentToken())->postJson('/api/staff/notifications/send', [
            'client_id' => $client->id,
            'titre' => 'Rendez-vous en agence',
            'message' => 'Merci de passer signer votre convention.',
            'type' => 'action',
            'target_page' => 'mes-documents',
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.titre', 'Rendez-vous en agence')
            ->assertJsonPath('data.clientId', $client->id)
            ->assertJsonPath('data.userId', $client->user_id)
            ->assertJsonPath('data.lu', false)
            ->assertJsonPath('data.targetPage', 'mes-documents');

        $this->assertDatabaseHas('app_notifications', [
            'client_id' => $client->id,
            'titre' => 'Rendez-vous en agence',
            'lu' => false,
        ]);

        $activity = Activity::query()->where('event', 'notification-envoyee')->first();
        $this->assertNotNull($activity);
        $this->assertSame($client->id, $activity->subject_id);
        $this->assertSame('Rendez-vous en agence', $activity->getExtraProperty('titre'));
    }

    public function test_the_notification_sent_by_the_staff_lands_in_the_clients_box(): void
    {
        [, $client, $token] = $this->makeClientUser();

        $this->withToken($this->agentToken())->postJson('/api/staff/notifications/send', [
            'client_id' => $client->id,
            'titre' => 'Dossier complet',
            'message' => 'Toutes vos pièces sont conformes.',
            'type' => 'info',
        ])->assertStatus(201);

        $this->forgetAuthState();
        $this->withToken($token)->getJson('/api/client/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.titre', 'Dossier complet');
    }

    public function test_send_rejects_an_incomplete_or_unknown_payload(): void
    {
        [, $client] = $this->makeClientUser();
        $token = $this->agentToken();

        $this->withToken($token)->postJson('/api/staff/notifications/send', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_id', 'titre', 'message', 'type']);

        $this->withToken($token)->postJson('/api/staff/notifications/send', [
            'client_id' => '019fb302-0098-715f-a528-56360e84eb74',
            'titre' => 'X', 'message' => 'Y', 'type' => 'info',
        ])->assertStatus(422)->assertJsonValidationErrors(['client_id']);

        $this->assertDatabaseCount('app_notifications', 0);
        $this->assertSame($client->id, Client::query()->firstOrFail()->id);
    }

    public function test_admin_can_also_read_and_send(): void
    {
        [, $client] = $this->makeClientUser();

        $this->withToken($this->adminToken())->postJson('/api/staff/notifications/send', [
            'client_id' => $client->id,
            'titre' => 'Note de la direction',
            'message' => 'Bienvenue.',
            'type' => 'info',
        ])->assertStatus(201);

        $this->withToken($this->adminToken())->getJson('/api/staff/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ─── Séparation des rôles ─────────────────────────────────

    public function test_client_token_is_refused_on_every_staff_notification_route(): void
    {
        [, $client, $token] = $this->makeClientUser();

        $this->withToken($token)->getJson('/api/staff/notifications')->assertStatus(403);
        $this->withToken($token)->postJson('/api/staff/notifications/send', [
            'client_id' => $client->id, 'titre' => 'Pirate', 'message' => 'X', 'type' => 'info',
        ])->assertStatus(403);

        $this->assertDatabaseCount('app_notifications', 0);
    }

    public function test_staff_token_is_refused_on_the_client_notification_routes(): void
    {
        [, $client] = $this->makeClientUser();
        $notif = $this->makeNotification($client);

        $this->withToken($this->agentToken())->getJson('/api/client/notifications')->assertStatus(403);
        $this->forgetAuthState();
        $this->withToken($this->adminToken())->getJson('/api/client/notifications')->assertStatus(403);
        $this->forgetAuthState();
        $this->withToken($this->agentToken())
            ->postJson('/api/client/notifications/'.$notif->id.'/read')->assertStatus(403);

        $this->assertFalse($notif->refresh()->lu);
    }

    public function test_notification_routes_require_authentication(): void
    {
        [, $client] = $this->makeClientUser();
        $notif = $this->makeNotification($client);

        $this->getJson('/api/client/notifications')->assertStatus(401);
        $this->postJson('/api/client/notifications/'.$notif->id.'/read')->assertStatus(401);
        $this->getJson('/api/staff/notifications')->assertStatus(401);
        $this->postJson('/api/staff/notifications/send', [])->assertStatus(401);
    }

    /** Un compte client sans dossier ne fait pas 500. */
    public function test_mine_returns_404_when_the_account_has_no_dossier(): void
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        $this->withToken($user->createToken('t')->plainTextToken)
            ->getJson('/api/client/notifications')
            ->assertStatus(404);
    }
}
