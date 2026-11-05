<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Archive;

use RuntimeException;

/**
 * An archive exceeded a safety limit: too many entries, too many decompressed bytes or an unsafe name.
 */
final class ArchiveLimitExceeded extends RuntimeException {}
