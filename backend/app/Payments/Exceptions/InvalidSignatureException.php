<?php

namespace App\Payments\Exceptions;

use RuntimeException;

/** Message du service de paiement dont la signature est absente, invalide ou périmée. */
class InvalidSignatureException extends RuntimeException {}
