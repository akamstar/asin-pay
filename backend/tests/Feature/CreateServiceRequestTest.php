<?php

namespace Tests\Feature;

use App\Enums\ServiceRequestStatus;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Support\ReferenceGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreateServiceRequestTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['service_requests.fees' => 100, 'service_requests.max_quantity' => 10]);
        $this->service = Service::factory()->create(['code' => 'CASIER_JUDICIAIRE', 'title' => 'Casier judiciaire', 'price' => 1500]);
    }

    #[Test]
    public function it_records_the_request_and_returns_the_amount_to_pay(): void
    {
        $response = $this->postJson('/api/v1/service-requests', ['service_code' => 'CASIER_JUDICIAIRE', 'quantity' => 3]);

        $response->assertCreated()
            ->assertJsonPath('data.service', ['code' => 'CASIER_JUDICIAIRE', 'title' => 'Casier judiciaire'])
            ->assertJsonPath('data.unit_price', 1500)
            ->assertJsonPath('data.quantity', 3)
            ->assertJsonPath('data.subtotal', 4500)
            ->assertJsonPath('data.fees', 100)
            ->assertJsonPath('data.amount', 4600)
            ->assertJsonPath('data.currency', 'XOF')
            ->assertJsonPath('data.status', ServiceRequestStatus::PendingPayment->value);

        $code = $response->json('data.code');
        $this->assertMatchesRegularExpression('/^'.ReferenceGenerator::regex().'$/', $code);
        $response->assertHeader('Location', route('api.service-requests.show', $code));
        $response->assertJsonPath('data.links.page', route('service-requests.show', $code));

        $this->assertDatabaseHas('service_requests', [
            'code' => $code,
            'service_id' => $this->service->id,
            'unit_price' => 1500,
            'quantity' => 3,
            'fees' => 100,
            'amount' => 4600,
            'status' => ServiceRequestStatus::PendingPayment->value,
        ]);
    }

    #[Test]
    public function it_uses_the_configured_fees(): void
    {
        config(['service_requests.fees' => 250]);

        $this->postJson('/api/v1/service-requests', ['service_code' => 'CASIER_JUDICIAIRE', 'quantity' => 1])
            ->assertCreated()
            ->assertJsonPath('data.fees', 250)
            ->assertJsonPath('data.amount', 1750);
    }

    #[Test]
    public function it_ignores_amounts_sent_by_the_client(): void
    {
        $this->postJson('/api/v1/service-requests', [
            'service_code' => 'CASIER_JUDICIAIRE',
            'quantity' => 1,
            'amount' => 1,
            'unit_price' => 1,
            'fees' => 0,
        ])->assertCreated()->assertJsonPath('data.amount', 1600);
    }

    #[Test]
    public function later_price_and_fee_changes_do_not_affect_existing_requests(): void
    {
        $code = $this->postJson('/api/v1/service-requests', ['service_code' => 'CASIER_JUDICIAIRE', 'quantity' => 2])
            ->json('data.code');

        $this->service->update(['price' => 9000]);
        config(['service_requests.fees' => 999]);

        $this->getJson("/api/v1/service-requests/{$code}")
            ->assertOk()
            ->assertJsonPath('data.unit_price', 1500)
            ->assertJsonPath('data.fees', 100)
            ->assertJsonPath('data.amount', 3100);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'service absent' => [['quantity' => 1], 'service_code'],
            'service inconnu' => [['service_code' => 'INCONNU', 'quantity' => 1], 'service_code'],
            'service non textuel' => [['service_code' => ['x'], 'quantity' => 1], 'service_code'],
            'quantité absente' => [['service_code' => 'CASIER_JUDICIAIRE'], 'quantity'],
            'quantité nulle' => [['service_code' => 'CASIER_JUDICIAIRE', 'quantity' => 0], 'quantity'],
            'quantité négative' => [['service_code' => 'CASIER_JUDICIAIRE', 'quantity' => -2], 'quantity'],
            'quantité au-dessus du max' => [['service_code' => 'CASIER_JUDICIAIRE', 'quantity' => 11], 'quantity'],
            'quantité décimale' => [['service_code' => 'CASIER_JUDICIAIRE', 'quantity' => 1.5], 'quantity'],
            'quantité textuelle' => [['service_code' => 'CASIER_JUDICIAIRE', 'quantity' => 'deux'], 'quantity'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[Test]
    #[DataProvider('invalidPayloads')]
    public function it_rejects_invalid_payloads(array $payload, string $field): void
    {
        $this->postJson('/api/v1/service-requests', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('service_requests', 0);
    }

    #[Test]
    public function it_rejects_inactive_services(): void
    {
        Service::factory()->inactive()->create(['code' => 'ANCIEN_ACTE']);

        $this->postJson('/api/v1/service-requests', ['service_code' => 'ANCIEN_ACTE', 'quantity' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_code' => "Ce service n'existe pas ou n'est plus disponible."]);
    }

    #[Test]
    public function it_respects_the_configured_max_quantity(): void
    {
        config(['service_requests.max_quantity' => 2]);

        $this->postJson('/api/v1/service-requests', ['service_code' => 'CASIER_JUDICIAIRE', 'quantity' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity' => 'La quantité ne peut pas dépasser 2.']);
    }

    #[Test]
    public function it_retries_when_a_reference_collides(): void
    {
        $existing = ServiceRequest::factory()->create(['code' => 'DEM-AAAAAAAAAA']);

        $this->mock(ReferenceGenerator::class, function (MockInterface $mock) use ($existing): void {
            $mock->shouldReceive('generate')->twice()->andReturn($existing->code, 'DEM-BBBBBBBBBB');
        });

        $this->postJson('/api/v1/service-requests', ['service_code' => 'CASIER_JUDICIAIRE', 'quantity' => 1])
            ->assertCreated()
            ->assertJsonPath('data.code', 'DEM-BBBBBBBBBB');
    }

    #[Test]
    public function the_database_rejects_an_inconsistent_amount(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('service_requests_amount_consistent');

        ServiceRequest::factory()->create(['unit_price' => 1000, 'quantity' => 2, 'fees' => 100, 'amount' => 1]);
    }

    #[Test]
    public function it_limits_the_creation_rate_per_client(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/service-requests', ['service_code' => 'CASIER_JUDICIAIRE', 'quantity' => 1])->assertCreated();
        }

        $this->postJson('/api/v1/service-requests', ['service_code' => 'CASIER_JUDICIAIRE', 'quantity' => 1])
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Trop de requêtes, veuillez réessayer dans un instant.');
    }
}
