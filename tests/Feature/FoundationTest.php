<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tests de fondation (Phase 1) : migrations, uuid, Sanctum, Spatie Permission,
 * Spatie Activity Log et comptes seedés. Aucun endpoint n'existe encore.
 */
class FoundationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run the DatabaseSeeder before each test.
     */
    protected $seed = true;

    public function test_migrate_and_seed_run_green_on_sqlite(): void
    {
        // RefreshDatabase + $seed ont déjà migré et seedé — on vérifie l'état.
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('users', ['email' => 'agent@cpi.sn']);
        $this->assertDatabaseHas('users', ['email' => 'admin@cpi.sn']);
    }

    public function test_users_get_uuid_string_primary_keys(): void
    {
        $user = User::factory()->create();

        // La PK est une chaîne uuid (HasUuids), pas un entier auto-incrémenté.
        $this->assertTrue(Str::isUuid($user->id));
        $this->assertTrue(Str::isUuid($user->getKey()));
    }

    public function test_create_token_works(): void
    {
        $user = User::factory()->create();

        $token = $user->createToken('t');

        $this->assertNotEmpty($token->plainTextToken);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_assign_role_persists_and_has_role_works(): void
    {
        $user = User::factory()->create();

        $user->assignRole('client');

        /** @var User $fresh */
        $fresh = User::query()->findOrFail($user->id);
        $this->assertTrue($fresh->hasRole('client'));
        $this->assertDatabaseHas('model_has_roles', [
            'model_id' => $user->id,
            'model_type' => User::class,
        ]);
    }

    public function test_activity_log_writes_a_row(): void
    {
        $user = User::factory()->create();
        $client = Client::create([
            'user_id' => $user->id,
            'name' => 'Client Test',
            'ref' => 'CPI-2026-TEST',
            'email' => 'client.test@example.com',
            'date_inscription' => now(),
        ]);

        activity()
            ->causedBy($user)
            ->performedOn($client)
            ->log('x');

        $activity = Activity::query()
            ->where('description', 'x')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(Client::class, $activity->subject_type);
        $this->assertSame($client->id, $activity->subject_id);
        $this->assertSame(User::class, $activity->causer_type);
        $this->assertSame($user->id, $activity->causer_id);
    }

    public function test_seeder_creates_staff_accounts_with_correct_roles(): void
    {
        /** @var User $agent */
        $agent = User::query()->where('email', 'agent@cpi.sn')->firstOrFail();
        /** @var User $admin */
        $admin = User::query()->where('email', 'admin@cpi.sn')->firstOrFail();

        $this->assertSame('Agent CPI', $agent->name);
        $this->assertTrue($agent->hasRole('agent-cpi'));

        $this->assertSame('Administrateur CPI', $admin->name);
        $this->assertTrue($admin->hasRole('super-admin'));
    }

    public function test_client_role_has_exact_permission_set(): void
    {
        $expected = [
            'upload-documents', 'view-documents', 'sign-documents',
            'view-cpi-docs', 'sign-cpi-docs',
            'view-banks', 'view-chantier', 'view-notifications',
            'view-own-profile', 'edit-own-profile',
        ];

        $actual = Role::findByName('client')
            ->permissions
            ->pluck('name')
            ->all();

        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }
}
