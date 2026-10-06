<?php

namespace App\Models;

use App\Enums\PaymentOperator;
use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'service_request_id',
        'phone_number',
        'operator',
        'amount',
        'provider_payment_id',
        'status',
        'failure_reason',
        'status_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'operator' => PaymentOperator::class,
            'status' => PaymentStatus::class,
            'status_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ServiceRequest, $this> */
    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', PaymentStatus::Pending);
    }

    /** Numéro masqué pour l'affichage : 01 •• •• •• 05. */
    public function maskedPhoneNumber(): string
    {
        return substr($this->phone_number, 0, 2).' •• •• •• '.substr($this->phone_number, -2);
    }
}
