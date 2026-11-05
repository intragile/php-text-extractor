<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Output;

/**
 * Accumulates extracted text up to a byte limit, cutting only on UTF-8 character boundaries.
 *
 * Extractors stop reading once append() returns false, so the limit bounds memory as well as output.
 */
final class TextBuffer
{
    private string $text = '';

    private bool $truncated = false;

    public function __construct(private readonly int $maxBytes) {}

    /**
     * @return bool false once the limit has been reached and text had to be dropped
     */
    public function append(string $chunk): bool
    {
        if ($chunk === '') {
            return ! $this->truncated;
        }

        $remaining = $this->maxBytes - strlen($this->text);

        if (strlen($chunk) <= $remaining) {
            $this->text .= $chunk;

            return true;
        }

        if ($remaining > 0) {
            $this->text .= mb_strcut($chunk, 0, $remaining, 'UTF-8');
        }

        $this->truncated = true;

        return false;
    }

    public function isFull(): bool
    {
        return $this->truncated || strlen($this->text) >= $this->maxBytes;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    public function toString(): string
    {
        return $this->text;
    }
}
