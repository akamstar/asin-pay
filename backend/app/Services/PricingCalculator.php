<?php

namespace App\Services;

use InvalidArgumentException;

class PricingCalculator
{
    /** Montant à payer : prix unitaire x quantité + frais de dossier. */
    public function calculate(int $unitPrice, int $quantity, int $fees): Pricing
    {
        if ($unitPrice <= 0) {
            throw new InvalidArgumentException('Le prix unitaire doit être strictement positif.');
        }
        if ($quantity <= 0) {
            throw new InvalidArgumentException('La quantité doit être strictement positive.');
        }
        if ($fees < 0) {
            throw new InvalidArgumentException('Les frais ne peuvent pas être négatifs.');
        }

        $subtotal = $unitPrice * $quantity;

        return new Pricing($unitPrice, $quantity, $subtotal, $fees, $subtotal + $fees);
    }
}
