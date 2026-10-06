<?php

namespace Tests\Unit;

use App\Services\PricingCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PricingCalculatorTest extends TestCase
{
    #[Test]
    public function it_adds_fees_to_unit_price_times_quantity(): void
    {
        $pricing = (new PricingCalculator)->calculate(unitPrice: 1500, quantity: 3, fees: 100);

        $this->assertSame(1500, $pricing->unitPrice);
        $this->assertSame(3, $pricing->quantity);
        $this->assertSame(4500, $pricing->subtotal);
        $this->assertSame(100, $pricing->fees);
        $this->assertSame(4600, $pricing->total);
    }

    #[Test]
    public function it_accepts_zero_fees(): void
    {
        $this->assertSame(500, (new PricingCalculator)->calculate(500, 1, 0)->total);
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function invalidInputs(): array
    {
        return [
            'prix nul' => [0, 1, 100],
            'prix négatif' => [-100, 1, 100],
            'quantité nulle' => [1000, 0, 100],
            'quantité négative' => [1000, -1, 100],
            'frais négatifs' => [1000, 1, -1],
        ];
    }

    #[Test]
    #[DataProvider('invalidInputs')]
    public function it_rejects_invalid_inputs(int $unitPrice, int $quantity, int $fees): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PricingCalculator)->calculate($unitPrice, $quantity, $fees);
    }
}
