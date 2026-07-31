<?php

namespace Database\Seeders;

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
        // Run Spatie permission seeder first
        $this->call(RoleAndPermissionSeeder::class);

        // Comptes intégrés — firstOrCreate : le seeder tourne à chaque démarrage
        // du conteneur (docker-entrypoint) sans jamais dupliquer ni écraser.
        $agent = User::firstOrCreate(
            ['email' => 'agent@cpi.sn'],
            ['name' => 'Agent CPI', 'password' => Hash::make('agent1234')],
        );
        if (! $agent->hasRole('agent-cpi')) {
            $agent->assignRole('agent-cpi');
        }

        $admin = User::firstOrCreate(
            ['email' => 'admin@cpi.sn'],
            ['name' => 'Administrateur CPI', 'password' => Hash::make('admin1234')],
        );
        if (! $admin->hasRole('super-admin')) {
            $admin->assignRole('super-admin');
        }
    }
}
