<?php

namespace App\Payments;

use App\Payments\Exceptions\InvalidSignatureException;
use Illuminate\Support\Carbon;

/**
 * Vérifie les messages signés en Ed25519 par le service de paiement.
 *
 * Contenu signé : `timestamp + "." + corps brut`. La vérification porte sur les
 * octets reçus (jamais sur un JSON ré-encodé) et rejette les messages trop
 * anciens ou trop en avance pour empêcher leur rejeu.
 */
class SignatureVerifier
{
    public const HEADER_SIGNATURE = 'X-Signature';

    public const HEADER_TIMESTAMP = 'X-Signature-Timestamp';

    public const HEADER_KEY_ID = 'X-Signature-Key-Id';

    public function __construct(
        private readonly string $publicKey,
        private readonly string $keyId,
        private readonly int $toleranceSeconds,
    ) {}

    /**
     * @throws InvalidSignatureException
     */
    public function verify(string $body, ?string $signature, ?string $timestamp, ?string $keyId): void
    {
        if ($signature === null || $signature === '' || $timestamp === null || $keyId === null) {
            throw new InvalidSignatureException('En-têtes de signature manquants.');
        }
        if (! hash_equals($this->keyId, $keyId)) {
            throw new InvalidSignatureException("Identifiant de clé inconnu : {$keyId}.");
        }
        if (! ctype_digit($timestamp) || abs(Carbon::now()->timestamp - (int) $timestamp) > $this->toleranceSeconds) {
            throw new InvalidSignatureException('Horodatage de signature invalide ou expiré.');
        }

        $publicKey = base64_decode($this->publicKey, true);
        $rawSignature = base64_decode($signature, true);
        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidSignatureException('Clé publique de vérification mal configurée.');
        }
        if ($rawSignature === false || strlen($rawSignature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new InvalidSignatureException('Signature mal encodée.');
        }

        if (! sodium_crypto_sign_verify_detached($rawSignature, $timestamp.'.'.$body, $publicKey)) {
            throw new InvalidSignatureException('Signature invalide.');
        }
    }
}
