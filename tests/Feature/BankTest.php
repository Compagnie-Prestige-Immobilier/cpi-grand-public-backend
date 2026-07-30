<?php

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\BankAssignment;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Banques partenaires (Phase 4) : registre géré par l'administrateur,
 * orientation des dossiers par le personnel, lecture seule côté client.
 */
class BankTest extends TestCase
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

    private function makeBank(string $name = 'CBAO'): Bank
    {
        return Bank::create(['name' => $name])->refresh();
    }

    // ─── Registre : lecture ───────────────────────────────────

    public function test_staff_lists_banks_with_their_assignments(): void
    {
        $bank = $this->makeBank('BOA Sénégal');
        [, $client] = $this->makeClientUser();
        BankAssignment::create(['client_id' => $client->id, 'bank_id' => $bank->id, 'status' => 'accord']);

        $response = $this->withToken($this->agentToken())->getJson('/api/staff/banks');

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'BOA Sénégal')
            ->assertJsonPath('data.0.color', '#1E4D8C')
            ->assertJsonPath('data.0.assignments.0.clientId', $client->id)
            ->assertJsonPath('data.0.assignments.0.bankName', 'BOA Sénégal')
            ->assertJsonPath('data.0.assignments.0.status', 'accord');
    }

    // ─── Registre : écriture (administrateur) ─────────────────

    public function test_admin_creates_a_bank(): void
    {
        $response = $this->withToken($this->adminToken())->postJson('/api/staff/banks', [
            'name' => 'Banque Atlantique',
            'convention_date' => '25 juillet 2026',
            'validity' => '25 juillet 2028',
            'products' => ['Fonctionnaire', 'Standard'],
            'rate' => '8,50%',
            'contact' => '+221 33 000 00 00',
            'color' => '#1A6B44',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Banque Atlantique')
            ->assertJsonPath('data.products', ['Fonctionnaire', 'Standard'])
            ->assertJsonPath('data.color', '#1A6B44')
            ->assertJsonPath('data.assignments', []);

        $this->assertDatabaseHas('banks', ['name' => 'Banque Atlantique']);
        $this->assertNotNull(Activity::query()->where('event', 'banque-creee')->first());
    }

    public function test_bank_creation_falls_back_to_the_database_default_color(): void
    {
        $this->withToken($this->adminToken())->postJson('/api/staff/banks', ['name' => 'Sans couleur'])
            ->assertCreated()
            ->assertJsonPath('data.color', '#1E4D8C');
    }

    public function test_bank_creation_requires_a_name(): void
    {
        $this->withToken($this->adminToken())->postJson('/api/staff/banks', [])->assertStatus(422);
    }

    public function test_admin_updates_a_bank(): void
    {
        $bank = $this->makeBank();

        $this->withToken($this->adminToken())->putJson('/api/staff/banks/'.$bank->id, [
            'rate' => '9,00%',
            'validity' => '31 décembre 2027',
        ])
            ->assertOk()
            ->assertJsonPath('data.rate', '9,00%')
            ->assertJsonPath('data.validity', '31 décembre 2027');

        $this->assertNotNull(Activity::query()->where('event', 'banque-modifiee')->first());
    }

    public function test_admin_deletes_a_bank_and_its_assignments(): void
    {
        $bank = $this->makeBank();
        [, $client] = $this->makeClientUser();
        BankAssignment::create(['client_id' => $client->id, 'bank_id' => $bank->id, 'status' => 'en-attente']);

        $this->withToken($this->adminToken())->deleteJson('/api/staff/banks/'.$bank->id)
            ->assertOk()
            ->assertJsonPath('message', 'Banque retirée du registre.');

        $this->assertDatabaseMissing('banks', ['id' => $bank->id]);
        $this->assertDatabaseMissing('bank_assignments', ['bank_id' => $bank->id]);
        $this->assertNotNull(Activity::query()->where('event', 'banque-supprimee')->first());
    }

    /**
     * L'agent CPI ne détient ni create-bank, ni edit-bank, ni delete-bank :
     * le registre des conventions est un acte d'administration.
     */
    public function test_agent_cannot_manage_the_bank_registry(): void
    {
        $bank = $this->makeBank();
        $token = $this->agentToken();

        $this->withToken($token)->postJson('/api/staff/banks', ['name' => 'Interdite'])->assertStatus(403);
        $this->withToken($token)->putJson('/api/staff/banks/'.$bank->id, ['name' => 'X'])->assertStatus(403);
        $this->withToken($token)->deleteJson('/api/staff/banks/'.$bank->id)->assertStatus(403);
    }

    // ─── Orientation des dossiers ─────────────────────────────

    public function test_agent_assigns_a_bank_to_a_client(): void
    {
        $bank = $this->makeBank();
        [, $client] = $this->makeClientUser();

        $this->withToken($this->agentToken())
            ->postJson("/api/staff/clients/{$client->id}/banks/{$bank->id}/assign")
            ->assertCreated()
            ->assertJsonPath('data.clientId', $client->id)
            ->assertJsonPath('data.bankId', $bank->id)
            ->assertJsonPath('data.bankName', 'CBAO')
            ->assertJsonPath('data.status', 'en-attente');

        $this->assertDatabaseHas('bank_assignments', [
            'client_id' => $client->id,
            'bank_id' => $bank->id,
            'status' => 'en-attente',
        ]);
        $this->assertNotNull(Activity::query()->where('event', 'banque-assignee')->first());
    }

    public function test_assign_is_idempotent_and_keeps_the_acquired_status(): void
    {
        $bank = $this->makeBank();
        [, $client] = $this->makeClientUser();
        $token = $this->agentToken();

        $this->withToken($token)->postJson("/api/staff/clients/{$client->id}/banks/{$bank->id}/assign")->assertCreated();
        $this->withToken($token)->postJson("/api/staff/clients/{$client->id}/banks/{$bank->id}/status", ['status' => 'accord'])->assertOk();

        // Ré-orienter ne duplique rien et n'écrase pas la réponse de la banque.
        $this->withToken($token)->postJson("/api/staff/clients/{$client->id}/banks/{$bank->id}/assign")
            ->assertOk()
            ->assertJsonPath('data.status', 'accord');

        $this->assertSame(1, BankAssignment::query()->count());
    }

    public function test_set_status_updates_and_logs(): void
    {
        $bank = $this->makeBank();
        [, $client] = $this->makeClientUser();
        BankAssignment::create(['client_id' => $client->id, 'bank_id' => $bank->id, 'status' => 'en-attente']);

        $this->withToken($this->agentToken())
            ->postJson("/api/staff/clients/{$client->id}/banks/{$bank->id}/status", ['status' => 'refus'])
            ->assertOk()
            ->assertJsonPath('data.status', 'refus');

        $activity = Activity::query()->where('event', 'banque-statut')->first();
        $this->assertNotNull($activity);
        $this->assertSame('refus', $activity->getExtraProperty('status'));
    }

    public function test_set_status_rejects_an_unknown_status(): void
    {
        $bank = $this->makeBank();
        [, $client] = $this->makeClientUser();
        BankAssignment::create(['client_id' => $client->id, 'bank_id' => $bank->id, 'status' => 'en-attente']);

        $this->withToken($this->agentToken())
            ->postJson("/api/staff/clients/{$client->id}/banks/{$bank->id}/status", ['status' => 'peut-etre'])
            ->assertStatus(422);
    }

    public function test_set_status_on_a_missing_assignment_returns_404(): void
    {
        $bank = $this->makeBank();
        [, $client] = $this->makeClientUser();

        $this->withToken($this->agentToken())
            ->postJson("/api/staff/clients/{$client->id}/banks/{$bank->id}/status", ['status' => 'accord'])
            ->assertStatus(404);
    }

    public function test_remove_assignment_deletes_the_row_and_logs(): void
    {
        $bank = $this->makeBank();
        [, $client] = $this->makeClientUser();
        BankAssignment::create(['client_id' => $client->id, 'bank_id' => $bank->id, 'status' => 'accord']);

        $this->withToken($this->agentToken())
            ->deleteJson("/api/staff/clients/{$client->id}/banks/{$bank->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Orientation bancaire retirée.');

        $this->assertDatabaseMissing('bank_assignments', [
            'client_id' => $client->id,
            'bank_id' => $bank->id,
        ]);
        $this->assertNotNull(Activity::query()->where('event', 'banque-retiree')->first());
    }

    public function test_remove_a_missing_assignment_returns_404(): void
    {
        $bank = $this->makeBank();
        [, $client] = $this->makeClientUser();

        $this->withToken($this->agentToken())
            ->deleteJson("/api/staff/clients/{$client->id}/banks/{$bank->id}")
            ->assertStatus(404);
    }

    // ─── Espace client ────────────────────────────────────────

    public function test_client_sees_only_their_own_assignments(): void
    {
        $bank = $this->makeBank('CBAO');
        $other = $this->makeBank('Ecobank');
        [, $clientA, $tokenA] = $this->makeClientUser();
        [, $clientB] = $this->makeClientUser();

        BankAssignment::create(['client_id' => $clientA->id, 'bank_id' => $bank->id, 'status' => 'accord']);
        BankAssignment::create(['client_id' => $clientB->id, 'bank_id' => $other->id, 'status' => 'refus']);

        $response = $this->withToken($tokenA)->getJson('/api/client/mes-banques');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($clientA->id, $data[0]['clientId']);
        $this->assertSame('CBAO', $data[0]['bankName']);
        $this->assertSame('accord', $data[0]['status']);
    }

    public function test_client_with_no_assignment_gets_an_empty_list(): void
    {
        [, , $token] = $this->makeClientUser();

        $this->withToken($token)->getJson('/api/client/mes-banques')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    // ─── Séparation des rôles ─────────────────────────────────

    public function test_client_token_is_refused_on_every_staff_bank_route(): void
    {
        $bank = $this->makeBank();
        [, $clientA, $tokenA] = $this->makeClientUser();
        [, $clientB] = $this->makeClientUser();
        BankAssignment::create(['client_id' => $clientB->id, 'bank_id' => $bank->id, 'status' => 'accord']);

        $this->withToken($tokenA)->getJson('/api/staff/banks')->assertStatus(403);
        $this->withToken($tokenA)->postJson('/api/staff/banks', ['name' => 'X'])->assertStatus(403);
        $this->withToken($tokenA)->putJson('/api/staff/banks/'.$bank->id, ['name' => 'X'])->assertStatus(403);
        $this->withToken($tokenA)->deleteJson('/api/staff/banks/'.$bank->id)->assertStatus(403);
        $this->withToken($tokenA)->postJson("/api/staff/clients/{$clientA->id}/banks/{$bank->id}/assign")->assertStatus(403);
        $this->withToken($tokenA)->postJson("/api/staff/clients/{$clientA->id}/banks/{$bank->id}/status", ['status' => 'accord'])->assertStatus(403);
        $this->withToken($tokenA)->deleteJson("/api/staff/clients/{$clientA->id}/banks/{$bank->id}")->assertStatus(403);
    }

    /** Un client ne peut ni lire ni modifier les orientations d'un autre dossier. */
    public function test_client_a_cannot_touch_client_b_assignments(): void
    {
        $bank = $this->makeBank();
        [, , $tokenA] = $this->makeClientUser();
        [, $clientB] = $this->makeClientUser();
        BankAssignment::create(['client_id' => $clientB->id, 'bank_id' => $bank->id, 'status' => 'accord']);

        // Aucune route client ne prend d'identifiant de dossier : la seule
        // lecture possible est la sienne, et elle est vide.
        $this->withToken($tokenA)->getJson('/api/client/mes-banques')
            ->assertOk()
            ->assertJsonPath('data', []);

        // Les routes staff (les seules à porter un {client}) lui sont fermées.
        $this->withToken($tokenA)->postJson("/api/staff/clients/{$clientB->id}/banks/{$bank->id}/status", ['status' => 'refus'])
            ->assertStatus(403);
        $this->withToken($tokenA)->deleteJson("/api/staff/clients/{$clientB->id}/banks/{$bank->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('bank_assignments', [
            'client_id' => $clientB->id,
            'bank_id' => $bank->id,
            'status' => 'accord',
        ]);
    }

    public function test_staff_token_is_refused_on_the_client_route(): void
    {
        $this->withToken($this->agentToken())->getJson('/api/client/mes-banques')->assertStatus(403);
        $this->withToken($this->adminToken())->getJson('/api/client/mes-banques')->assertStatus(403);
    }

    public function test_bank_routes_require_authentication(): void
    {
        $bank = $this->makeBank();
        [, $client] = $this->makeClientUser();

        $this->getJson('/api/client/mes-banques')->assertStatus(401);
        $this->getJson('/api/staff/banks')->assertStatus(401);
        $this->postJson('/api/staff/banks', ['name' => 'X'])->assertStatus(401);
        $this->putJson('/api/staff/banks/'.$bank->id, ['name' => 'X'])->assertStatus(401);
        $this->deleteJson('/api/staff/banks/'.$bank->id)->assertStatus(401);
        $this->postJson("/api/staff/clients/{$client->id}/banks/{$bank->id}/assign")->assertStatus(401);
        $this->postJson("/api/staff/clients/{$client->id}/banks/{$bank->id}/status", ['status' => 'accord'])->assertStatus(401);
        $this->deleteJson("/api/staff/clients/{$client->id}/banks/{$bank->id}")->assertStatus(401);
    }
}
