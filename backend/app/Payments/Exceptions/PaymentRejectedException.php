<?php

namespace App\Payments\Exceptions;

use RuntimeException;

/** Requête refusée par le service de paiement (4xx) : la retenter ne changerait rien. */
class PaymentRejectedException extends RuntimeException {}
