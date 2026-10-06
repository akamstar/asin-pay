<?php

namespace Tests\Unit;

use App\Support\ReferenceGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ReferenceGeneratorTest extends TestCase
{
    #[Test]
    public function it_generates_references_matching_the_public_format(): void
    {
        $reference = (new ReferenceGenerator)->generate();

        $this->assertMatchesRegularExpression('/^'.ReferenceGenerator::regex().'$/', $reference);
        $this->assertMatchesRegularExpression('/^DEM-[0-9A-HJKMNP-TV-Z]{10}$/', $reference);
    }

    #[Test]
    public function it_generates_distinct_references(): void
    {
        $generator = new ReferenceGenerator;
        $references = array_map(fn (): string => $generator->generate(), range(1, 1000));

        $this->assertCount(1000, array_unique($references));
    }
}
