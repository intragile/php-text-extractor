<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Support;

use RuntimeException;

/**
 * Parsing ran past Options::$maxParseSeconds.
 *
 * Thrown from deep inside a parser loop so that every extractor stops the same way; the
 * TextExtractor turns it into a Failed result with Reason::ParseTimeout.
 *
 * @internal
 */
final class ParseTimedOut extends RuntimeException {}
