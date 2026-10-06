<?php

namespace App\Payments\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** La demande n'est pas payable (paiement déjà en cours ou demande déjà payée). */
class PaymentNotAllowedException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
