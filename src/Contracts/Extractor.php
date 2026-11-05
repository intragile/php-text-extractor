<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Contracts;

use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Result;

/**
 * Turns one or more formats into text.
 *
 * Implementations return raw text through Result::extracted() and should stop reading once
 * $options->maxOutputBytes is reached; normalisation and the empty-text check are applied by the
 * caller. Problems with the file itself are reported as a non-successful Result. Exceptions are
 * allowed: ArchiveLimitExceeded and CorruptInput map to NotExtractable, anything else to Failed.
 */
interface Extractor
{
    public function supports(Format $format): bool;

    public function extract(string $path, Format $format, Options $options): Result;
}
