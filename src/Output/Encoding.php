<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Output;

use ValueError;

/**
 * Converts legacy single-byte text to UTF-8.
 *
 * mbstring has no table for several Windows and DOS code pages (Central European, Greek, Hebrew,
 * Arabic, Baltic, Vietnamese, Thai and the original IBM PC set) and iconv is unreliable on
 * musl-based images, so those are decoded from built-in tables; everything else goes through
 * mbstring. The tables follow the Unicode consortium mappings as shipped in Python's codecs.
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

    /**
     * Windows-1253 bytes 0x80-0xFF; null marks bytes the code page leaves undefined.
     *
     * @var list<string|null>
     */
    private const WINDOWS_1253 = [
        '€', null, '‚', 'ƒ', '„', '…', '†', '‡', null, '‰', null, '‹', null, null, null, null,
        null, '‘', '’', '“', '”', '•', '–', '—', null, '™', null, '›', null, null, null, null,
        "\u{00A0}", '΅', 'Ά', '£', '¤', '¥', '¦', '§', '¨', '©', null, '«', '¬', "\u{00AD}", '®', '―',
        '°', '±', '²', '³', '΄', 'µ', '¶', '·', 'Έ', 'Ή', 'Ί', '»', 'Ό', '½', 'Ύ', 'Ώ',
        'ΐ', 'Α', 'Β', 'Γ', 'Δ', 'Ε', 'Ζ', 'Η', 'Θ', 'Ι', 'Κ', 'Λ', 'Μ', 'Ν', 'Ξ', 'Ο',
        'Π', 'Ρ', null, 'Σ', 'Τ', 'Υ', 'Φ', 'Χ', 'Ψ', 'Ω', 'Ϊ', 'Ϋ', 'ά', 'έ', 'ή', 'ί',
        'ΰ', 'α', 'β', 'γ', 'δ', 'ε', 'ζ', 'η', 'θ', 'ι', 'κ', 'λ', 'μ', 'ν', 'ξ', 'ο',
        'π', 'ρ', 'ς', 'σ', 'τ', 'υ', 'φ', 'χ', 'ψ', 'ω', 'ϊ', 'ϋ', 'ό', 'ύ', 'ώ', null,
    ];

    /**
     * Windows-1255 bytes 0x80-0xFF; null marks bytes the code page leaves undefined.
     *
     * @var list<string|null>
     */
    private const WINDOWS_1255 = [
        '€', null, '‚', 'ƒ', '„', '…', '†', '‡', 'ˆ', '‰', null, '‹', null, null, null, null,
        null, '‘', '’', '“', '”', '•', '–', '—', '˜', '™', null, '›', null, null, null, null,
        "\u{00A0}", '¡', '¢', '£', '₪', '¥', '¦', '§', '¨', '©', '×', '«', '¬', "\u{00AD}", '®', '¯',
        '°', '±', '²', '³', '´', 'µ', '¶', '·', '¸', '¹', '÷', '»', '¼', '½', '¾', '¿',
        "\u{05B0}", "\u{05B1}", "\u{05B2}", "\u{05B3}", "\u{05B4}", "\u{05B5}", "\u{05B6}", "\u{05B7}", "\u{05B8}", "\u{05B9}", null, "\u{05BB}", "\u{05BC}", "\u{05BD}", "\u{05BE}", "\u{05BF}",
        "\u{05C0}", "\u{05C1}", "\u{05C2}", "\u{05C3}", "\u{05F0}", "\u{05F1}", "\u{05F2}", "\u{05F3}", "\u{05F4}", null, null, null, null, null, null, null,
        "\u{05D0}", "\u{05D1}", "\u{05D2}", "\u{05D3}", "\u{05D4}", "\u{05D5}", "\u{05D6}", "\u{05D7}", "\u{05D8}", "\u{05D9}", "\u{05DA}", "\u{05DB}", "\u{05DC}", "\u{05DD}", "\u{05DE}", "\u{05DF}",
        "\u{05E0}", "\u{05E1}", "\u{05E2}", "\u{05E3}", "\u{05E4}", "\u{05E5}", "\u{05E6}", "\u{05E7}", "\u{05E8}", "\u{05E9}", "\u{05EA}", null, null, "\u{200E}", "\u{200F}", null,
    ];

    /**
     * Windows-1256 bytes 0x80-0xFF; null marks bytes the code page leaves undefined.
     *
     * @var list<string|null>
     */
    private const WINDOWS_1256 = [
        '€', "\u{067E}", '‚', 'ƒ', '„', '…', '†', '‡', 'ˆ', '‰', "\u{0679}", '‹', 'Œ', "\u{0686}", "\u{0698}", "\u{0688}",
        "\u{06AF}", '‘', '’', '“', '”', '•', '–', '—', "\u{06A9}", '™', "\u{0691}", '›', 'œ', "\u{200C}", "\u{200D}", "\u{06BA}",
        "\u{00A0}", '،', '¢', '£', '¤', '¥', '¦', '§', '¨', '©', "\u{06BE}", '«', '¬', "\u{00AD}", '®', '¯',
        '°', '±', '²', '³', '´', 'µ', '¶', '·', '¸', '¹', "\u{061B}", '»', '¼', '½', '¾', "\u{061F}",
        "\u{06C1}", "\u{0621}", "\u{0622}", "\u{0623}", "\u{0624}", "\u{0625}", "\u{0626}", "\u{0627}", "\u{0628}", "\u{0629}", "\u{062A}", "\u{062B}", "\u{062C}", "\u{062D}", "\u{062E}", "\u{062F}",
        "\u{0630}", "\u{0631}", "\u{0632}", "\u{0633}", "\u{0634}", "\u{0635}", "\u{0636}", '×', "\u{0637}", "\u{0638}", "\u{0639}", "\u{063A}", "\u{0640}", "\u{0641}", "\u{0642}", "\u{0643}",
        'à', "\u{0644}", 'â', "\u{0645}", "\u{0646}", "\u{0647}", "\u{0648}", 'ç', 'è', 'é', 'ê', 'ë', "\u{0649}", "\u{064A}", 'î', 'ï',
        "\u{064B}", "\u{064C}", "\u{064D}", "\u{064E}", 'ô', "\u{064F}", "\u{0650}", '÷', "\u{0651}", 'ù', "\u{0652}", 'û', 'ü', "\u{200E}", "\u{200F}", "\u{06D2}",
    ];

    /**
     * Windows-1257 bytes 0x80-0xFF; null marks bytes the code page leaves undefined.
     *
     * @var list<string|null>
     */
    private const WINDOWS_1257 = [
        '€', null, '‚', null, '„', '…', '†', '‡', null, '‰', null, '‹', null, '¨', 'ˇ', '¸',
        null, '‘', '’', '“', '”', '•', '–', '—', null, '™', null, '›', null, '¯', '˛', null,
        "\u{00A0}", null, '¢', '£', '¤', null, '¦', '§', 'Ø', '©', 'Ŗ', '«', '¬', "\u{00AD}", '®', 'Æ',
        '°', '±', '²', '³', '´', 'µ', '¶', '·', 'ø', '¹', 'ŗ', '»', '¼', '½', '¾', 'æ',
        'Ą', 'Į', 'Ā', 'Ć', 'Ä', 'Å', 'Ę', 'Ē', 'Č', 'É', 'Ź', 'Ė', 'Ģ', 'Ķ', 'Ī', 'Ļ',
        'Š', 'Ń', 'Ņ', 'Ó', 'Ō', 'Õ', 'Ö', '×', 'Ų', 'Ł', 'Ś', 'Ū', 'Ü', 'Ż', 'Ž', 'ß',
        'ą', 'į', 'ā', 'ć', 'ä', 'å', 'ę', 'ē', 'č', 'é', 'ź', 'ė', 'ģ', 'ķ', 'ī', 'ļ',
        'š', 'ń', 'ņ', 'ó', 'ō', 'õ', 'ö', '÷', 'ų', 'ł', 'ś', 'ū', 'ü', 'ż', 'ž', '˙',
    ];

    /**
     * Windows-1258 bytes 0x80-0xFF; null marks bytes the code page leaves undefined.
     *
     * @var list<string|null>
     */
    private const WINDOWS_1258 = [
        '€', null, '‚', 'ƒ', '„', '…', '†', '‡', 'ˆ', '‰', null, '‹', 'Œ', null, null, null,
        null, '‘', '’', '“', '”', '•', '–', '—', '˜', '™', null, '›', 'œ', null, null, 'Ÿ',
        "\u{00A0}", '¡', '¢', '£', '¤', '¥', '¦', '§', '¨', '©', 'ª', '«', '¬', "\u{00AD}", '®', '¯',
        '°', '±', '²', '³', '´', 'µ', '¶', '·', '¸', '¹', 'º', '»', '¼', '½', '¾', '¿',
        'À', 'Á', 'Â', 'Ă', 'Ä', 'Å', 'Æ', 'Ç', 'È', 'É', 'Ê', 'Ë', "\u{0300}", 'Í', 'Î', 'Ï',
        'Đ', 'Ñ', "\u{0309}", 'Ó', 'Ô', 'Ơ', 'Ö', '×', 'Ø', 'Ù', 'Ú', 'Û', 'Ü', 'Ư', "\u{0303}", 'ß',
        'à', 'á', 'â', 'ă', 'ä', 'å', 'æ', 'ç', 'è', 'é', 'ê', 'ë', "\u{0301}", 'í', 'î', 'ï',
        'đ', 'ñ', "\u{0323}", 'ó', 'ô', 'ơ', 'ö', '÷', 'ø', 'ù', 'ú', 'û', 'ü', 'ư', '₫', 'ÿ',
    ];

    /**
     * Windows-874 bytes 0x80-0xFF; null marks bytes the code page leaves undefined.
     *
     * @var list<string|null>
     */
    private const WINDOWS_874 = [
        '€', null, null, null, null, '…', null, null, null, null, null, null, null, null, null, null,
        null, '‘', '’', '“', '”', '•', '–', '—', null, null, null, null, null, null, null, null,
        "\u{00A0}", 'ก', 'ข', 'ฃ', 'ค', 'ฅ', 'ฆ', 'ง', 'จ', 'ฉ', 'ช', 'ซ', 'ฌ', 'ญ', 'ฎ', 'ฏ',
        'ฐ', 'ฑ', 'ฒ', 'ณ', 'ด', 'ต', 'ถ', 'ท', 'ธ', 'น', 'บ', 'ป', 'ผ', 'ฝ', 'พ', 'ฟ',
        'ภ', 'ม', 'ย', 'ร', 'ฤ', 'ล', 'ฦ', 'ว', 'ศ', 'ษ', 'ส', 'ห', 'ฬ', 'อ', 'ฮ', 'ฯ',
        'ะ', "\u{0E31}", 'า', 'ำ', "\u{0E34}", "\u{0E35}", "\u{0E36}", "\u{0E37}", "\u{0E38}", "\u{0E39}", "\u{0E3A}", null, null, null, null, '฿',
        'เ', 'แ', 'โ', 'ใ', 'ไ', 'ๅ', 'ๆ', "\u{0E47}", "\u{0E48}", "\u{0E49}", "\u{0E4A}", "\u{0E4B}", "\u{0E4C}", "\u{0E4D}", "\u{0E4E}", '๏',
        '๐', '๑', '๒', '๓', '๔', '๕', '๖', '๗', '๘', '๙', '๚', '๛', null, null, null, null,
    ];

    /**
     * CP437 bytes 0x80-0xFF; null marks bytes the code page leaves undefined.
     *
     * @var list<string|null>
     */
    private const CP437 = [
        'Ç', 'ü', 'é', 'â', 'ä', 'à', 'å', 'ç', 'ê', 'ë', 'è', 'ï', 'î', 'ì', 'Ä', 'Å',
        'É', 'æ', 'Æ', 'ô', 'ö', 'ò', 'û', 'ù', 'ÿ', 'Ö', 'Ü', '¢', '£', '¥', '₧', 'ƒ',
        'á', 'í', 'ó', 'ú', 'ñ', 'Ñ', 'ª', 'º', '¿', '⌐', '¬', '½', '¼', '¡', '«', '»',
        '░', '▒', '▓', '│', '┤', '╡', '╢', '╖', '╕', '╣', '║', '╗', '╝', '╜', '╛', '┐',
        '└', '┴', '┬', '├', '─', '┼', '╞', '╟', '╚', '╔', '╩', '╦', '╠', '═', '╬', '╧',
        '╨', '╤', '╥', '╙', '╘', '╒', '╓', '╫', '╪', '┘', '┌', '█', '▄', '▌', '▐', '▀',
        'α', 'ß', 'Γ', 'π', 'Σ', 'σ', 'µ', 'τ', 'Φ', 'Θ', 'Ω', 'δ', '∞', 'φ', 'ε', '∩',
        '≡', '±', '≥', '≤', '⌠', '⌡', '÷', '≈', '°', '∙', '·', '√', 'ⁿ', '²', '■', "\u{00A0}",
    ];

    /**
     * Lower-cased encoding names, with their common aliases, that are decoded from a built-in table.
     */
    private const TABLES = [
        'windows-1250' => self::WINDOWS_1250, 'cp1250' => self::WINDOWS_1250, 'win-1250' => self::WINDOWS_1250,
        'windows-1253' => self::WINDOWS_1253, 'cp1253' => self::WINDOWS_1253, 'win-1253' => self::WINDOWS_1253,
        'windows-1255' => self::WINDOWS_1255, 'cp1255' => self::WINDOWS_1255, 'win-1255' => self::WINDOWS_1255,
        'windows-1256' => self::WINDOWS_1256, 'cp1256' => self::WINDOWS_1256, 'win-1256' => self::WINDOWS_1256,
        'windows-1257' => self::WINDOWS_1257, 'cp1257' => self::WINDOWS_1257, 'win-1257' => self::WINDOWS_1257,
        'windows-1258' => self::WINDOWS_1258, 'cp1258' => self::WINDOWS_1258, 'win-1258' => self::WINDOWS_1258,
        'windows-874' => self::WINDOWS_874, 'cp874' => self::WINDOWS_874, 'win-874' => self::WINDOWS_874,
        'cp437' => self::CP437, 'ibm437' => self::CP437,
    ];

    private const REPLACEMENT = "\u{FFFD}";

    public static function isSupported(string $encoding): bool
    {
        if (self::table($encoding) !== null) {
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
        $table = self::table($from);

        if ($table === null) {
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
            $out .= $byte < 0x80 ? $bytes[$i] : ($table[$byte - 0x80] ?? self::REPLACEMENT);
        }

        return $out;
    }

    /**
     * @return list<string|null>|null
     */
    private static function table(string $encoding): ?array
    {
        return self::TABLES[strtolower($encoding)] ?? null;
    }
}
