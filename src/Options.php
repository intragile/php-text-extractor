<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor;

use IntrAgile\TextExtractor\Output\Encoding;
use InvalidArgumentException;

/**
 * Limits and tool settings for an extraction.
 *
 * The defaults are chosen so that a single hostile or oversized file cannot exhaust the memory of
 * a small worker container.
 */
final class Options
{
    private const POSITIVE = [
        'maxInputBytes',
        'maxOutputBytes',
        'maxArchiveEntryBytes',
        'maxArchiveTotalBytes',
        'maxCompressionRatio',
        'maxArchiveEntries',
        'maxImagePixels',
        'processTimeout',
    ];

    /**
     * @param  int  $processMemoryMb  Address-space cap for each external tool; 0 disables the cap.
     * @param  string  $ocrLanguages  Tesseract language list, e.g. "hun+eng".
     * @param  string  $fallbackEncoding  Assumed encoding of text that is not valid UTF-8.
     */
    public function __construct(
        public readonly int $maxInputBytes = 104857600,
        public readonly int $maxOutputBytes = 10485760,
        public readonly int $maxArchiveEntryBytes = 268435456,
        public readonly int $maxArchiveTotalBytes = 536870912,
        public readonly int $maxCompressionRatio = 200,
        public readonly int $maxArchiveEntries = 10000,
        public readonly int $maxImagePixels = 16000000,
        public readonly int $processTimeout = 60,
        public readonly int $processMemoryMb = 512,
        public readonly ?string $pdftotextBinary = null,
        public readonly ?string $tesseractBinary = null,
        public readonly string $ocrLanguages = 'eng',
        public readonly string $fallbackEncoding = 'Windows-1252',
    ) {
        foreach (self::POSITIVE as $name) {
            if ($this->{$name} < 1) {
                throw new InvalidArgumentException(sprintf('Option "%s" must be a positive integer.', $name));
            }
        }

        if ($processMemoryMb < 0) {
            throw new InvalidArgumentException('Option "processMemoryMb" must be zero or a positive integer.');
        }

        if (preg_match('/^[a-z_]{3,}(\+[a-z_]{3,})*$/i', $ocrLanguages) !== 1) {
            throw new InvalidArgumentException(sprintf('Option "ocrLanguages" is not a valid Tesseract language list: "%s".', $ocrLanguages));
        }

        if (! Encoding::isSupported($fallbackEncoding)) {
            throw new InvalidArgumentException(sprintf('Option "fallbackEncoding" names an unsupported encoding: "%s".', $fallbackEncoding));
        }
    }

    /**
     * Returns a copy with the given options replaced.
     *
     * @param  array<string, int|string|null>  $overrides  Keyed by constructor parameter name.
     */
    public function with(array $overrides): self
    {
        $current = get_object_vars($this);

        foreach (array_keys($overrides) as $name) {
            if (! array_key_exists($name, $current)) {
                throw new InvalidArgumentException(sprintf('Unknown option "%s".', $name));
            }
        }

        /** @var array{maxInputBytes: int, maxOutputBytes: int, maxArchiveEntryBytes: int, maxArchiveTotalBytes: int, maxCompressionRatio: int, maxArchiveEntries: int, maxImagePixels: int, processTimeout: int, processMemoryMb: int, pdftotextBinary: ?string, tesseractBinary: ?string, ocrLanguages: string, fallbackEncoding: string} $merged */
        $merged = array_merge($current, $overrides);

        return new self(...$merged);
    }
}
