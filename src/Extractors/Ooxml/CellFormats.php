<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Extractors\Ooxml;

/**
 * The number-format kind of each cell format (cellXfs entry) of a workbook, as far as it matters
 * for showing a value: whether a number is a date, a time of day, both, or neither.
 *
 * Cell formats are kept as a packed table of 32-bit numFmtIds (four bytes each), resolved against
 * the built-in ids and the workbook's custom formats on lookup, so the order in which numFmts and
 * cellXfs appear in the styles part does not matter.
 *
 * @internal
 */
final class CellFormats
{
    public const NONE = 0;

    public const DATE = 1;

    public const TIME = 2;

    public const DATE_TIME = 3;

    /** Elapsed time such as [h]:mm, which is a quantity rather than a point in time. */
    public const DURATION = 4;

    /**
     * Built-in numFmtIds (ECMA-376 Part 1, 18.8.30) that display dates or times. 27–36 and 50–58
     * are the East Asian locale formats, whose exact pattern depends on the workbook's locale but
     * whose kind does not.
     */
    private const BUILT_IN = [
        14 => self::DATE, 15 => self::DATE, 16 => self::DATE, 17 => self::DATE,
        18 => self::TIME, 19 => self::TIME, 20 => self::TIME, 21 => self::TIME,
        22 => self::DATE_TIME,
        27 => self::DATE, 28 => self::DATE, 29 => self::DATE, 30 => self::DATE, 31 => self::DATE,
        32 => self::TIME, 33 => self::TIME, 34 => self::TIME, 35 => self::TIME,
        36 => self::DATE,
        45 => self::TIME,
        46 => self::DURATION,
        47 => self::TIME,
        50 => self::DATE, 51 => self::DATE, 52 => self::DATE, 53 => self::DATE, 54 => self::DATE,
        55 => self::TIME, 56 => self::TIME,
        57 => self::DATE, 58 => self::DATE,
    ];

    /** @var array<int, int> custom numFmtId => kind, holding only formats that are not NONE */
    private array $custom = [];

    /** @var array<int, true> custom numFmtIds that override a built-in id with a non-date format */
    private array $plain = [];

    private int $customCount = 0;

    private string $formatIds = '';

    private int $count = 0;

    public function addNumberFormat(int $numFmtId, string $formatCode): void
    {
        $kind = self::classify($formatCode);
        $this->customCount++;

        if ($kind === self::NONE) {
            unset($this->custom[$numFmtId]);

            if (isset(self::BUILT_IN[$numFmtId])) {
                $this->plain[$numFmtId] = true;
            }

            return;
        }

        unset($this->plain[$numFmtId]);
        $this->custom[$numFmtId] = $kind;
    }

    public function customCount(): int
    {
        return $this->customCount;
    }

    public function addCellFormat(int $numFmtId): void
    {
        $this->formatIds .= pack('V', $numFmtId);
        $this->count++;
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * NONE for an index past the end of the table, which Excel shows as General.
     */
    public function kindOf(int $index): int
    {
        if ($index < 0 || $index >= $this->count) {
            return self::NONE;
        }

        $unpacked = unpack('V', $this->formatIds, $index * 4);
        $numFmtId = is_array($unpacked) && is_int($unpacked[1] ?? null) ? $unpacked[1] : 0;

        if (isset($this->custom[$numFmtId])) {
            return $this->custom[$numFmtId];
        }

        return isset($this->plain[$numFmtId]) ? self::NONE : (self::BUILT_IN[$numFmtId] ?? self::NONE);
    }

    /**
     * Decides the kind of a custom format code the way spreadsheet applications do: by the date
     * and time tokens left after removing everything that is displayed literally.
     */
    public static function classify(string $formatCode): int
    {
        // Elapsed-time tokens live inside brackets, so they must be found before brackets go.
        if (preg_match('/\[(?:h+|m+|s+)\]/i', $formatCode) === 1) {
            return self::DURATION;
        }

        // Literal parts are removed in a single left-to-right pass, so that an escaped quote does
        // not open a quoted section: quoted text, a backslash-escaped character, _x and *x padding,
        // and bracketed colours, conditions and locale tags.
        $tokens = preg_replace(['/"[^"]*"?|\\\\.|[_*].|\[[^\]]*\]?/s', '/AM\/PM|A\/P/i'], '', $formatCode) ?? '';
        $tokens = strtolower($tokens);

        $hasTime = strpbrk($tokens, 'hs') !== false;
        // "m" means minutes next to h or s and months otherwise.
        $hasDate = strpbrk($tokens, 'dy') !== false || (! $hasTime && str_contains($tokens, 'm'));

        if ($hasDate) {
            return $hasTime ? self::DATE_TIME : self::DATE;
        }

        return $hasTime ? self::TIME : self::NONE;
    }
}
