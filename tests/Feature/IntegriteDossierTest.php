<?php

namespace Tests\Feature;

use App\Models\Chantier;
use App\Models\Client;
use App\Models\Decaissement;
use App\Models\Demande;
use App\Models\RequisDoc;
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

    public function test_deleting_a_client_hides_its_whole_dossier_without_destroying_it(): void
    {
        $client = $this->client();
        Demande::create(['client_id' => $client->id, 'montant' => 25000000]);
        $client->refresh();

        $client->delete();

        // Plus rien de visible…
        $this->assertNull(Client::find($client->id));
        $this->assertNull(Demande::where('client_id', $client->id)->first());
        $this->assertNull(Decaissement::where('client_id', $client->id)->first());
        $this->assertNull(Chantier::where('client_id', $client->id)->first());

        // …mais rien de détruit : une suppression douce est un simple UPDATE,
        // les cascades SQL ne se déclenchent pas, d'où la propagation explicite.
        $this->assertSoftDeleted('clients', ['id' => $client->id]);
        $this->assertSoftDeleted('demandes', ['client_id' => $client->id]);
        $this->assertSoftDeleted('decaissements', ['client_id' => $client->id]);
        $this->assertSoftDeleted('chantiers', ['client_id' => $client->id]);
        $this->assertSame(3, RequisDoc::withTrashed()->where('client_id', $client->id)->whereNotNull('deleted_at')->count());
    }

    public function test_restoring_a_client_brings_its_dossier_back(): void
    {
        $client = $this->client();
        Demande::create(['client_id' => $client->id, 'montant' => 25000000]);
        $client->refresh();
        $client->delete();

        Client::withTrashed()->findOrFail($client->id)->restore();

        // Sans la restauration en cascade, le dossier ne reviendrait qu'en
        // coquille : le client sans sa demande, ses pièces ni son chantier.
        $this->assertNotNull(Client::find($client->id));
        $this->assertNotNull(Demande::where('client_id', $client->id)->first());
        $this->assertNotNull(Decaissement::where('client_id', $client->id)->first());
        $this->assertNotNull(Chantier::where('client_id', $client->id)->first());
        $this->assertSame(3, RequisDoc::where('client_id', $client->id)->count());
    }

    public function test_ensure_methods_restore_a_trashed_row_instead_of_failing(): void
    {
        // La contrainte d'unicité compte AUSSI les lignes en corbeille : une
        // insertion naïve échouerait au lieu de restaurer.
        $client = $this->client();
        $decaissementId = $client->decaissement->id;
        $client->decaissement->delete();
        $client->refresh();

        $decaissement = $client->ensureDecaissement();

        $this->assertSame($decaissementId, $decaissement->id);
        $this->assertFalse($decaissement->trashed());
        $this->assertSame(1, Decaissement::withTrashed()->where('client_id', $client->id)->count());
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
