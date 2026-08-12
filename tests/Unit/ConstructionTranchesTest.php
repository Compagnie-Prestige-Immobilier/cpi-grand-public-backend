<?php

namespace Tests\Unit;

use App\Models\Chantier;
use App\Support\ConstructionTranches;
use PHPUnit\Framework\TestCase;

class ConstructionTranchesTest extends TestCase
{
    public function test_the_percentages_add_up_to_one_hundred(): void
    {
        $this->assertSame(100, ConstructionTranches::totalPct());
    }

    public function test_there_are_four_tranches_numbered_one_to_four(): void
    {
        $this->assertSame(4, ConstructionTranches::count());
        $this->assertSame([1, 2, 3, 4], array_column(ConstructionTranches::definitions(), 'num'));
    }

    public function test_the_chantier_derives_from_the_shared_definitions(): void
    {
        // Le décaissement bancaire porte les mêmes tranches : si les deux listes
        // cessaient de venir de la même source, libellés et pourcentages
        // pourraient diverger sans que rien ne le signale.
        $this->assertSame(ConstructionTranches::definitions(), Chantier::defaultTranches());
    }

    public function test_a_tranche_can_be_found_by_its_functional_number(): void
    {
        $this->assertSame('Avance de démarrage', ConstructionTranches::byNum(1)['label']);
        $this->assertSame(5, ConstructionTranches::byNum(4)['pct']);
        $this->assertNull(ConstructionTranches::byNum(5));
    }
}
