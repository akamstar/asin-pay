<?php

namespace App\Support;

/**
 * Génère les références publiques des demandes, par exemple DEM-7K3M9QX2AB.
 *
 * Sans compte usager, la référence est le seul moyen d'accéder à une demande :
 * elle est donc aléatoire (10 caractères Crockford base32, environ 50 bits) et
 * non séquentielle. L'alphabet exclut I, L, O et U pour éviter les confusions.
 */
class ReferenceGenerator
{
    public const PREFIX = 'DEM-';

    public const LENGTH = 10;

    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return self::PREFIX.$code;
    }

    /**
     * Expression régulière (sans délimiteurs) d'une référence valide, utilisée comme contrainte de route.
     */
    public static function regex(): string
    {
        return preg_quote(self::PREFIX, '/').'['.self::ALPHABET.']{'.self::LENGTH.'}';
    }
}
