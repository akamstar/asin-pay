<?php

namespace Tests\Feature;

use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceCatalogTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_lists_only_active_services_with_pricing_metadata(): void
    {
        config(['service_requests.fees' => 150, 'service_requests.max_quantity' => 5]);
        Service::factory()->create(['code' => 'ACTE_NAISSANCE', 'title' => 'Acte de naissance', 'price' => 1000]);
        Service::factory()->inactive()->create(['code' => 'ANCIEN_ACTE']);

        $this->getJson('/api/v1/services')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0', ['code' => 'ACTE_NAISSANCE', 'title' => 'Acte de naissance', 'price' => 1000])
            ->assertJsonPath('meta', ['fees' => 150, 'max_quantity' => 5, 'currency' => 'XOF']);
    }

    #[Test]
    public function the_seeder_provides_the_expected_catalogue_and_is_idempotent(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(
            ['ACTE_NAISSANCE' => 1000, 'CASIER_JUDICIAIRE' => 1500, 'CERTIFICAT_RESIDENCE' => 500],
            Service::orderBy('code')->pluck('price', 'code')->all(),
        );
    }
}
