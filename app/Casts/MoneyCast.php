<?php

namespace App\Casts;

use Brick\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Montant monétaire porté par deux colonnes : `<champ>_amount` (entier, en
 * unité mineure) et `<champ>_currency` (code ISO 4217).
 *
 * Pourquoi un entier plutôt que le `decimal(15, 2)` actuel : le cast Eloquent
 * `decimal:2` renvoie une **chaîne**, que les DTO redéclaraient en `float`.
 * On additionnait donc des flottants issus de chaînes sur des montants de
 * financement immobilier — sans jamais vérifier, par ailleurs, que la somme
 * décaissée reste sous le montant accordé.
 *
 * Le franc CFA (XOF) n'a **pas** de sous-unité : l'entier en unité mineure est
 * numériquement le montant en FCFA, sans division par 100 nulle part. Le
 * schéma reste néanmoins correct si une devise à sous-unité apparaît un jour.
 *
 * Usage :
 *   protected function casts(): array
 *   {
 *       return ['montant_amount' => MoneyCast::class.':montant_currency'];
 *   }
 *
 * @implements CastsAttributes<Money, Money|int|string|null>
 */
class MoneyCast implements CastsAttributes
{
    public function __construct(
        private readonly string $currencyColumn = 'currency',
        private readonly string $defaultCurrency = 'XOF',
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::ofMinor((int) $value, $this->currencyOf($attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null, $this->currencyColumn => $this->currencyOf($attributes)];
        }

        $money = $value instanceof Money
            ? $value
            : Money::of($value, $this->currencyOf($attributes));

        return [
            $key => $money->getMinorAmount()->toInt(),
            $this->currencyColumn => $money->getCurrency()->getCurrencyCode(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function currencyOf(array $attributes): string
    {
        $devise = $attributes[$this->currencyColumn] ?? null;

        return is_string($devise) && $devise !== '' ? $devise : $this->defaultCurrency;
    }
}
