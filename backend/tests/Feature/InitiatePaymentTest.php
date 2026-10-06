<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\ServiceRequestStatus;
use App\Jobs\InitiatePaymentJob;
use App\Models\Payment;
use App\Models\ServiceRequest;
use App\Support\ReferenceGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InitiatePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function pay(ServiceRequest $serviceRequest, mixed $phoneNumber = '0102030405'): TestResponse
    {
        return $this->postJson("/api/v1/service-requests/{$serviceRequest->code}/payments", ['phone_number' => $phoneNumber, 'operator' => 'MTN']);
    }

    #[Test]
    public function it_starts_the_payment_and_hands_it_to_the_queue(): void
    {
        $serviceRequest = ServiceRequest::factory()->create();

        $response = $this->pay($serviceRequest)
            ->assertAccepted()
            ->assertJsonPath('data.status', ServiceRequestStatus::PaymentInProgress->value)
            ->assertJsonPath('data.is_payable', false)
            ->assertJsonPath('data.payment.status', PaymentStatus::Pending->value)
            ->assertJsonPath('data.payment.amount', $serviceRequest->amount)
            ->assertJsonPath('data.payment.phone_number', '01 •• •• •• 05');

        $payment = Payment::sole();
        $this->assertMatchesRegularExpression('/^'.ReferenceGenerator::regex(ReferenceGenerator::PAYMENT_PREFIX).'$/', $payment->code);
        $this->assertSame($response->json('data.payment.code'), $payment->code);
        $this->assertSame('0102030405', $payment->phone_number);
        $this->assertSame($serviceRequest->amount, $payment->amount);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(ServiceRequestStatus::PaymentInProgress, $serviceRequest->fresh()->status);

        Queue::assertPushed(InitiatePaymentJob::class, fn (InitiatePaymentJob $job): bool => $job->payment->is($payment));
    }

    #[Test]
    public function it_accepts_common_separators_in_the_phone_number(): void
    {
        $this->pay(ServiceRequest::factory()->create(), '01 02.03-04 05')->assertAccepted();

        $this->assertSame('0102030405', Payment::sole()->phone_number);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidPhoneNumbers(): array
    {
        return [
            'absent' => [null],
            'vide' => [''],
            'ne commence pas par 01' => ['0702030405'],
            'commence par 1' => ['1002030405'],
            '9 chiffres' => ['010203040'],
            '11 chiffres' => ['01020304050'],
            'avec des lettres' => ['01020304ab'],
            'avec indicatif' => ['+2250102030405'],
            'tableau' => [['0102030405']],
        ];
    }

    #[Test]
    #[DataProvider('invalidPhoneNumbers')]
    public function it_rejects_invalid_phone_numbers(mixed $phoneNumber): void
    {
        $serviceRequest = ServiceRequest::factory()->create();

        $this->pay($serviceRequest, $phoneNumber)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone_number');

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(ServiceRequestStatus::PendingPayment, $serviceRequest->fresh()->status);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_rejects_an_unknown_operator(): void
    {
        $this->postJson('/api/v1/service-requests/'.ServiceRequest::factory()->create()->code.'/payments', ['phone_number' => '0102030405', 'operator' => 'ORANGE'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('operator');
    }

    #[Test]
    public function it_refuses_a_second_payment_while_one_is_in_progress(): void
    {
        $serviceRequest = ServiceRequest::factory()->create();
        $this->pay($serviceRequest)->assertAccepted();

        $this->pay($serviceRequest)
            ->assertConflict()
            ->assertJsonPath('message', 'Un paiement est déjà en cours pour cette demande.');

        $this->assertDatabaseCount('payments', 1);
    }

    #[Test]
    public function it_refuses_to_pay_a_request_already_paid(): void
    {
        $serviceRequest = ServiceRequest::factory()->paid()->create();

        $this->pay($serviceRequest)
            ->assertConflict()
            ->assertJsonPath('message', 'Cette demande est déjà payée.');
    }

    #[Test]
    public function it_allows_a_new_attempt_after_a_failure(): void
    {
        $serviceRequest = ServiceRequest::factory()->create();
        Payment::factory()->for($serviceRequest)->failed()->create();

        $this->pay($serviceRequest)->assertAccepted();

        $this->assertSame(2, $serviceRequest->payments()->count());
    }

    #[Test]
    public function it_returns_404_for_an_unknown_request(): void
    {
        $this->postJson('/api/v1/service-requests/DEM-0000000000/payments', ['phone_number' => '0102030405', 'operator' => 'MTN'])
            ->assertNotFound();
    }

    #[Test]
    public function the_database_forbids_two_pending_payments_for_the_same_request(): void
    {
        $serviceRequest = ServiceRequest::factory()->paymentInProgress()->create();
        Payment::factory()->for($serviceRequest)->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('payments_one_pending_per_service_request');

        Payment::factory()->for($serviceRequest)->create();
    }
}
