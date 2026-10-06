<?php

namespace Tests\Unit;

use App\Payments\Exceptions\InvalidSignatureException;
use App\Payments\SignatureVerifier;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SignatureVerifierTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const BODY = '{"id":"pay_1","status":"SUCCESS"}';

    private string $secretKey;

    private SignatureVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestamp(self::NOW));
        $keyPair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keyPair);
        $this->verifier = new SignatureVerifier(base64_encode(sodium_crypto_sign_publickey($keyPair)), 'key-1', 300);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function sign(string $body, int|string $timestamp, ?string $secretKey = null): string
    {
        return base64_encode(sodium_crypto_sign_detached($timestamp.'.'.$body, $secretKey ?? $this->secretKey));
    }

    #[Test]
    public function it_accepts_a_valid_signature(): void
    {
        $this->verifier->verify(self::BODY, $this->sign(self::BODY, self::NOW), (string) self::NOW, 'key-1');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_accepts_a_timestamp_within_tolerance(): void
    {
        $timestamp = self::NOW - 299;
        $this->verifier->verify(self::BODY, $this->sign(self::BODY, $timestamp), (string) $timestamp, 'key-1');

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{callable(self): array{string, ?string, ?string, ?string}}>
     */
    public static function invalidMessages(): array
    {
        return [
            'corps modifié' => [fn (self $t) => ['{"id":"pay_1","status":"FAILED"}', $t->sign(self::BODY, self::NOW), (string) self::NOW, 'key-1']],
            'timestamp modifié' => [fn (self $t) => [self::BODY, $t->sign(self::BODY, self::NOW), (string) (self::NOW + 1), 'key-1']],
            'message trop ancien (rejeu)' => [fn (self $t) => [self::BODY, $t->sign(self::BODY, self::NOW - 301), (string) (self::NOW - 301), 'key-1']],
            'message trop en avance' => [fn (self $t) => [self::BODY, $t->sign(self::BODY, self::NOW + 301), (string) (self::NOW + 301), 'key-1']],
            'autre clé privée' => [fn (self $t) => [self::BODY, $t->sign(self::BODY, self::NOW, sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())), (string) self::NOW, 'key-1']],
            'identifiant de clé inconnu' => [fn (self $t) => [self::BODY, $t->sign(self::BODY, self::NOW), (string) self::NOW, 'key-2']],
            'signature absente' => [fn (self $t) => [self::BODY, null, (string) self::NOW, 'key-1']],
            'timestamp absent' => [fn (self $t) => [self::BODY, $t->sign(self::BODY, self::NOW), null, 'key-1']],
            'timestamp non numérique' => [fn (self $t) => [self::BODY, $t->sign(self::BODY, 'abc'), 'abc', 'key-1']],
            'signature non base64' => [fn (self $t) => [self::BODY, '%%%', (string) self::NOW, 'key-1']],
        ];
    }

    /**
     * @param  callable(self): array{string, ?string, ?string, ?string}  $message
     */
    #[Test]
    #[DataProvider('invalidMessages')]
    public function it_rejects_invalid_messages(callable $message): void
    {
        $this->expectException(InvalidSignatureException::class);

        $this->verifier->verify(...$message($this));
    }

    #[Test]
    public function it_rejects_a_misconfigured_public_key(): void
    {
        $verifier = new SignatureVerifier('pas-une-cle', 'key-1', 300);

        $this->expectException(InvalidSignatureException::class);

        $verifier->verify(self::BODY, $this->sign(self::BODY, self::NOW), (string) self::NOW, 'key-1');
    }
}
