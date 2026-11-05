<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Output;

use IntrAgile\TextExtractor\Output\TextNormalizer;
use PHPUnit\Framework\TestCase;

final class TextNormalizerTest extends TestCase
{
    public function test_windows1250_input_becomes_utf8(): void
    {
        $normalizer = new TextNormalizer('Windows-1250');

        $this->assertSame('árvíztűrő', $normalizer->toUtf8("\xE1rv\xEDzt\xFBr\xF5"));
    }

    public function test_valid_utf8_is_left_alone(): void
    {
        $this->assertSame('tükörfúrógép', (new TextNormalizer('Windows-1252'))->toUtf8('tükörfúrógép'));
    }

    public function test_utf8_bom_is_stripped(): void
    {
        $this->assertSame('abc', (new TextNormalizer('Windows-1252'))->toUtf8("\xEF\xBB\xBFabc"));
    }

    public function test_utf16le_bom_is_decoded(): void
    {
        $bytes = "\xFF\xFE".mb_convert_encoding('Ő és ű', 'UTF-16LE', 'UTF-8');

        $this->assertSame('Ő és ű', (new TextNormalizer('Windows-1252'))->toUtf8($bytes));
    }

    public function test_utf16be_bom_is_decoded(): void
    {
        $bytes = "\xFE\xFF".mb_convert_encoding('Ő és ű', 'UTF-16BE', 'UTF-8');

        $this->assertSame('Ő és ű', (new TextNormalizer('Windows-1252'))->toUtf8($bytes));
    }

    public function test_nul_and_control_bytes_are_removed(): void
    {
        $normalizer = new TextNormalizer('Windows-1252');

        $this->assertSame("a\tb\nc", $normalizer->normalize("a\0\tb\x07\n\x1Bc"));
    }

    public function test_line_endings_are_unified(): void
    {
        $this->assertSame("a\nb\nc", (new TextNormalizer('Windows-1252'))->normalize("a\r\nb\rc"));
    }

    public function test_blank_line_runs_collapse_to_one_empty_line(): void
    {
        $this->assertSame("a\n\nb", (new TextNormalizer('Windows-1252'))->normalize("a\n\n\n\n \n\t\nb"));
    }

    public function test_trailing_whitespace_and_outer_blank_space_are_trimmed(): void
    {
        $this->assertSame("a\n  b", (new TextNormalizer('Windows-1252'))->normalize("\n\n a  \t\n  b \n\n"));
    }

    public function test_invalid_utf8_inside_normalize_does_not_survive(): void
    {
        $normalized = (new TextNormalizer('Windows-1252'))->normalize("ok\xC3(");

        $this->assertTrue(mb_check_encoding($normalized, 'UTF-8'));
        $this->assertStringStartsWith('ok', $normalized);
    }
}
