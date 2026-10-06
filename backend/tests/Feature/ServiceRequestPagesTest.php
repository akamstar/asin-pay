<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceRequestPagesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_form_page_lists_active_services(): void
    {
        Service::factory()->create(['title' => 'Acte de naissance']);
        Service::factory()->inactive()->create(['title' => 'Ancien acte']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Nouvelle demande')
            ->assertSee('Acte de naissance')
            ->assertDontSee('Ancien acte')
            ->assertSee('Demander');
    }

    #[Test]
    public function the_summary_page_shows_the_reference_and_amount_to_pay(): void
    {
        $service = Service::factory()->create(['title' => 'Acte de naissance', 'price' => 1000]);
        $serviceRequest = ServiceRequest::factory()->for($service)->create([
            'unit_price' => 1000,
            'quantity' => 2,
            'fees' => 100,
            'amount' => 2100,
        ]);

        $this->get("/demandes/{$serviceRequest->code}")
            ->assertOk()
            ->assertSee($serviceRequest->code)
            ->assertSee('Acte de naissance')
            ->assertSee("2\u{202F}100\u{00A0}F", false)
            ->assertSee('En attente de paiement');
    }

    #[Test]
    public function the_summary_page_returns_404_for_an_unknown_reference(): void
    {
        $this->get('/demandes/DEM-0000000000')->assertNotFound();
    }
}
