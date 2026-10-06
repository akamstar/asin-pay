<?php

namespace App\Payments\Exceptions;

use RuntimeException;

/** Erreur transitoire du service de paiement (réseau, 5xx) : l'appel peut être retenté. */
class PaymentGatewayException extends RuntimeException {}
