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

        // Create built-in staff accounts
        $agent = User::create([
            'name' => 'Agent CPI',
            'email' => 'agent@cpi.sn',
            'password' => Hash::make('agent1234'),
        ]);
        $agent->assignRole('agent-cpi');

        $admin = User::create([
            'name' => 'Administrateur CPI',
            'email' => 'admin@cpi.sn',
            'password' => Hash::make('admin1234'),
        ]);
        $admin->assignRole('super-admin');
    }
}
