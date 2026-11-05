<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Output;

/**
 * Writes document text into a TextBuffer while applying one table layout for every format.
 *
 * Cells of a table row are joined by a tab and every row takes exactly one line, so a table reads
 * like tab-separated values. Anything that would break a line inside a cell (paragraphs, line
 * breaks, tabs, nested tables) becomes a single space instead. Separators are held back until
 * the next visible text, which keeps empty cells at the end of a row and empty rows out of the
 * output and never leaves a dangling tab or space.
 *
 * Outside tables, text and breaks are written unchanged.
 *
 * @internal
 */
final class LayoutWriter
{
    /** Number of currently open tables; only the outermost one defines rows and columns. */
    private int $depth = 0;

    private bool $atLineStart = true;

    private int $cellsInRow = 0;

    private bool $cellHasText = false;

    private int $pendingTabs = 0;

    private bool $pendingSpace = false;

    public function __construct(private readonly TextBuffer $buffer) {}

    public function atLineStart(): bool
    {
        return $this->atLineStart;
    }

    /**
     * @return bool false once the buffer is full
     */
    public function text(string $text): bool
    {
        if ($text === '') {
            return true;
        }

        if ($this->depth === 0) {
            return $this->emit($text);
        }

        $text = (string) preg_replace('/[\t\n\r\f\v]+/', ' ', $text);
        $core = trim($text, ' ');

        if ($core === '') {
            $this->separate();

            return true;
        }

        if ($text[0] === ' ') {
            $this->separate();
        }

        $prefix = '';

        if ($this->pendingTabs > 0) {
            $prefix = str_repeat("\t", $this->pendingTabs);
        } elseif ($this->pendingSpace && $this->cellHasText) {
            $prefix = ' ';
        }

        $this->pendingTabs = 0;
        $this->pendingSpace = str_ends_with($text, ' ');
        $this->cellHasText = true;

        return $this->emit($prefix.$core);
    }

    /**
     * Ends a paragraph; outside tables this always writes a line break, even for empty paragraphs.
     */
    public function paragraphEnd(): bool
    {
        return $this->breakOr("\n");
    }

    /**
     * Ends a block element without stacking up empty lines, as HTML rendering does.
     */
    public function blockEnd(): bool
    {
        if ($this->depth > 0) {
            $this->separate();

            return true;
        }

        return $this->atLineStart || $this->emit("\n");
    }

    public function lineBreak(): bool
    {
        return $this->breakOr("\n");
    }

    public function tab(): bool
    {
        return $this->breakOr("\t");
    }

    public function tableStart(): bool
    {
        $this->depth++;

        if ($this->depth > 1) {
            $this->separate();

            return true;
        }

        $this->resetRow();

        return $this->endLine();
    }

    public function tableEnd(): bool
    {
        if ($this->depth === 0) {
            return true;
        }

        $this->depth--;

        if ($this->depth > 0) {
            $this->separate();

            return true;
        }

        $this->resetRow();

        return $this->endLine();
    }

    public function rowStart(): bool
    {
        if ($this->depth !== 1) {
            $this->separate();

            return true;
        }

        $this->resetRow();

        // Text inside the table but outside any row, such as an HTML caption, keeps its own line.
        return $this->endLine();
    }

    public function rowEnd(): bool
    {
        if ($this->depth !== 1) {
            $this->separate();

            return true;
        }

        $this->resetRow();

        return $this->endLine();
    }

    public function cellStart(): bool
    {
        if ($this->depth !== 1) {
            $this->separate();

            return true;
        }

        if ($this->cellsInRow > 0) {
            $this->pendingTabs++;
        }

        $this->cellsInRow++;
        $this->cellHasText = false;
        $this->pendingSpace = false;

        return true;
    }

    public function cellEnd(): bool
    {
        if ($this->depth !== 1) {
            $this->separate();
        }

        return true;
    }

    private function breakOr(string $outside): bool
    {
        if ($this->depth > 0) {
            $this->separate();

            return true;
        }

        return $this->emit($outside);
    }

    /**
     * Requests a single space before the next text of the current cell.
     */
    private function separate(): void
    {
        if ($this->cellHasText) {
            $this->pendingSpace = true;
        }
    }

    private function resetRow(): void
    {
        $this->cellsInRow = 0;
        $this->cellHasText = false;
        $this->pendingTabs = 0;
        $this->pendingSpace = false;
    }

    private function endLine(): bool
    {
        return $this->atLineStart || $this->emit("\n");
    }

    private function emit(string $text): bool
    {
        $this->atLineStart = str_ends_with($text, "\n");

        return $this->buffer->append($text);
    }
}
