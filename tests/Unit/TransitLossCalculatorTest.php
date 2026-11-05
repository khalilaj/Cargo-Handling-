<?php

namespace Tests\Unit;

use App\Domain\Cargo\Services\TransitLossCalculator;
use PHPUnit\Framework\TestCase;

class TransitLossCalculatorTest extends TestCase
{
    public function test_applies_half_a_percent_loss(): void
    {
        $c = new TransitLossCalculator;

        $this->assertSame(5_000, $c->lossFor(1_000_000));
        $this->assertSame(995_000, $c->netFor(1_000_000));
    }

    public function test_rounds_half_up_to_whole_kg(): void
    {
        $c = new TransitLossCalculator;

        $this->assertSame(1, $c->lossFor(100));   // 0.5 -> 1
        $this->assertSame(0, $c->lossFor(99));    // 0.495 -> 0
        $this->assertSame(99, $c->netFor(100));
    }
}
