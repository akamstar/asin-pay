<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'PENDING';
    case Success = 'SUCCESS';
    case Failed = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En cours',
            self::Success => 'Réussi',
            self::Failed => 'Échoué',
        };
    }

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
