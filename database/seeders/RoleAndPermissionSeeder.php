<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // ─── Permissions ───────────────────────────────────────
        $permissions = [
            // Client management
            'view-clients', 'create-client', 'edit-client', 'delete-client',
            // Documents
            'view-documents', 'validate-documents', 'manage-documents',
            'sign-documents', 'upload-documents',
            // CPI Docs
            'view-cpi-docs', 'create-cpi-docs', 'publish-cpi-docs',
            'archive-cpi-docs', 'sign-cpi-docs',
            // Banks
            'view-banks', 'create-bank', 'edit-bank', 'delete-bank',
            'assign-bank',
            // Disbursements
            'view-decaissements', 'manage-decaissements',
            // Chantier
            'view-chantier', 'manage-chantier',
            // Notifications
            'send-notifications', 'view-notifications',
            // System
            'manage-staff', 'view-stats', 'manage-demo-data',
            // Own profile
            'view-own-profile', 'edit-own-profile',
        ];

        // firstOrCreate : le seeder est idempotent — il tourne à chaque démarrage
        // du conteneur (docker-entrypoint), pas seulement après migrate:fresh.
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // ─── Roles ─────────────────────────────────────────────
        $superAdmin = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $superAdmin->syncPermissions(Permission::all());

        $agentCpi = Role::firstOrCreate(['name' => 'agent-cpi', 'guard_name' => 'web']);
        $agentCpi->syncPermissions([
            'view-clients', 'create-client', 'edit-client',
            'view-documents', 'validate-documents', 'manage-documents',
            'view-cpi-docs', 'create-cpi-docs', 'publish-cpi-docs',
            'archive-cpi-docs', 'sign-cpi-docs',
            'view-banks', 'assign-bank',
            'view-decaissements', 'manage-decaissements',
            'view-chantier', 'manage-chantier',
            'send-notifications', 'view-notifications',
            'view-own-profile', 'edit-own-profile',
        ]);

        $client = Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
        $client->syncPermissions([
            'upload-documents', 'view-documents', 'sign-documents',
            'view-cpi-docs', 'sign-cpi-docs',
            'view-banks', 'view-chantier', 'view-notifications',
            'view-own-profile', 'edit-own-profile',
        ]);

        // Every user gets exactly ONE Spatie role: client / agent-cpi / super-admin.
        // /auth/me returns getRoleNames()->first() + getAllPermissions()->pluck('name')
        // — the frontend's single source of truth for RBAC.
        // Ownership rules ("a client only sees THEIR dossier") remain Policy checks, not permissions.
    }
}
