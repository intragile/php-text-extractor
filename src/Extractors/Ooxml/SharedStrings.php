<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Extractors\Ooxml;

/**
 * The shared-string table of a workbook.
 *
 * Items are kept in one string with a packed table of end offsets, so each item costs four bytes
 * of bookkeeping instead of a PHP array slot plus a string header (roughly 50 bytes). Workbooks
 * routinely hold hundreds of thousands of short items, and hostile ones millions of empty ones.
 *
 * @internal
 */
final class SharedStrings
{
    /** The offsets are stored as unsigned 32-bit integers. */
    public const MAX_TEXT_BYTES = 0xFFFFFFFF;

    private string $text = '';

    private string $ends = '';

    private int $count = 0;

    public function add(string $item): void
    {
        $this->text .= $item;
        $this->ends .= pack('V', strlen($this->text));
        $this->count++;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function textBytes(): int
    {
        return strlen($this->text);
    }

    /**
     * Returns '' for an index past the end of the table, as Excel shows a dangling reference.
     */
    public function get(int $index): string
    {
        if ($index < 0 || $index >= $this->count) {
            return '';
        }

        $start = $index === 0 ? 0 : $this->endOf($index - 1);

        return substr($this->text, $start, $this->endOf($index) - $start);
    }

    private function endOf(int $index): int
    {
        $unpacked = unpack('V', $this->ends, $index * 4);

        return is_array($unpacked) && is_int($unpacked[1] ?? null) ? $unpacked[1] : 0;
    }
}
