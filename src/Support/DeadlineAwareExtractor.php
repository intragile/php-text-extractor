<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Support;

use IntrAgile\TextExtractor\Contracts\Extractor;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Result;

/**
 * A built-in extractor that parses in PHP and honours a deadline shared with its caller.
 *
 * TextExtractor starts the deadline when an extraction begins, so the limit covers the whole call
 * rather than restarting inside each extractor. Calling extract() directly starts a deadline from
 * the options instead.
 *
 * @internal
 */
interface DeadlineAwareExtractor extends Extractor
{
    /**
     * @throws ParseTimedOut when the deadline passes before parsing finishes
     */
    public function extractWithin(string $path, Format $format, Options $options, Deadline $deadline): Result;
}
