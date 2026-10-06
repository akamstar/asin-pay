<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\Payment;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FakesPaymentGateway;
use Tests\TestCase;

class ReconcilePaymentsTest extends TestCase
{
    use FakesPaymentGateway;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPaymentGateway();
        config(['services.payment.reconcile_after' => 120]);
    }

    private function pendingPayment(int $ageInSeconds, bool $withProviderId = true): Payment
    {
        $factory = Payment::factory()
            ->for(ServiceRequest::factory()->paymentInProgress())
            ->state(['status_at' => now()->subSeconds($ageInSeconds)]);

        return ($withProviderId ? $factory->withProviderId() : $factory)->create();
    }

    #[Test]
    public function it_applies_the_status_of_stale_payments_and_ignores_recent_ones(): void
    {
        $stale = $this->pendingPayment(ageInSeconds: 300);
        $recent = $this->pendingPayment(ageInSeconds: 30);
        Http::fake(['payement.test/*' => $this->signedGatewayResponse($this->gatewayPayment($stale, 'SUCCESS'), 200)]);

        $this->artisan('payments:reconcile')->expectsOutputToContain('Paiements réconciliés : 1.')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === "http://payement.test/api/v1/payments/{$stale->provider_payment_id}");
        $this->assertSame(PaymentStatus::Success, $stale->fresh()->status);
        $this->assertSame(ServiceRequestStatus::Paid, $stale->serviceRequest->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $recent->fresh()->status);
    }

    #[Test]
    public function it_leaves_payments_still_pending_at_the_provider(): void
    {
        $payment = $this->pendingPayment(ageInSeconds: 300);
        Http::fake(['payement.test/*' => $this->signedGatewayResponse($this->gatewayPayment($payment, 'PENDING'), 200)]);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    #[Test]
    public function it_fails_stale_payments_never_transmitted_to_the_provider(): void
    {
        $payment = $this->pendingPayment(ageInSeconds: 300, withProviderId: false);
        Http::fake();

        $this->artisan('payments:reconcile')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('PROVIDER_UNAVAILABLE', $payment->fresh()->failure_reason);
        $this->assertSame(ServiceRequestStatus::PendingPayment, $payment->serviceRequest->fresh()->status);
    }

    #[Test]
    public function it_keeps_going_when_the_provider_is_unreachable(): void
    {
        $payment = $this->pendingPayment(ageInSeconds: 300);
        Http::fake(['payement.test/*' => Http::response('oops', 503)]);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    #[Test]
    public function it_ignores_an_unsigned_provider_response(): void
    {
        $payment = $this->pendingPayment(ageInSeconds: 300);
        Http::fake(['payement.test/*' => Http::response($this->gatewayPayment($payment, 'SUCCESS'), 200)]);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }
}
