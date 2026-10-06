<?php

namespace App\Http\Resources;

use App\Models\ServiceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceRequest
 */
class ServiceRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'service' => [
                'code' => $this->service->code,
                'title' => $this->service->title,
            ],
            'unit_price' => $this->unit_price,
            'quantity' => $this->quantity,
            'subtotal' => $this->unit_price * $this->quantity,
            'fees' => $this->fees,
            'amount' => $this->amount,
            'currency' => 'XOF',
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_at' => $this->status_at->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'links' => [
                'self' => route('api.service-requests.show', $this->resource),
                'page' => route('service-requests.show', $this->resource),
            ],
        ];
    }
}
