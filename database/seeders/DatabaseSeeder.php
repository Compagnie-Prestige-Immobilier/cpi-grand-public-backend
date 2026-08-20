<?php

namespace Database\Seeders;

use App\Enums\StatutCompte;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Rôles et permissions : indispensables dans TOUS les environnements,
        // production comprise — sans eux personne ne peut se connecter. Ce
        // seeder ne crée aucun compte.
        $this->call(RoleAndPermissionSeeder::class);

        // Comptes de démonstration : JAMAIS en production.
        //
        // Ils étaient créés inconditionnellement, avec des identifiants publiés
        // en clair dans le dépôt (admin@cpi.sn / admin1234, super-admin, toutes
        // les permissions), et `docker-entrypoint.sh` exécute `db:seed` à chaque
        // démarrage du conteneur qui sert le trafic. N'importe qui ayant lu ce
        // fichier pouvait prendre le contrôle total de la plateforme.
        //
        // En production, le compte d'amorçage se crée hors du code source :
        //   php artisan cpi:create-admin admin@cpi.sn
        if (app()->isProduction()) {
            $this->command->info('Environnement de production : comptes de démonstration ignorés.');
            $this->command->info('Créer le compte d\'amorçage avec `php artisan cpi:create-admin <email>`.');

            return;
        }

        $agent = User::firstOrCreate(
            ['email' => 'agent@cpi.sn'],
            // Un compte du personnel est créé par un administrateur : le faire
            // passer par la file de validation n'aurait aucun sens, et le
            // laisser au statut par défaut le bloquerait sur une plateforme
            // vierge.
            ['name' => 'Agent CPI', 'password' => Hash::make('agent1234'),
                'email_verified_at' => now(), 'statut_compte' => StatutCompte::Valide],
        );
        if (! $agent->hasRole('agent-cpi')) {
            $agent->assignRole('agent-cpi');
        }

        $admin = User::firstOrCreate(
            ['email' => 'admin@cpi.sn'],
            ['name' => 'Administrateur CPI', 'password' => Hash::make('admin1234'),
                'email_verified_at' => now(), 'statut_compte' => StatutCompte::Valide],
        );
        if (! $admin->hasRole('super-admin')) {
            $admin->assignRole('super-admin');
        }
    }
}
