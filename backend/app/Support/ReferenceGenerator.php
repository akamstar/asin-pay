<?php

namespace App\Support;

/**
 * Génère les références publiques, par exemple DEM-7K3M9QX2AB (demande) ou PAY-4HZ81QW0CE (paiement).
 *
 * Sans compte usager, la référence d'une demande est le seul moyen d'y accéder :
 * elle est donc aléatoire (10 caractères Crockford base32, environ 50 bits) et
 * non séquentielle. L'alphabet exclut I, L, O et U pour éviter les confusions.
 */
class ReferenceGenerator
{
    public const SERVICE_REQUEST_PREFIX = 'DEM-';

    public const PAYMENT_PREFIX = 'PAY-';

    public const LENGTH = 10;

    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function generate(string $prefix = self::SERVICE_REQUEST_PREFIX): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $prefix.$code;
    }

    /**
     * Expression régulière (sans délimiteurs) d'une référence valide, utilisée comme contrainte de route.
     */
    public static function regex(string $prefix = self::SERVICE_REQUEST_PREFIX): string
    {
        return preg_quote($prefix, '/').'['.self::ALPHABET.']{'.self::LENGTH.'}';
    }
}
