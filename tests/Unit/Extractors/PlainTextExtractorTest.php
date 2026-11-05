<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Extractors;

use IntrAgile\TextExtractor\Extractors\PlainTextExtractor;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Status;
use IntrAgile\TextExtractor\Tests\Support\Fixtures;
use IntrAgile\TextExtractor\Tests\Support\TestCase;
use IntrAgile\TextExtractor\TextExtractor;

final class PlainTextExtractorTest extends TestCase
{
    public function test_supports_the_plain_text_flavours(): void
    {
        $extractor = new PlainTextExtractor;

        foreach ([Format::Txt, Format::Markdown, Format::Csv, Format::Tsv, Format::Json] as $format) {
            $this->assertTrue($extractor->supports($format), $format->value);
        }

        $this->assertFalse($extractor->supports(Format::Html));
        $this->assertFalse($extractor->supports(Format::Xml));
    }

    public function test_windows1250_csv_becomes_utf8(): void
    {
        $result = TextExtractor::create(new Options(fallbackEncoding: 'Windows-1250'))
            ->extract(Fixtures::file("n\xE9v;v\xE1ros\n\xC1rp\xE1d;Gy\xF5r", 'csv'));

        $this->assertSame("név;város\nÁrpád;Győr", $result->text);
    }

    public function test_utf16_bom_text_is_decoded(): void
    {
        $bytes = "\xFF\xFE".mb_convert_encoding("árvíztűrő 😀\ntükörfúrógép", 'UTF-16LE', 'UTF-8');

        $this->assertSame("árvíztűrő 😀\ntükörfúrógép", TextExtractor::create()->extract(Fixtures::file($bytes, 'txt'))->text);
    }

    public function test_large_utf16_text_survives_chunk_boundaries(): void
    {
        $line = "ő😀 surrogate pairs and accents\n";
        $text = str_repeat($line, 6000);
        $bytes = "\xFE\xFF".mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');

        $this->assertSame(trim($text), TextExtractor::create()->extract(Fixtures::file($bytes, 'txt'))->text);
    }

    public function test_output_is_truncated_at_the_limit(): void
    {
        $result = (new PlainTextExtractor)->extract(Fixtures::file(str_repeat('abc ', 250_000), 'txt'), Format::Txt, new Options(maxOutputBytes: 100));

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertLessThanOrEqual(100, strlen($result->text));
        $this->assertTrue($result->truncated);
    }

    public function test_multibyte_character_split_across_chunks_survives(): void
    {
        $text = 'a'.str_repeat('ő', 65_000);
        $result = TextExtractor::create()->extract(Fixtures::file($text, 'txt'));

        $this->assertSame($text, $result->text);
        $this->assertTrue(mb_check_encoding($result->text, 'UTF-8'));
    }

    public function test_utf8_file_with_a_late_invalid_byte_stays_utf8(): void
    {
        $text = str_repeat('ű', 40_000)."\xFF".'vége';
        $result = TextExtractor::create()->extract(Fixtures::file($text, 'txt'));

        $this->assertTrue(mb_check_encoding($result->text, 'UTF-8'));
        $this->assertStringStartsWith('űű', $result->text);
        $this->assertStringEndsWith('vége', $result->text);
    }

    public function test_json_is_returned_as_text(): void
    {
        $this->assertSame('{"name": "Győr"}', TextExtractor::create()->extract(Fixtures::file('{"name": "Győr"}', 'json'))->text);
    }
}
