<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\Payment;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FakesPaymentGateway;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use FakesPaymentGateway;
    use RefreshDatabase;

    private ServiceRequest $serviceRequest;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPaymentGateway();
        $this->serviceRequest = ServiceRequest::factory()->paymentInProgress()->create();
        $this->payment = Payment::factory()->for($this->serviceRequest)->create();
    }

    private function assertUnchanged(): void
    {
        $this->assertSame(PaymentStatus::Pending, $this->payment->fresh()->status);
        $this->assertSame(ServiceRequestStatus::PaymentInProgress, $this->serviceRequest->fresh()->status);
    }

    #[Test]
    public function a_success_marks_the_request_as_paid(): void
    {
        $this->postWebhook($this->webhookEvent($this->payment, 'SUCCESS', ['id' => 'pay_xyz']))->assertNoContent();

        $payment = $this->payment->fresh();
        $this->assertSame(PaymentStatus::Success, $payment->status);
        $this->assertSame('pay_xyz', $payment->provider_payment_id);
        $this->assertSame(ServiceRequestStatus::Paid, $this->serviceRequest->fresh()->status);

        $this->getJson("/api/v1/service-requests/{$this->serviceRequest->code}")
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.payment.status', 'SUCCESS');
    }

    #[Test]
    public function a_failure_makes_the_request_payable_again(): void
    {
        $this->postWebhook($this->webhookEvent($this->payment, 'FAILED'))->assertNoContent();

        $payment = $this->payment->fresh();
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('TRANSACTION_REFUSEE', $payment->failure_reason);
        $this->assertSame(ServiceRequestStatus::PendingPayment, $this->serviceRequest->fresh()->status);

        $this->getJson("/api/v1/service-requests/{$this->serviceRequest->code}")
            ->assertJsonPath('data.is_payable', true)
            ->assertJsonPath('data.payment.failure_message', 'Le paiement a été refusé. Vérifiez votre solde ou utilisez un autre numéro.');
    }

    #[Test]
    public function a_duplicate_webhook_is_ignored(): void
    {
        $event = $this->webhookEvent($this->payment, 'SUCCESS');
        $this->postWebhook($event)->assertNoContent();
        $statusAt = $this->payment->fresh()->status_at;

        $this->travel(5)->seconds();
        $this->postWebhook($event)->assertNoContent();

        $this->assertEquals($statusAt, $this->payment->fresh()->status_at);
    }

    #[Test]
    public function a_failure_received_after_a_success_is_ignored(): void
    {
        $this->postWebhook($this->webhookEvent($this->payment, 'SUCCESS'))->assertNoContent();
        $this->postWebhook($this->webhookEvent($this->payment, 'FAILED'))->assertNoContent();

        $this->assertSame(PaymentStatus::Success, $this->payment->fresh()->status);
        $this->assertSame(ServiceRequestStatus::Paid, $this->serviceRequest->fresh()->status);
    }

    #[Test]
    public function a_late_success_after_a_provider_outage_is_accepted(): void
    {
        $this->payment->update(['status' => PaymentStatus::Failed, 'failure_reason' => 'PROVIDER_UNAVAILABLE']);
        $this->serviceRequest->update(['status' => ServiceRequestStatus::PendingPayment]);

        $this->postWebhook($this->webhookEvent($this->payment, 'SUCCESS'))->assertNoContent();

        $this->assertSame(PaymentStatus::Success, $this->payment->fresh()->status);
        $this->assertSame(ServiceRequestStatus::Paid, $this->serviceRequest->fresh()->status);
    }

    #[Test]
    public function a_second_success_on_an_already_paid_request_is_reported(): void
    {
        $this->payment->update(['status' => PaymentStatus::Failed, 'failure_reason' => 'PROVIDER_UNAVAILABLE']);
        $this->serviceRequest->update(['status' => ServiceRequestStatus::Paid]);
        Log::spy();

        $this->postWebhook($this->webhookEvent($this->payment, 'SUCCESS'))->assertNoContent();

        Log::shouldHaveReceived('critical')->once();
        $this->assertSame(ServiceRequestStatus::Paid, $this->serviceRequest->fresh()->status);
    }

    #[Test]
    public function a_tampered_body_is_rejected(): void
    {
        $signed = json_encode($this->webhookEvent($this->payment, 'FAILED'));

        $this->postWebhook($this->webhookEvent($this->payment, 'SUCCESS'), $this->signatureHeaders($signed))
            ->assertUnauthorized();

        $this->assertUnchanged();
    }

    #[Test]
    public function an_expired_signature_is_rejected(): void
    {
        $event = $this->webhookEvent($this->payment, 'SUCCESS');

        $this->postWebhook($event, $this->signatureHeaders(json_encode($event), now()->subMinutes(10)->timestamp))
            ->assertUnauthorized();

        $this->assertUnchanged();
    }

    #[Test]
    public function a_missing_signature_is_rejected(): void
    {
        $this->postWebhook($this->webhookEvent($this->payment, 'SUCCESS'), [])->assertUnauthorized();

        $this->assertUnchanged();
    }

    #[Test]
    public function an_unknown_payment_returns_404(): void
    {
        $this->postWebhook($this->webhookEvent($this->payment, 'SUCCESS', ['merchant_reference' => 'PAY-0000000000']))
            ->assertNotFound();
    }

    #[Test]
    public function an_amount_mismatch_is_rejected(): void
    {
        $this->postWebhook($this->webhookEvent($this->payment, 'SUCCESS', ['amount' => 1]))->assertUnprocessable();

        $this->assertUnchanged();
    }

    #[Test]
    public function a_provider_id_mismatch_is_rejected(): void
    {
        $this->payment->update(['provider_payment_id' => 'pay_original']);

        $this->postWebhook($this->webhookEvent($this->payment, 'SUCCESS', ['id' => 'pay_other']))->assertUnprocessable();

        $this->assertUnchanged();
    }

    #[Test]
    public function an_event_inconsistent_with_its_status_is_rejected(): void
    {
        $event = $this->webhookEvent($this->payment, 'SUCCESS');
        $event['event'] = 'payment.failed';

        $this->postWebhook($event)->assertUnprocessable();

        $this->assertUnchanged();
    }
}
