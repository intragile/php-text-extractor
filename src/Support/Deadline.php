<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Support;

use Closure;
use IntrAgile\TextExtractor\Options;

/**
 * A cooperative time limit for parsing done in PHP.
 *
 * PHP cannot interrupt its own code from outside, so parser loops call tick() on every step and
 * the deadline throws ParseTimedOut once the limit has passed. Reading the clock costs far more
 * than a loop step, so tick() only looks at it once every STRIDE calls; check() always looks and
 * suits loops whose steps are already expensive, such as one per 64 KiB chunk.
 *
 * The clock is a monotonic nanosecond counter, so wall-clock adjustments cannot cut a parse short
 * or extend it.
 *
 * @internal
 */
final class Deadline
{
    public const STRIDE = 4096;

    private const NS_PER_SECOND = 1_000_000_000;

    /** @var Closure(): int */
    private Closure $clock;

    private int $expiresAt;

    private int $countdown;

    /**
     * @param  Closure(): int  $clock
     */
    private function __construct(Closure $clock, private readonly int $seconds, private readonly int $stride)
    {
        $this->clock = $clock;
        $this->countdown = $stride;
        $now = $clock();
        $budget = $seconds > intdiv(PHP_INT_MAX, self::NS_PER_SECOND) ? PHP_INT_MAX : $seconds * self::NS_PER_SECOND;
        // Saturate rather than wrap: an overflowing sum would put the deadline in the past.
        $this->expiresAt = $now > PHP_INT_MAX - $budget ? PHP_INT_MAX : $now + $budget;
    }

    /**
     * @param  (Closure(): int)|null  $clock  Monotonic nanoseconds; defaults to hrtime().
     * @param  int  $stride  Number of tick() calls between two clock readings.
     */
    public static function start(int $seconds, ?Closure $clock = null, int $stride = self::STRIDE): self
    {
        return new self($clock ?? static fn (): int => (int) hrtime(true), $seconds, max(1, $stride));
    }

    /**
     * @param  (Closure(): int)|null  $clock  Monotonic nanoseconds; defaults to hrtime().
     */
    public static function fromOptions(Options $options, ?Closure $clock = null): self
    {
        return self::start($options->maxParseSeconds, $clock);
    }

    /**
     * Counts one parser step and checks the clock once every stride steps.
     *
     * @throws ParseTimedOut
     */
    public function tick(): void
    {
        if (--$this->countdown > 0) {
            return;
        }

        $this->countdown = $this->stride;
        $this->check();
    }

    /**
     * @throws ParseTimedOut
     */
    public function check(): void
    {
        if ($this->isExpired()) {
            throw new ParseTimedOut(sprintf('Parsing did not finish within %d seconds.', $this->seconds));
        }
    }

    public function isExpired(): bool
    {
        return ($this->clock)() > $this->expiresAt;
    }

    public function seconds(): int
    {
        return $this->seconds;
    }
}
