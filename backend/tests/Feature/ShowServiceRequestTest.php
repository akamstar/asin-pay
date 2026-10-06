<?php

namespace Tests\Feature;

use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShowServiceRequestTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_a_request_by_its_public_reference(): void
    {
        $serviceRequest = ServiceRequest::factory()->create();

        $this->getJson("/api/v1/service-requests/{$serviceRequest->code}")
            ->assertOk()
            ->assertJsonPath('data.code', $serviceRequest->code)
            ->assertJsonPath('data.amount', $serviceRequest->amount)
            ->assertJsonMissingPath('data.id');
    }

    #[Test]
    public function it_returns_404_for_an_unknown_reference(): void
    {
        $this->getJson('/api/v1/service-requests/DEM-0000000000')
            ->assertNotFound()
            ->assertJsonPath('message', 'Ressource introuvable.');
    }

    #[Test]
    public function it_returns_404_for_a_malformed_reference_or_an_internal_id(): void
    {
        $serviceRequest = ServiceRequest::factory()->create();

        $this->getJson("/api/v1/service-requests/{$serviceRequest->id}")->assertNotFound();
        $this->getJson('/api/v1/service-requests/dem-abc')->assertNotFound();
    }
}
