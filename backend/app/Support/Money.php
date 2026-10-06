<?php

namespace App\Support;

final class Money
{
    /** Formate un montant en FCFA : 5000 donne « 5 000 F » (espaces insécables). */
    public static function format(int $amount): string
    {
        return number_format($amount, 0, ',', "\u{202F}")."\u{00A0}F";
    }
}
