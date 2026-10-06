<?php

namespace App\Models;

use App\Enums\ServiceRequestStatus;
use Database\Factories\ServiceRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceRequest extends Model
{
    /** @use HasFactory<ServiceRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'service_id',
        'unit_price',
        'quantity',
        'fees',
        'amount',
        'status',
        'status_at',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'quantity' => 'integer',
            'fees' => 'integer',
            'amount' => 'integer',
            'status' => ServiceRequestStatus::class,
            'status_at' => 'datetime',
        ];
    }

    /** La demande est retrouvée par sa référence publique, jamais par son id. */
    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasOne<Payment, $this> */
    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function isPayable(): bool
    {
        return $this->status === ServiceRequestStatus::PendingPayment;
    }
}
