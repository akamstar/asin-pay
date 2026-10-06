<?php

namespace App\Http\Controllers\Api;

use App\Actions\ApplyPaymentResult;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Payments\Exceptions\InvalidSignatureException;
use App\Payments\GatewayPayment;
use App\Payments\SignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

class PaymentWebhookController extends Controller
{
    private const EVENTS = [
        'payment.succeeded' => PaymentStatus::Success,
        'payment.failed' => PaymentStatus::Failed,
    ];

    /**
     * Reçoit le résultat d'un paiement, signé en Ed25519 par le service de paiement.
     * La signature est vérifiée sur le corps brut, avant tout décodage.
     */
    public function __invoke(Request $request, SignatureVerifier $verifier, ApplyPaymentResult $applyResult): Response|JsonResponse
    {
        $body = $request->getContent();

        try {
            $verifier->verify(
                $body,
                $request->header(SignatureVerifier::HEADER_SIGNATURE),
                $request->header(SignatureVerifier::HEADER_TIMESTAMP),
                $request->header(SignatureVerifier::HEADER_KEY_ID),
            );
        } catch (InvalidSignatureException $e) {
            Log::warning('Webhook de paiement rejeté : signature invalide.', ['reason' => $e->getMessage(), 'ip' => $request->ip()]);

            return response()->json(['message' => 'Signature invalide.'], 401);
        }

        $decoded = json_decode($body, true);
        $event = is_array($decoded) ? $decoded : [];
        $expectedStatus = self::EVENTS[(string) ($event['event'] ?? '')] ?? null;

        try {
            $result = GatewayPayment::fromArray(is_array($event['data'] ?? null) ? $event['data'] : []);
        } catch (UnexpectedValueException) {
            $result = null;
        }

        if ($expectedStatus === null || $result === null || $result->status !== $expectedStatus) {
            return response()->json(['message' => 'Événement invalide.'], 422);
        }

        $payment = Payment::where('code', $result->merchantReference)->first();
        if ($payment === null) {
            Log::warning('Webhook de paiement pour un paiement inconnu.', ['merchant_reference' => $result->merchantReference]);

            return response()->json(['message' => 'Paiement inconnu.'], 404);
        }

        if ($result->amount !== $payment->amount
            || ($payment->provider_payment_id !== null && $payment->provider_payment_id !== $result->id)) {
            Log::error('Webhook de paiement incohérent avec le paiement enregistré.', [
                'payment' => $payment->code, 'received_amount' => $result->amount, 'received_id' => $result->id,
            ]);

            return response()->json(['message' => 'Événement incohérent avec le paiement.'], 422);
        }

        $applyResult->handle($payment, $result->status, $result->failureReason, $result->id);

        return response()->noContent();
    }
}
