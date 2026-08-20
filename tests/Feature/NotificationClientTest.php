<?php

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\Client;
use App\Models\CpiDoc;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 49 événements étaient journalisés ; TROIS produisaient une notification. Le
 * client n'était pas prévenu quand un contrat attendait sa signature, quand la
 * banque rendait sa réponse, quand de l'argent était versé, ni quand son
 * dossier changeait d'étape. Il devait ouvrir l'application et deviner ce qui
 * avait bougé.
 */
class NotificationClientTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function agent(): User
    {
        /** @var User $agent */
        $agent = User::query()->where('email', 'agent@cpi.sn')->firstOrFail();

        return $agent;
    }

    /**
     * `conseiller_id` par défaut à l'agent seedé : ces tests portent sur LA
     * NOTIFICATION envoyée par une mutation, pas sur le cloisonnement — sous
     * le cloisonnement strict, l'agent doit pouvoir atteindre le dossier pour
     * que la mutation ait seulement lieu.
     *
     * @return array{0: User, 1: Client}
     */
    private function dossier(): array
    {
        $user = User::factory()->create();
        $user->assignRole('client');
        $client = Client::create([
            'user_id' => $user->id,
            'name' => $user->name,
            'ref' => Client::generateRef(),
            'conseiller_id' => $this->agent()->id,
        ])->refresh();

        return [$user, $client];
    }

    private function agentToken(): string
    {
        return $this->agent()->createToken('t')->plainTextToken;
    }

    private function adminToken(): string
    {
        return User::query()->where('email', 'admin@cpi.sn')->firstOrFail()
            ->createToken('t')->plainTextToken;
    }

    public function test_the_client_is_told_when_a_document_awaits_their_signature(): void
    {
        [$user, $client] = $this->dossier();
        $doc = CpiDoc::create([
            'client_id' => $client->id,
            'categorie' => 'contrats',
            'nom' => 'Convention de financement',
            'auteur' => 'CPI',
            'signature_requise' => true,
        ]);

        $this->withToken($this->agentToken())
            ->postJson("/api/staff/cpi-docs/{$doc->id}/publish")
            ->assertOk();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $user->id,
            'titre' => 'Document à signer',
            'type' => 'action',
        ]);
    }

    public function test_the_client_is_told_when_a_document_is_simply_made_available(): void
    {
        [$user, $client] = $this->dossier();
        $doc = CpiDoc::create([
            'client_id' => $client->id,
            'categorie' => 'contrats',
            'nom' => 'Attestation',
            'auteur' => 'CPI',
            'signature_requise' => false,
        ]);

        $this->withToken($this->agentToken())
            ->postJson("/api/staff/cpi-docs/{$doc->id}/publish")
            ->assertOk();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $user->id,
            'titre' => 'Nouveau document',
        ]);
    }

    public function test_the_client_is_told_of_the_bank_decision(): void
    {
        [$user, $client] = $this->dossier();
        $bank = Bank::create(['name' => 'BOA Sénégal', 'color' => '#123456']);

        $this->withToken($this->agentToken())
            ->postJson("/api/staff/clients/{$client->id}/banks/{$bank->id}/assign")
            ->assertCreated();

        $this->withToken($this->agentToken())
            ->postJson("/api/staff/clients/{$client->id}/banks/{$bank->id}/status", ['status' => 'accord'])
            ->assertOk();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $user->id,
            'titre' => 'Accord bancaire',
            'type' => 'validation',
        ]);
    }

    public function test_the_client_is_told_when_money_is_disbursed(): void
    {
        [$user, $client] = $this->dossier();

        $this->withToken($this->agentToken())
            ->putJson("/api/staff/decaissements/{$client->id}", ['terrain_montant' => 12000000])
            ->assertOk();

        $this->withToken($this->adminToken())
            ->postJson("/api/staff/decaissements/{$client->id}/validate-terrain")
            ->assertOk();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $user->id,
            'titre' => 'Versement effectué',
        ]);
    }

    public function test_the_client_is_told_when_the_dossier_moves_forward(): void
    {
        [$user, $client] = $this->dossier();

        $this->withToken($this->agentToken())
            ->postJson("/api/staff/clients/{$client->id}/dossier-etape", ['etape' => 3])
            ->assertOk();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $user->id,
            'titre' => 'Votre dossier a avancé',
        ]);
    }

    public function test_a_dossier_without_an_account_is_not_notified(): void
    {
        // Un dossier ouvert par le personnel avant l'inscription du client n'a
        // personne à prévenir : l'envoi doit être sans effet, pas une erreur.
        $client = Client::create(['name' => 'Sans compte', 'ref' => Client::generateRef(), 'conseiller_id' => $this->agent()->id])->refresh();

        $this->withToken($this->agentToken())
            ->postJson("/api/staff/clients/{$client->id}/dossier-etape", ['etape' => 2])
            ->assertOk();

        $this->assertDatabaseCount('app_notifications', 0);
    }
}
