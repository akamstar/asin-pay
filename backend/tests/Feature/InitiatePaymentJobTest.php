<?php

namespace Tests\Feature;

use App\Actions\ApplyPaymentResult;
use App\Enums\PaymentStatus;
use App\Enums\ServiceRequestStatus;
use App\Jobs\InitiatePaymentJob;
use App\Models\Payment;
use App\Models\ServiceRequest;
use App\Payments\Exceptions\PaymentGatewayException;
use App\Payments\PaymentGatewayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\FakesPaymentGateway;
use Tests\TestCase;

class InitiatePaymentJobTest extends TestCase
{
    use FakesPaymentGateway;
    use RefreshDatabase;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPaymentGateway();
        $serviceRequest = ServiceRequest::factory()->paymentInProgress()->create();
        $this->payment = Payment::factory()->for($serviceRequest)->create(['phone_number' => '0102030405']);
    }

    private function runJob(): InitiatePaymentJob
    {
        $job = (new InitiatePaymentJob($this->payment))->withFakeQueueInteractions();
        $job->handle(app(PaymentGatewayClient::class), app(ApplyPaymentResult::class));

        return $job;
    }

    #[Test]
    public function it_sends_the_debit_request_and_stores_the_provider_id(): void
    {
        Http::fake(['payement.test/*' => $this->signedGatewayResponse(
            $this->gatewayPayment($this->payment, 'PENDING', ['id' => 'pay_abc123']),
        )]);

        $this->runJob()->assertNotFailed();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://payement.test/api/v1/payments'
            && $request->header('X-Api-Key') === ['test-api-key']
            && $request->header('Idempotency-Key') === [$this->payment->code]
            && $request->data() === [
                'merchant_reference' => $this->payment->code,
                'amount' => $this->payment->amount,
                'currency' => 'XOF',
                'phone' => '0102030405',
                'operator' => $this->payment->operator->value,
            ]);

        $this->payment->refresh();
        $this->assertSame('pay_abc123', $this->payment->provider_payment_id);
        $this->assertSame(PaymentStatus::Pending, $this->payment->status);
    }

    #[Test]
    public function it_applies_a_final_status_returned_immediately(): void
    {
        Http::fake(['payement.test/*' => $this->signedGatewayResponse($this->gatewayPayment($this->payment, 'SUCCESS'), 200)]);

        $this->runJob();

        $this->assertSame(PaymentStatus::Success, $this->payment->fresh()->status);
        $this->assertSame(ServiceRequestStatus::Paid, $this->payment->serviceRequest->fresh()->status);
    }

    #[Test]
    public function it_rethrows_transient_errors_so_the_queue_retries(): void
    {
        Http::fake(['payement.test/*' => Http::response('oops', 503)]);

        try {
            $this->runJob();
            $this->fail('Une PaymentGatewayException était attendue.');
        } catch (PaymentGatewayException) {
            $this->assertSame(PaymentStatus::Pending, $this->payment->fresh()->status);
        }
    }

    #[Test]
    public function it_fails_without_retry_when_the_response_is_not_signed(): void
    {
        Http::fake(['payement.test/*' => Http::response($this->gatewayPayment($this->payment, 'PENDING'), 202)]);

        $this->runJob()->assertFailed();
    }

    #[Test]
    public function it_fails_without_retry_when_the_request_is_rejected(): void
    {
        Http::fake(['payement.test/*' => Http::response(['error' => ['code' => 'VALIDATION_FAILED']], 422)]);

        $this->runJob()->assertFailed();
    }

    #[Test]
    public function it_fails_when_the_response_does_not_match_the_payment(): void
    {
        Http::fake(['payement.test/*' => $this->signedGatewayResponse($this->gatewayPayment($this->payment, 'PENDING', ['amount' => 1]))]);

        $this->runJob()->assertFailed();
    }

    #[Test]
    public function it_does_nothing_when_the_payment_is_no_longer_pending(): void
    {
        Http::fake();
        $this->payment->update(['status' => PaymentStatus::Success]);

        $this->runJob();

        Http::assertNothingSent();
    }

    #[Test]
    public function a_definitive_failure_makes_the_request_payable_again(): void
    {
        (new InitiatePaymentJob($this->payment))->failed(new RuntimeException('injoignable'));

        $this->payment->refresh();
        $this->assertSame(PaymentStatus::Failed, $this->payment->status);
        $this->assertSame('PROVIDER_UNAVAILABLE', $this->payment->failure_reason);
        $this->assertSame(ServiceRequestStatus::PendingPayment, $this->payment->serviceRequest->fresh()->status);
    }
}
