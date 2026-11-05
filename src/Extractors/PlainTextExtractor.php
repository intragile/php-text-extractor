<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Extractors;

use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Output\Encoding;
use IntrAgile\TextExtractor\Output\TextBuffer;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Result;
use IntrAgile\TextExtractor\Support\Deadline;
use IntrAgile\TextExtractor\Support\DeadlineAwareExtractor;

/**
 * Reads text files in chunks, decoding them to UTF-8.
 *
 * The encoding is decided once from the start of the file (BOM, then UTF-8 validity, then the
 * configured fallback) so that a file is never decoded with two different code pages.
 */
final class PlainTextExtractor implements DeadlineAwareExtractor
{
    private const CHUNK = 65536;

    public function supports(Format $format): bool
    {
        return in_array($format, [Format::Txt, Format::Markdown, Format::Csv, Format::Tsv, Format::Json], true);
    }

    public function extract(string $path, Format $format, Options $options): Result
    {
        return $this->extractWithin($path, $format, $options, Deadline::fromOptions($options));
    }

    public function extractWithin(string $path, Format $format, Options $options, Deadline $deadline): Result
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return Result::failed(Reason::Unreadable, $format, 'The file cannot be opened.');
        }

        try {
            $buffer = new TextBuffer($options->maxOutputBytes);
            $first = (string) fread($handle, self::CHUNK);
            $encoding = $this->detectEncoding($first, $options->fallbackEncoding);
            $first = $this->stripBom($first, $encoding);
            $pending = '';
            $chunk = $first;

            while (true) {
                $atEnd = feof($handle);
                [$complete, $pending] = $this->splitIncomplete($pending.$chunk, $encoding, $atEnd);

                if (! $buffer->append($this->decode($complete, $encoding))) {
                    break;
                }

                if ($atEnd) {
                    break;
                }

                // Decoding a chunk through a code page table is the costly step, so one clock
                // reading per chunk is cheap by comparison.
                $deadline->check();
                $chunk = (string) fread($handle, self::CHUNK);
            }
        } finally {
            fclose($handle);
        }

        return Result::extracted($buffer->toString(), $format, $buffer->isTruncated());
    }

    private function detectEncoding(string $head, string $fallback): string
    {
        if (str_starts_with($head, "\xFF\xFE")) {
            return 'UTF-16LE';
        }

        if (str_starts_with($head, "\xFE\xFF")) {
            return 'UTF-16BE';
        }

        [$complete] = $this->splitIncomplete($head, 'UTF-8', false);

        return mb_check_encoding($complete, 'UTF-8') ? 'UTF-8' : $fallback;
    }

    private function stripBom(string $head, string $encoding): string
    {
        return match (true) {
            $encoding === 'UTF-8' && str_starts_with($head, "\xEF\xBB\xBF") => substr($head, 3),
            $encoding === 'UTF-16LE', $encoding === 'UTF-16BE' => substr($head, 2),
            default => $head,
        };
    }

    /**
     * Holds back a trailing partial character so it can be completed by the next chunk.
     *
     * @return array{string, string} the decodable part and the carried-over tail
     */
    private function splitIncomplete(string $bytes, string $encoding, bool $atEnd): array
    {
        if ($atEnd || $bytes === '') {
            return [$bytes, ''];
        }

        if ($encoding === 'UTF-8') {
            $length = strlen($bytes);

            for ($back = 1; $back <= min(3, $length); $back++) {
                $byte = ord($bytes[$length - $back]);

                if ($byte < 0x80) {
                    return [$bytes, ''];
                }

                if ($byte >= 0xC0) {
                    $needed = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : 2);

                    return $back < $needed
                        ? [substr($bytes, 0, $length - $back), substr($bytes, $length - $back)]
                        : [$bytes, ''];
                }
            }

            return [$bytes, ''];
        }

        if ($encoding === 'UTF-16LE' || $encoding === 'UTF-16BE') {
            $cut = strlen($bytes) - (strlen($bytes) % 2);

            if ($cut >= 2) {
                $unit = substr($bytes, $cut - 2, 2);
                $high = $encoding === 'UTF-16LE' ? ord($unit[1]) : ord($unit[0]);

                if ($high >= 0xD8 && $high <= 0xDB) {
                    $cut -= 2;
                }
            }

            return [substr($bytes, 0, $cut), substr($bytes, $cut)];
        }

        return [$bytes, ''];
    }

    private function decode(string $bytes, string $encoding): string
    {
        if ($encoding === 'UTF-8') {
            return mb_check_encoding($bytes, 'UTF-8') ? $bytes : mb_scrub($bytes, 'UTF-8');
        }

        return Encoding::toUtf8($bytes, $encoding);
    }
}
