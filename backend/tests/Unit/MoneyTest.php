<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    #[Test]
    public function it_formats_amounts_in_fcfa_with_thousands_separator(): void
    {
        $this->assertSame("5\u{202F}000\u{00A0}F", Money::format(5000));
        $this->assertSame("1\u{202F}234\u{202F}567\u{00A0}F", Money::format(1234567));
        $this->assertSame("100\u{00A0}F", Money::format(100));
    }
}
