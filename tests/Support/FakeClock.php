<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Support;

use Closure;

/**
 * A monotonic nanosecond clock that tests move by hand, so time limits can be exercised without
 * waiting.
 */
final class FakeClock
{
    private const HOUR_NS = 3_600_000_000_000;

    public int $readings = 0;

    private ?int $jumpAfter = null;

    public function __construct(private int $now = 0) {}

    /**
     * A clock that stands still for the first $readings readings and then is an hour later, so
     * any parse-time limit expires at the next check after that.
     */
    public static function expiringAfter(int $readings): self
    {
        $clock = new self;
        $clock->jumpAfter = $readings;

        return $clock;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds * 1_000_000_000;
    }

    public function read(): int
    {
        $this->readings++;

        if ($this->jumpAfter !== null && $this->readings > $this->jumpAfter) {
            return $this->now + self::HOUR_NS;
        }

        return $this->now;
    }

    /**
     * @return Closure(): int
     */
    public function closure(): Closure
    {
        return $this->read(...);
    }
}
