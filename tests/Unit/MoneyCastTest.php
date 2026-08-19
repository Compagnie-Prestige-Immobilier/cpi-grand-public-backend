<?php

namespace Tests\Unit;

use App\Casts\MoneyCast;
use App\Models\Demande;
use Brick\Money\Money;
use PHPUnit\Framework\TestCase;

class MoneyCastTest extends TestCase
{
    private MoneyCast $cast;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cast = new MoneyCast('montant_currency');
    }

    public function test_it_reads_an_integer_column_as_money(): void
    {
        $montant = $this->cast->get(new Demande, 'montant_amount', 25_000_000, ['montant_currency' => 'XOF']);

        $this->assertInstanceOf(Money::class, $montant);
        $this->assertSame('XOF', $montant->getCurrency()->getCurrencyCode());
        // XOF n'a pas de sous-unité : l'entier stocké EST le montant en FCFA.
        $this->assertSame('25000000', (string) $montant->getAmount());
    }

    public function test_it_keeps_null_null(): void
    {
        $this->assertNull($this->cast->get(new Demande, 'montant_amount', null, []));
    }

    public function test_it_writes_both_the_amount_and_the_currency(): void
    {
        $ecrit = $this->cast->set(
            new Demande, 'montant_amount', Money::of(15_000_000, 'XOF'), ['montant_currency' => 'XOF'],
        );

        $this->assertSame(15_000_000, $ecrit['montant_amount']);
        $this->assertSame('XOF', $ecrit['montant_currency']);
    }

    public function test_it_accepts_a_plain_number_and_falls_back_to_xof(): void
    {
        $ecrit = $this->cast->set(new Demande, 'montant_amount', 900_000, []);

        $this->assertSame(900_000, $ecrit['montant_amount']);
        $this->assertSame('XOF', $ecrit['montant_currency']);
    }

    public function test_a_currency_with_a_minor_unit_is_stored_in_minor_units(): void
    {
        // Garde-fou : le schéma doit rester juste si un financement en euros
        // apparaît un jour. 12,34 EUR = 1234 centimes.
        $ecrit = $this->cast->set(new Demande, 'montant_amount', Money::of('12.34', 'EUR'), ['montant_currency' => 'EUR']);

        $this->assertSame(1234, $ecrit['montant_amount']);
        $this->assertSame('EUR', $ecrit['montant_currency']);
    }

    public function test_reading_back_a_minor_unit_currency_restores_the_decimal(): void
    {
        $montant = $this->cast->get(new Demande, 'montant_amount', 1234, ['montant_currency' => 'EUR']);

        $this->assertSame('12.34', (string) $montant?->getAmount());
    }
}
