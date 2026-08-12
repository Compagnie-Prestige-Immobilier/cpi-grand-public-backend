<?php

namespace Tests\Feature;

use App\Models\Chantier;
use App\Models\Client;
use App\Models\Decaissement;
use App\Models\Demande;
use App\Support\HasOneDuplicates;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `Demande`, `Decaissement` et `Chantier` sont des `hasOne` côté modèle sans
 * qu'aucune contrainte ne l'impose en base : deux requêtes concurrentes sur un
 * dossier fraîchement créé pouvaient insérer deux lignes, après quoi `first()`
 * en renvoyait une au hasard.
 */
class IntegriteDossierTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function client(): Client
    {
        return Client::create(['name' => 'Awa Ndiaye', 'ref' => Client::generateRef()]);
    }

    public function test_a_client_cannot_hold_two_demandes(): void
    {
        $client = $this->client();
        Demande::create(['client_id' => $client->id]);

        $this->expectException(QueryException::class);
        Demande::create(['client_id' => $client->id]);
    }

    public function test_a_client_cannot_hold_two_decaissements(): void
    {
        // `Client::booted()` en crée déjà un à la création du dossier.
        $client = $this->client();

        $this->expectException(QueryException::class);
        Decaissement::create(['client_id' => $client->id]);
    }

    public function test_a_client_cannot_hold_two_chantiers(): void
    {
        $client = $this->client();

        $this->expectException(QueryException::class);
        Chantier::create(['client_id' => $client->id]);
    }

    public function test_ensure_methods_stay_idempotent(): void
    {
        $client = $this->client();

        // Rejouées, elles doivent relire la ligne existante et non tenter une
        // seconde insertion — c'est ce que `firstOrCreate` garantit désormais.
        $decaissement = $client->ensureDecaissement();
        $chantier = $client->ensureChantier();

        $this->assertSame($decaissement->id, $client->ensureDecaissement()->id);
        $this->assertSame($chantier->id, $client->ensureChantier()->id);
        $this->assertSame(1, Decaissement::where('client_id', $client->id)->count());
        $this->assertSame(1, Chantier::where('client_id', $client->id)->count());
        $this->assertSame(4, $chantier->tranches()->count());
    }

    public function test_the_duplicate_audit_reports_a_clean_base(): void
    {
        $this->client();

        $this->assertSame(0, HasOneDuplicates::total());
        $this->artisan('cpi:audit-doublons')->assertSuccessful();
    }

    public function test_the_audit_command_is_read_only(): void
    {
        $client = $this->client();
        $avant = [
            Demande::count(), Decaissement::count(), Chantier::count(), Client::count(),
        ];

        $this->artisan('cpi:audit-doublons');

        $this->assertSame($avant, [
            Demande::count(), Decaissement::count(), Chantier::count(), Client::count(),
        ]);
        $this->assertNotNull($client->fresh());
    }
}
