<?php

namespace Tests\Feature;

use App\Dto\ClientData;
use App\Dto\UserData;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Régressions Gate 1 :
 *  (a) la sortie des DTOs est en camelCase (MapInputName uniquement — pas MapName) ;
 *  (b) ClientData accepte un client minimal type Google OAuth (colonnes nullables à null).
 */
class DtoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run the DatabaseSeeder before each test.
     */
    protected bool $seed = true;

    public function test_user_data_output_keys_are_camel_case(): void
    {
        $agent = User::query()->where('email', 'agent@cpi.sn')->firstOrFail();

        $payload = UserData::from($agent)->toArray();

        $this->assertArrayHasKey('needsOnboarding', $payload);
        $this->assertArrayHasKey('profileType', $payload);
        $this->assertArrayNotHasKey('needs_onboarding', $payload);
        $this->assertArrayNotHasKey('profile_type', $payload);
        $this->assertSame('agent-cpi', $payload['role']);
        $this->assertContains('view-clients', $payload['permissions']);
    }

    public function test_client_data_output_keys_are_camel_case(): void
    {
        $client = Client::create([
            'name' => 'Client Complet',
            'ref' => 'CPI-2026-FULL',
            'project_nom' => 'Villa Almadies',
            'dossier_etape' => 2,
            'date_inscription' => now(),
        ])->refresh();

        $payload = ClientData::from($client)->toArray();

        $this->assertArrayHasKey('projectNom', $payload);
        $this->assertArrayHasKey('dossierEtape', $payload);
        $this->assertArrayHasKey('dateInscription', $payload);
        $this->assertArrayNotHasKey('project_nom', $payload);
        $this->assertArrayNotHasKey('dossier_etape', $payload);
        $this->assertArrayNotHasKey('date_inscription', $payload);
        $this->assertSame('Villa Almadies', $payload['projectNom']);
        $this->assertSame(2, $payload['dossierEtape']);
    }

    public function test_client_data_accepts_minimal_google_oauth_client(): void
    {
        $user = User::factory()->create();

        // Le flux Google OAuth (STEP 7.2) crée exactement ce client minimal.
        // refresh() hydrate les colonnes remplies par les défauts SQL (statut, progression, dossier_etape).
        $client = Client::create([
            'user_id' => $user->id,
            'name' => 'Google User',
            'ref' => 'CPI-2026-GOOG',
            'email' => 'google.user@example.com',
            'date_inscription' => now(),
        ])->refresh();

        $data = ClientData::from($client);

        $this->assertSame($client->id, $data->id);
        $this->assertSame('Google User', $data->name);
        $this->assertNull($data->projectNom);
        $this->assertNull($data->phone);
        $this->assertNull($data->banque);
        $this->assertSame('Dossier en préparation', $data->statut);
        $this->assertSame(0, $data->progression);
        $this->assertSame(0, $data->dossierEtape);
        $this->assertNotNull($data->dateInscription);
    }
}
