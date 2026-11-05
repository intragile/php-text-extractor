<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Output;

final class TextNormalizer
{
    public function __construct(private readonly string $fallbackEncoding) {}

    /**
     * Decodes raw bytes of unknown encoding: BOM first, then UTF-8 validation, then the fallback.
     */
    public function toUtf8(string $bytes): string
    {
        if (strncmp($bytes, "\xEF\xBB\xBF", 3) === 0) {
            $bytes = substr($bytes, 3);
        } elseif (strncmp($bytes, "\xFF\xFE", 2) === 0) {
            return self::convert(substr($bytes, 2), 'UTF-16LE');
        } elseif (strncmp($bytes, "\xFE\xFF", 2) === 0) {
            return self::convert(substr($bytes, 2), 'UTF-16BE');
        }

        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }

        return Encoding::toUtf8($bytes, $this->fallbackEncoding);
    }

    /**
     * Produces clean UTF-8: no control characters except tab and newline, unified line endings,
     * no trailing whitespace on lines and at most one empty line in a row.
     */
    public function normalize(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($text, 'UTF-8');
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $text = (string) preg_replace('/[ \t\x{00A0}]+$/mu', '', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    private static function convert(string $bytes, string $from): string
    {
        $converted = mb_convert_encoding($bytes, 'UTF-8', $from);

        return is_string($converted) ? $converted : '';
    }
}
