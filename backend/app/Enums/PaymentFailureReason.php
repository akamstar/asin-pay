<?php

namespace App\Enums;

/**
 * Raisons d'échec connues. Le service de paiement peut en renvoyer d'autres :
 * la colonne reste donc une chaîne libre et {@see self::messageFor()} prévoit un message par défaut.
 */
enum PaymentFailureReason: string
{
    case Refused = 'TRANSACTION_REFUSEE';
    case ProviderUnavailable = 'PROVIDER_UNAVAILABLE';

    public static function messageFor(?string $reason): string
    {
        return match (self::tryFrom((string) $reason)) {
            self::Refused => 'Le paiement a été refusé. Vérifiez votre solde ou utilisez un autre numéro.',
            self::ProviderUnavailable => 'Le service de paiement est momentanément indisponible. Veuillez réessayer.',
            null => "Le paiement n'a pas abouti. Veuillez réessayer.",
        };
    }
}
