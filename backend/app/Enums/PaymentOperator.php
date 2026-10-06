<?php

namespace App\Enums;

/** Opérateurs de mobile money proposés à l'usager. */
enum PaymentOperator: string
{
    case Mtn = 'MTN';
    case Moov = 'MOOV';
    case Celtiis = 'CELTIIS';

    public function label(): string
    {
        return match ($this) {
            self::Mtn => 'MTN Mobile Money',
            self::Moov => 'Moov Money',
            self::Celtiis => 'Celtiis Cash',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $operator): array => ['value' => $operator->value, 'label' => $operator->label()], self::cases());
    }
}
