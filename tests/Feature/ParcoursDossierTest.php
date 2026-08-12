<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Demande;
use App\Models\User;
use App\Support\ParcoursDossier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le calcul de l'étape du parcours existait en TROIS exemplaires : deux en PHP
 * (ClientController, DemandeController) et un en SQL (StatsController, pour
 * agréger). Rien ne garantissait qu'ils restent d'accord — or un tableau de
 * bord qui compte les dossiers par étape doit compter exactement ce que chaque
 * dossier affiche.
 */
class ParcoursDossierTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function dossier(bool $soumise, int $etapeCpi, bool $piecesValidees): Client
    {
        $client = Client::create([
            'name' => 'Dossier test',
            'ref' => Client::generateRef(),
            'dossier_etape' => $etapeCpi,
        ])->refresh();

        Demande::create(['client_id' => $client->id, 'submitted' => $soumise]);

        if ($piecesValidees) {
            $client->requisDocs()->update(['status' => 'accepte']);
        }

        return $client->refresh();
    }

    public function test_the_php_and_sql_versions_agree_on_every_case(): void
    {
        // C'est le seul test qui compte vraiment : la duplication n'est un
        // problème que si les copies divergent. On construit des dossiers
        // couvrant toutes les combinaisons, puis on compare la distribution
        // calculée en PHP à celle que renvoie l'agrégat SQL.
        foreach ([false, true] as $soumise) {
            foreach ([false, true] as $validees) {
                foreach ([0, 2, 4, 5] as $etapeCpi) {
                    $this->dossier($soumise, $etapeCpi, $validees);
                }
            }
        }

        $attendu = array_fill(0, 6, 0);
        foreach (Client::with('demande', 'requisDocs')->get() as $client) {
            $etape = ParcoursDossier::etape(
                (bool) $client->demande?->submitted,
                $client->requisDocs,
                $client->dossier_etape,
            );
            $attendu[$etape]++;
        }

        $admin = User::query()->where('email', 'admin@cpi.sn')->firstOrFail();
        // `dossiers.parEtape` est produit par le bloc « agent » : c'est lui qui
        // agrège les dossiers par étape.
        $obtenu = $this->withToken($admin->createToken('t')->plainTextToken)
            ->getJson('/api/staff/stats/agent')->assertOk()
            ->json('data.dossiers.parEtape');

        $this->assertSame(
            $attendu,
            $obtenu,
            "L'agrégat SQL de StatsController et le calcul PHP de ParcoursDossier divergent.",
        );
    }

    public function test_an_unsubmitted_dossier_is_at_step_zero(): void
    {
        $client = $this->dossier(false, 4, true);

        $this->assertSame(
            ParcoursDossier::INSCRIPTION,
            ParcoursDossier::etape(false, $client->requisDocs()->get(), 4),
        );
    }

    public function test_a_submitted_dossier_with_pending_pieces_stays_at_step_one(): void
    {
        $client = $this->dossier(true, 4, false);

        $this->assertSame(
            ParcoursDossier::PIECES,
            ParcoursDossier::etape(true, $client->requisDocs()->get(), 4),
        );
    }

    public function test_an_out_of_range_stage_is_clamped(): void
    {
        // Une valeur aberrante en base ne doit pas sortir telle quelle vers
        // l'interface.
        $client = $this->dossier(true, 0, true);
        $pieces = $client->requisDocs()->get();

        $this->assertSame(ParcoursDossier::INSTRUCTION, ParcoursDossier::etape(true, $pieces, 0));
        $this->assertSame(ParcoursDossier::SIGNATURE, ParcoursDossier::etape(true, $pieces, 99));
    }
}
