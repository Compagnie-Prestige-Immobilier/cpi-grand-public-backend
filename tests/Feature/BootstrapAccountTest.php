<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le seeder créait `admin@cpi.sn` / `admin1234` (super-admin, toutes les
 * permissions) sans aucune garde d'environnement, et `docker-entrypoint.sh`
 * l'exécute à chaque démarrage du conteneur qui sert le trafic. Les
 * identifiants étaient publiés en clair dans le dépôt.
 */
class BootstrapAccountTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Deux tests basculent l'application en `production` pour éprouver la garde
     * du seeder. Sans cette restauration, l'environnement fuit vers les tests
     * suivants du même processus : leur `$seed = true` appelle `db:seed` sans
     * `--force`, la commande demande confirmation, n'écrit rien, et ils
     * échouent sur des rôles absents — un faux positif difficile à lire.
     */
    protected function tearDown(): void
    {
        app()['env'] = 'testing';

        parent::tearDown();
    }

    public function test_demo_accounts_are_never_created_in_production(): void
    {
        // Partir d'une base sans compte de démonstration, quel que soit ce que
        // les tests précédents ont laissé : ce qu'on vérifie, c'est que le
        // seeder ne les RECRÉE pas.
        User::whereIn('email', ['admin@cpi.sn', 'agent@cpi.sn'])->delete();

        app()['env'] = 'production';

        // `--force` : en production, `db:seed` demande confirmation.
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['email' => 'admin@cpi.sn']);
        $this->assertDatabaseMissing('users', ['email' => 'agent@cpi.sn']);
    }

    public function test_roles_and_permissions_are_still_seeded_in_production(): void
    {
        // Sans eux, personne ne peut se connecter — y compris le compte
        // d'amorçage créé ensuite à la main.
        app()['env'] = 'production';

        // `--force` : en production, `db:seed` demande confirmation.
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('roles', ['name' => 'super-admin']);
        $this->assertDatabaseHas('roles', ['name' => 'agent-cpi']);
        $this->assertDatabaseHas('roles', ['name' => 'client']);
        $this->assertDatabaseHas('permissions', ['name' => 'manage-staff']);
    }

    public function test_demo_accounts_are_still_created_outside_production(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'admin@cpi.sn']);
        $this->assertDatabaseHas('users', ['email' => 'agent@cpi.sn']);
    }

    public function test_the_bootstrap_command_creates_an_admin_with_a_generated_password(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('cpi:create-admin', ['email' => 'dsi@cpi.sn', '--name' => 'DSI CPI'])
            ->assertSuccessful();

        $user = User::where('email', 'dsi@cpi.sn')->firstOrFail();
        $this->assertTrue($user->hasRole('super-admin'));
        $this->assertSame('DSI CPI', $user->name);
        // Le mot de passe n'est ni vide, ni une valeur connue du dépôt.
        $this->assertNotEmpty($user->password);
        $this->assertFalse(password_verify('admin1234', $user->password));
    }

    public function test_the_bootstrap_command_refuses_to_overwrite_without_reset(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->artisan('cpi:create-admin', ['email' => 'dsi@cpi.sn'])->assertSuccessful();

        $avant = User::where('email', 'dsi@cpi.sn')->firstOrFail()->password;

        $this->artisan('cpi:create-admin', ['email' => 'dsi@cpi.sn'])->assertFailed();

        $this->assertSame($avant, User::where('email', 'dsi@cpi.sn')->firstOrFail()->password);
    }

    public function test_the_bootstrap_command_regenerates_the_password_with_reset(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->artisan('cpi:create-admin', ['email' => 'dsi@cpi.sn'])->assertSuccessful();
        $avant = User::where('email', 'dsi@cpi.sn')->firstOrFail()->password;

        $this->artisan('cpi:create-admin', ['email' => 'dsi@cpi.sn', '--reset' => true])
            ->assertSuccessful();

        $this->assertNotSame($avant, User::where('email', 'dsi@cpi.sn')->firstOrFail()->password);
    }

    public function test_the_bootstrap_command_rejects_an_unknown_role(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('cpi:create-admin', ['email' => 'x@cpi.sn', '--role' => 'root'])
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'x@cpi.sn']);
    }
}
