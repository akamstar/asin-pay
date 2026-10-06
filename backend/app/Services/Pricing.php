<?php

namespace App\Services;

/** Détail du montant d'une demande, en FCFA. */
final readonly class Pricing
{
    public function __construct(
        public int $unitPrice,
        public int $quantity,
        public int $subtotal,
        public int $fees,
        public int $total,
    ) {}
}
