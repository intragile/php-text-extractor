<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Extractors;

/**
 * Checks the pixel dimensions of every page or frame of an image by reading only its headers.
 *
 * TIFF and GIF files can hold several images, and getimagesize() reports only the first one (for
 * GIF only the logical screen, which a frame may exceed). Tesseract decodes every TIFF page, so a
 * small first page must not hide a huge later one. Headers that cannot be parsed are not treated
 * as a reason to refuse the file: Tesseract then reports the problem itself.
 *
 * @internal
 */
final class ImageGeometry
{
    /** Directories a TIFF may chain before the file is refused as impossible to verify. */
    public const MAX_TIFF_DIRECTORIES = 10000;

    /** Total directory entries examined across all TIFF pages, bounding the work per file. */
    private const MAX_TIFF_ENTRIES = 1000000;

    /** libtiff refuses directories with more entries than this. */
    private const MAX_ENTRIES_PER_DIRECTORY = 65535;

    private const TIFF_IMAGE_WIDTH = 256;

    private const TIFF_IMAGE_LENGTH = 257;

    private const TIFF_SHORT = 3;

    private const TIFF_LONG = 4;

    private const TIFF_LONG8 = 16;

    private const GIF_CHUNK = 65536;

    /**
     * @return string|null why the image exceeds the limit, or null when it does not or cannot be determined
     */
    public static function oversized(string $path, int $maxPixels): ?string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $magic = (string) fread($handle, 6);

            if (in_array(substr($magic, 0, 4), ["II\x2A\x00", "MM\x00\x2A", "II\x2B\x00", "MM\x00\x2B"], true)) {
                return self::tiff($handle, $magic[0] === 'M', $magic[2] === "\x2B" || $magic[3] === "\x2B", $maxPixels);
            }

            if ($magic === 'GIF87a' || $magic === 'GIF89a') {
                return self::gif($handle, $maxPixels);
            }
        } finally {
            fclose($handle);
        }

        $size = @getimagesize($path);

        if (is_array($size)) {
            return self::check('The image', $size[0], $size[1], $maxPixels);
        }

        return null;
    }

    /**
     * @param  resource  $handle
     */
    private static function tiff($handle, bool $bigEndian, bool $bigTiff, int $maxPixels): ?string
    {
        $headerSize = $bigTiff ? 16 : 8;
        $header = self::read($handle, 0, $headerSize);

        if ($header === null || ($bigTiff && self::unsigned(substr($header, 4, 2), $bigEndian) !== 8)) {
            return null;
        }

        $offset = self::unsigned(substr($header, $bigTiff ? 8 : 4), $bigEndian);
        $countSize = $bigTiff ? 8 : 2;
        $entrySize = $bigTiff ? 20 : 12;
        $seen = [];
        $page = 0;
        $entries = 0;

        while ($offset !== 0) {
            // A directory inside the header or one already visited would make the chain endless.
            if ($offset < $headerSize || isset($seen[$offset])) {
                return null;
            }

            $seen[$offset] = true;

            if (++$page > self::MAX_TIFF_DIRECTORIES) {
                return sprintf('The image has more than %d pages; they cannot all be checked against the pixel limit.', self::MAX_TIFF_DIRECTORIES);
            }

            $countBytes = self::read($handle, $offset, $countSize);
            $count = $countBytes === null ? 0 : self::unsigned($countBytes, $bigEndian);

            if ($count < 1 || $count > self::MAX_ENTRIES_PER_DIRECTORY) {
                return null;
            }

            $entries += $count;

            if ($entries > self::MAX_TIFF_ENTRIES) {
                return sprintf('The image has more than %d directory entries; they cannot all be checked against the pixel limit.', self::MAX_TIFF_ENTRIES);
            }

            $table = self::read($handle, $offset + $countSize, $count * $entrySize + ($bigTiff ? 8 : 4));

            if ($table === null) {
                return null;
            }

            [$width, $height] = self::tiffDimensions($table, $count, $bigEndian, $bigTiff);
            $reason = self::check(sprintf('Page %d of the image', $page), $width, $height, $maxPixels);

            if ($reason !== null) {
                return $reason;
            }

            $offset = self::unsigned(substr($table, $count * $entrySize), $bigEndian);
        }

        return null;
    }

    /**
     * Reads ImageWidth and ImageLength from a directory; tags are scanned in full because a file
     * with unsorted tags is still accepted by libtiff.
     *
     * @return array{int, int}
     */
    private static function tiffDimensions(string $table, int $count, bool $bigEndian, bool $bigTiff): array
    {
        $entrySize = $bigTiff ? 20 : 12;
        $valueAt = $bigTiff ? 12 : 8;
        $width = 0;
        $height = 0;

        for ($i = 0; $i < $count; $i++) {
            $entry = substr($table, $i * $entrySize, $entrySize);
            $tag = self::unsigned(substr($entry, 0, 2), $bigEndian);

            if ($tag !== self::TIFF_IMAGE_WIDTH && $tag !== self::TIFF_IMAGE_LENGTH) {
                continue;
            }

            $type = self::unsigned(substr($entry, 2, 2), $bigEndian);
            $value = match ($type) {
                self::TIFF_SHORT => self::unsigned(substr($entry, $valueAt, 2), $bigEndian),
                self::TIFF_LONG => self::unsigned(substr($entry, $valueAt, 4), $bigEndian),
                self::TIFF_LONG8 => $bigTiff ? self::unsigned(substr($entry, $valueAt, 8), $bigEndian) : 0,
                default => 0,
            };

            if ($tag === self::TIFF_IMAGE_WIDTH) {
                $width = $value;
            } else {
                $height = $value;
            }
        }

        return [$width, $height];
    }

    /**
     * Walks the GIF block structure, skipping image data sub-blocks without decoding them.
     *
     * @param  resource  $handle
     */
    private static function gif($handle, int $maxPixels): ?string
    {
        $screen = self::read($handle, 6, 7);

        if ($screen === null) {
            return null;
        }

        $reason = self::check('The image', self::unsigned(substr($screen, 0, 2), false), self::unsigned(substr($screen, 2, 2), false), $maxPixels);

        if ($reason !== null) {
            return $reason;
        }

        $window = ['start' => 0, 'data' => ''];
        $flags = ord($screen[4]);
        $position = 13 + (($flags & 0x80) !== 0 ? 3 * (2 << ($flags & 0x07)) : 0);
        $frame = 0;

        while (true) {
            $introducer = self::gifBytes($handle, $window, $position++, 1);
            $introducer = $introducer === null ? null : ord($introducer);

            if ($introducer === 0x21) {
                // Extension: a label byte followed by data sub-blocks.
                $position = self::skipSubBlocks($handle, $window, $position + 1);
            } elseif ($introducer === 0x2C) {
                $descriptor = self::gifBytes($handle, $window, $position, 9);

                if ($descriptor === null) {
                    return null;
                }

                $reason = self::check(sprintf('Frame %d of the image', ++$frame), self::unsigned(substr($descriptor, 4, 2), false), self::unsigned(substr($descriptor, 6, 2), false), $maxPixels);

                if ($reason !== null) {
                    return $reason;
                }

                $flags = ord($descriptor[8]);
                $position += 9 + (($flags & 0x80) !== 0 ? 3 * (2 << ($flags & 0x07)) : 0);
                // The LZW minimum code size precedes the image data sub-blocks.
                $position = self::skipSubBlocks($handle, $window, $position + 1);
            } else {
                // The trailer, the end of the file or an unknown block: nothing more to check.
                return null;
            }

            if ($position === null) {
                return null;
            }
        }
    }

    /**
     * Returns the position after a chain of data sub-blocks, or null when the file ends first.
     *
     * @param  resource  $handle
     * @param  array{start: int, data: string}  $window
     */
    private static function skipSubBlocks($handle, array &$window, int $position): ?int
    {
        while (true) {
            $size = self::gifBytes($handle, $window, $position, 1);

            if ($size === null) {
                return null;
            }

            $position += 1 + ord($size);

            if ($size === "\x00") {
                return $position;
            }
        }
    }

    /**
     * Reads through a window of the file so that walking many small sub-blocks stays cheap.
     *
     * @param  resource  $handle
     * @param  array{start: int, data: string}  $window
     */
    private static function gifBytes($handle, array &$window, int $position, int $length): ?string
    {
        $offset = $position - $window['start'];

        if ($offset < 0 || $offset + $length > strlen($window['data'])) {
            $data = fseek($handle, $position) === 0 ? fread($handle, max($length, self::GIF_CHUNK)) : false;

            if (! is_string($data) || strlen($data) < $length) {
                return null;
            }

            $window = ['start' => $position, 'data' => $data];
            $offset = 0;
        }

        return substr($window['data'], $offset, $length);
    }

    private static function check(string $subject, int $width, int $height, int $maxPixels): ?string
    {
        if ($width < 1 || $height < 1 || $width * $height <= $maxPixels) {
            return null;
        }

        return sprintf('%s has %d x %d pixels, above the limit of %d.', $subject, $width, $height, $maxPixels);
    }

    /**
     * @param  resource  $handle
     */
    private static function read($handle, int $offset, int $length): ?string
    {
        if ($length < 1 || fseek($handle, $offset) !== 0) {
            return null;
        }

        $bytes = fread($handle, $length);

        return is_string($bytes) && strlen($bytes) === $length ? $bytes : null;
    }

    /**
     * Decodes an unsigned integer of 2, 4 or 8 bytes; values beyond PHP_INT_MAX saturate.
     */
    private static function unsigned(string $bytes, bool $bigEndian): int
    {
        if (! $bigEndian) {
            $bytes = strrev($bytes);
        }

        $value = 0;

        foreach (str_split($bytes) as $byte) {
            if ($value > (PHP_INT_MAX >> 8)) {
                return PHP_INT_MAX;
            }

            $value = ($value << 8) | ord($byte);
        }

        return $value;
    }
}
