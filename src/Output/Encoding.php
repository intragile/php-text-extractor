<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Output;

use ValueError;

/**
 * Converts legacy single-byte text to UTF-8.
 *
 * mbstring has no Windows-1250 (Central European) table and iconv is unreliable on musl-based
 * images, so that code page is decoded from a built-in table; everything else goes through mbstring.
 */
final class Encoding
{
    /**
     * Windows-1250 bytes 0x80-0xFF; null marks bytes the code page leaves undefined.
     *
     * @var list<string|null>
     */
    private const WINDOWS_1250 = [
        '€', null, '‚', null, '„', '…', '†', '‡', null, '‰', 'Š', '‹', 'Ś', 'Ť', 'Ž', 'Ź',
        null, '‘', '’', '“', '”', '•', '–', '—', null, '™', 'š', '›', 'ś', 'ť', 'ž', 'ź',
        ' ', 'ˇ', '˘', 'Ł', '¤', 'Ą', '¦', '§', '¨', '©', 'Ş', '«', '¬', '­', '®', 'Ż',
        '°', '±', '˛', 'ł', '´', 'µ', '¶', '·', '¸', 'ą', 'ş', '»', 'Ľ', '˝', 'ľ', 'ż',
        'Ŕ', 'Á', 'Â', 'Ă', 'Ä', 'Ĺ', 'Ć', 'Ç', 'Č', 'É', 'Ę', 'Ë', 'Ě', 'Í', 'Î', 'Ď',
        'Đ', 'Ń', 'Ň', 'Ó', 'Ô', 'Ő', 'Ö', '×', 'Ř', 'Ů', 'Ú', 'Ű', 'Ü', 'Ý', 'Ţ', 'ß',
        'ŕ', 'á', 'â', 'ă', 'ä', 'ĺ', 'ć', 'ç', 'č', 'é', 'ę', 'ë', 'ě', 'í', 'î', 'ď',
        'đ', 'ń', 'ň', 'ó', 'ô', 'ő', 'ö', '÷', 'ř', 'ů', 'ú', 'ű', 'ü', 'ý', 'ţ', '˙',    ];

    private const REPLACEMENT = "\u{FFFD}";

    public static function isSupported(string $encoding): bool
    {
        if (self::isWindows1250($encoding)) {
            return true;
        }

        try {
            mb_convert_encoding('', 'UTF-8', $encoding);
        } catch (ValueError) {
            return false;
        }

        return true;
    }

    public static function toUtf8(string $bytes, string $from): string
    {
        if (! self::isWindows1250($from)) {
            $converted = mb_convert_encoding($bytes, 'UTF-8', $from);

            return is_string($converted) ? $converted : '';
        }

        if (preg_match('/[\x80-\xFF]/', $bytes) !== 1) {
            return $bytes;
        }

        $out = '';
        $length = strlen($bytes);

        for ($i = 0; $i < $length; $i++) {
            $byte = ord($bytes[$i]);
            $out .= $byte < 0x80 ? $bytes[$i] : (self::WINDOWS_1250[$byte - 0x80] ?? self::REPLACEMENT);
        }

        return $out;
    }

    private static function isWindows1250(string $encoding): bool
    {
        return in_array(strtolower($encoding), ['windows-1250', 'cp1250', 'win-1250'], true);
    }
}
