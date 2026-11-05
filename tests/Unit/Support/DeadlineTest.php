<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Support;

use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Support\Deadline;
use IntrAgile\TextExtractor\Support\ParseTimedOut;
use IntrAgile\TextExtractor\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;

final class DeadlineTest extends TestCase
{
    public function test_check_passes_until_the_limit_is_reached(): void
    {
        $clock = new FakeClock;
        $deadline = Deadline::start(5, $clock->closure());

        $clock->advance(5);
        $deadline->check();

        $this->assertFalse($deadline->isExpired());
    }

    public function test_check_throws_once_the_limit_has_passed(): void
    {
        $clock = new FakeClock;
        $deadline = Deadline::start(5, $clock->closure());
        $clock->advance(6);

        $this->expectException(ParseTimedOut::class);
        $this->expectExceptionMessage('5 seconds');

        $deadline->check();
    }

    public function test_is_expired_reports_without_throwing(): void
    {
        $clock = new FakeClock;
        $deadline = Deadline::start(1, $clock->closure());
        $clock->advance(2);

        $this->assertTrue($deadline->isExpired());
    }

    public function test_tick_reads_the_clock_only_once_per_stride(): void
    {
        $clock = new FakeClock;
        $deadline = Deadline::start(5, $clock->closure(), 100);
        $startReadings = $clock->readings;

        for ($i = 0; $i < 99; $i++) {
            $deadline->tick();
        }

        $this->assertSame($startReadings, $clock->readings);

        $deadline->tick();

        $this->assertSame($startReadings + 1, $clock->readings);
    }

    public function test_tick_throws_at_the_first_stride_boundary_after_expiry(): void
    {
        $clock = new FakeClock;
        $deadline = Deadline::start(1, $clock->closure(), 10);
        $clock->advance(2);

        for ($i = 0; $i < 9; $i++) {
            $deadline->tick();
        }

        $this->expectException(ParseTimedOut::class);

        $deadline->tick();
    }

    public function test_the_default_stride_spreads_clock_reads(): void
    {
        $clock = new FakeClock;
        $deadline = Deadline::start(5, $clock->closure());

        for ($i = 0; $i < Deadline::STRIDE * 3; $i++) {
            $deadline->tick();
        }

        // One reading when the deadline starts, then one per stride.
        $this->assertSame(4, $clock->readings);
    }

    public function test_from_options_uses_the_parse_time_limit(): void
    {
        $clock = new FakeClock;
        $deadline = Deadline::fromOptions(new Options(maxParseSeconds: 3), $clock->closure());

        $this->assertSame(3, $deadline->seconds());

        $clock->advance(3);
        $this->assertFalse($deadline->isExpired());

        $clock->advance(1);
        $this->assertTrue($deadline->isExpired());
    }

    public function test_a_huge_limit_does_not_overflow_into_the_past(): void
    {
        $clock = new FakeClock(PHP_INT_MAX - 10);
        $deadline = Deadline::start(PHP_INT_MAX, $clock->closure());

        $this->assertFalse($deadline->isExpired());
    }

    public function test_the_system_clock_is_used_by_default(): void
    {
        $deadline = Deadline::start(60);

        $deadline->check();
        $deadline->tick();

        $this->assertFalse($deadline->isExpired());
    }
}
