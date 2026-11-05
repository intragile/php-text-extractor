<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Extractors\Ooxml;

use IntrAgile\TextExtractor\Exception\CorruptInput;
use IntrAgile\TextExtractor\Extractors\Ooxml\XlsxExtractor;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Result;
use IntrAgile\TextExtractor\Status;
use IntrAgile\TextExtractor\Tests\Support\Fixtures;
use IntrAgile\TextExtractor\Tests\Support\TestCase;
use IntrAgile\TextExtractor\Tests\Support\XlsxFixtures;
use IntrAgile\TextExtractor\TextExtractor;

final class XlsxExtractorTest extends TestCase
{
    protected function setUp(): void
    {
        if (! extension_loaded('zip') || ! extension_loaded('xmlreader')) {
            $this->markTestSkipped('ext-zip and ext-xmlreader are required.');
        }
    }

    public function test_supports_only_xlsx(): void
    {
        $extractor = new XlsxExtractor;

        $this->assertTrue($extractor->supports(Format::Xlsx));
        $this->assertFalse($extractor->supports(Format::Docx));
        $this->assertFalse($extractor->supports(Format::Ods));
    }

    public function test_shared_and_inline_strings_and_numbers(): void
    {
        $rows = '<row r="1">'
            .'<c r="A1" t="s"><v>0</v></c>'
            .'<c r="B1" t="inlineStr"><is><r><t>In</t></r><r><t>line</t></r><rPh sb="0" eb="1"><t>X</t></rPh></is></c>'
            .'<c r="C1"><v>42.5</v></c>'
            .'</row>'
            .'<row r="2">'
            .'<c r="A2" t="b"><v>1</v></c>'
            .'<c r="B2" t="s"><v>1</v></c>'
            .'<c r="C2" t="e"><v>#DIV/0!</v></c>'
            .'<c r="D2" t="b"><v>0</v></c>'
            .'<c r="E2" t="s"><v>99</v></c>'
            .'<c r="F2" t="d"><v>2026-11-05T00:00:00</v></c>'
            .'</row>';
        $shared = [
            'Név & cím',
            '<r><t>Ár</t></r><r><t xml:space="preserve">víz </t></r><rPh sb="0" eb="1"><t>アル</t></rPh><phoneticPr fontId="1"/>',
        ];

        $result = $this->extract(XlsxFixtures::xlsx(['Adatok' => $rows], $shared));

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertSame(Format::Xlsx, $result->format);
        $this->assertSame("## Adatok\nNév & cím\tInline\t42.5\nTRUE\tÁrvíz \t#DIV/0!\tFALSE\t2026-11-05T00:00:00", $result->text);
        $this->assertFalse($result->truncated);
    }

    public function test_sheet_order_and_headers(): void
    {
        $result = $this->extract(XlsxFixtures::xlsx([
            'Első' => '<row r="1"><c r="A1" t="inlineStr"><is><t>egy</t></is></c></row>',
            'Üres' => '<row r="1"/><row r="2"><c r="A2"/><c r="B2" t="s"/></row>',
            'Második' => '<row r="1"><c r="A1"><v>1</v></c><c r="C1"><v>3</v></c></row>'
                .'<row r="5"><c r="B5" t="inlineStr"><is><t>kettő</t></is></c></row>',
        ]));

        $this->assertSame("## Első\negy\n\n## Második\n1\t3\nkettő", $result->text);
    }

    public function test_formula_cells_output_cached_value(): void
    {
        $rows = '<row r="1">'
            .'<c r="A1"><f>SUM(B1:C1)</f><v>3</v></c>'
            .'<c r="B1" t="str"><f>CONCAT("a","b")</f><v>ab</v></c>'
            .'<c r="C1"><f>NOW()</f></c>'
            .'</row>';

        $result = $this->extract(XlsxFixtures::xlsx(['Képletek' => $rows]));

        $this->assertSame("## Képletek\n3\tab", $result->text);
        $this->assertStringNotContainsString('SUM', $result->text);
    }

    public function test_a_million_empty_rows_produce_no_output_and_finish_fast(): void
    {
        $rows = str_repeat('<row/>', 1_000_000);
        $path = XlsxFixtures::xlsx(['Sheet1' => $rows]);
        unset($rows);
        $extractor = new TextExtractor(extractors: [new XlsxExtractor]);

        $memoryBefore = memory_get_peak_usage(true);
        $started = microtime(true);
        $result = $extractor->extract($path);
        $elapsed = microtime(true) - $started;
        $memoryGrowth = memory_get_peak_usage(true) - $memoryBefore;

        $this->assertSame(Status::Empty, $result->status);
        $this->assertLessThan(5.0, $elapsed);
        $this->assertLessThan(32 * 1024 * 1024, $memoryGrowth);
    }

    public function test_huge_shared_strings_truncate(): void
    {
        $rows = '<row r="1"><c r="A1" t="s"><v>0</v></c></row>'
            .'<row r="2"><c r="A2" t="s"><v>1</v></c></row>'
            .'<row r="3"><c r="A3" t="s"><v>2</v></c></row>';
        $shared = [str_repeat('a', 60), str_repeat('b', 60), 'c'];

        $result = $this->extract(
            XlsxFixtures::xlsx(['S' => $rows], $shared),
            new Options(maxOutputBytes: 100),
        );

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertTrue($result->truncated);
        $this->assertSame("## S\n".str_repeat('a', 60)."\n".str_repeat('b', 34), $result->text);
    }

    public function test_a_shared_string_longer_than_the_output_limit_is_cut_not_dropped(): void
    {
        $result = $this->extract(
            XlsxFixtures::xlsx(['S' => '<row><c t="s"><v>0</v></c></row>'], [str_repeat('Lorem ipsum ', 20)]),
            new Options(maxOutputBytes: 100),
        );

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertTrue($result->truncated);
        $this->assertSame("## S\n".substr(str_repeat('Lorem ipsum ', 20), 0, 95), $result->text);
    }

    public function test_shared_strings_keep_their_indices_when_one_is_cut(): void
    {
        $rows = '<row><c t="s"><v>0</v></c><c t="s"><v>1</v></c><c t="s"><v>2</v></c></row>';
        $shared = ['first', str_repeat('ő', 100), 'third'];

        $result = $this->extract(XlsxFixtures::xlsx(['S' => $rows], $shared), new Options(maxOutputBytes: 120));

        $this->assertTrue($result->truncated);
        $this->assertTrue(mb_check_encoding($result->text, 'UTF-8'));
        $this->assertSame("## S\nfirst\t".str_repeat('ő', 54), $result->text);
        $this->assertStringNotContainsString('third', $result->text);
    }

    public function test_one_huge_shared_string_referenced_by_many_cells_stays_bounded(): void
    {
        $rows = '<row r="1">'.str_repeat('<c t="s"><v>0</v></c>', 300).'</row>';
        $path = XlsxFixtures::xlsx(['S' => $rows], [str_repeat('A', 1_000_000)]);
        $extractor = new TextExtractor(new Options(maxOutputBytes: 2_000_000), extractors: [new XlsxExtractor]);

        $growth = self::peakGrowth(static function () use ($extractor, $path, &$result): void {
            $result = $extractor->extract($path);
        });

        $this->assertInstanceOf(Result::class, $result);
        $this->assertSame(Status::Extracted, $result->status);
        $this->assertTrue($result->truncated);
        $this->assertSame(2_000_000, strlen($result->text));
        $this->assertLessThan(24 * 1024 * 1024, $growth);
    }

    public function test_huge_inline_strings_and_values_are_bounded_by_the_output_limit(): void
    {
        $text = str_repeat('x', 4_000_000);
        $rows = '<row>'
            .str_repeat('<c t="inlineStr"><is><t>'.$text.'</t><t>'.$text.'</t></is></c>', 3)
            .'<c><v>'.str_repeat('<![CDATA['.$text.']]>', 3).'</v></c>'
            .'</row>';
        $path = XlsxFixtures::xlsx(['S' => $rows]);
        unset($text, $rows);
        $extractor = new TextExtractor(new Options(maxOutputBytes: 1000), extractors: [new XlsxExtractor]);

        $growth = self::peakGrowth(static function () use ($extractor, $path, &$result): void {
            $result = $extractor->extract($path);
        });

        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->truncated);
        $this->assertSame(1000, strlen($result->text));
        $this->assertLessThan(24 * 1024 * 1024, $growth);
    }

    public function test_millions_of_empty_shared_strings_hit_the_archive_limit(): void
    {
        $entries = XlsxFixtures::entries(['S' => '<row><c t="s"><v>0</v></c></row>']);
        $entries['xl/sharedStrings.xml'] = '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .str_repeat('<si/>', 1_100_000).'</sst>';
        $path = Fixtures::zip($entries, 'xlsx');
        unset($entries);

        $growth = self::peakGrowth(function () use ($path, &$result): void {
            $result = $this->extract($path, new Options(maxOutputBytes: 1000));
        });

        $this->assertInstanceOf(Result::class, $result);
        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::ArchiveLimitExceeded, $result->reason);
        $this->assertLessThan(24 * 1024 * 1024, $growth);
    }

    public function test_line_breaks_inside_cells_become_spaces(): void
    {
        $rows = '<row><c t="s"><v>0</v></c><c t="inlineStr"><is><t>x&#13;&#10;y</t></is></c><c t="str"><v>p&#10;&#10;q</v></c></row>'
            .'<row><c><v>2</v></c></row>';

        $result = $this->extract(XlsxFixtures::xlsx(['S' => $rows], ["first\nsecond"]));

        $this->assertSame("## S\nfirst second\tx y\tp  q\n2", $result->text);
    }

    public function test_output_stops_at_the_output_limit(): void
    {
        $rows = str_repeat('<row><c t="inlineStr"><is><t>0123456789</t></is></c></row>', 100);

        $result = $this->extract(XlsxFixtures::xlsx(['S' => $rows]), new Options(maxOutputBytes: 50));

        $this->assertTrue($result->truncated);
        $this->assertLessThanOrEqual(50, strlen($result->text));
        $this->assertStringStartsWith("## S\n0123456789\n", $result->text);
    }

    public function test_sheet_with_a_missing_part_is_skipped(): void
    {
        $entries = XlsxFixtures::entries([
            'A' => '<row><c t="inlineStr"><is><t>a</t></is></c></row>',
            'B' => '<row><c t="inlineStr"><is><t>b</t></is></c></row>',
        ]);
        unset($entries['xl/worksheets/sheet2.xml']);

        $result = $this->extract(Fixtures::zip($entries, 'xlsx'));

        $this->assertSame("## B\nb", $result->text);
    }

    public function test_workbook_without_shared_strings_part_still_reads_inline_values(): void
    {
        $entries = XlsxFixtures::entries(['A' => '<row><c t="s"><v>0</v></c><c><v>7</v></c></row>']);
        unset($entries['xl/sharedStrings.xml']);

        $result = $this->extract(Fixtures::zip($entries, 'xlsx'));

        $this->assertSame("## A\n7", $result->text);
    }

    public function test_all_empty_workbook_is_empty(): void
    {
        $result = $this->extract(XlsxFixtures::xlsx(['A' => '', 'B' => '<row/>']));

        $this->assertSame(Status::Empty, $result->status);
    }

    public function test_missing_workbook_is_corrupt(): void
    {
        $entries = XlsxFixtures::entries(['A' => '<row><c><v>1</v></c></row>']);
        unset($entries['xl/workbook.xml']);
        $path = Fixtures::zip($entries, 'xlsx');

        $this->expectException(CorruptInput::class);

        (new XlsxExtractor)->extract($path, Format::Xlsx, new Options);
    }

    public function test_malformed_sheet_is_reported_as_corrupt(): void
    {
        $entries = XlsxFixtures::entries(['A' => '<row><c><v>1</v></c></row>']);
        $entries['xl/worksheets/sheet1.xml'] = '<worksheet><sheetData><row></sheetData>';

        $result = $this->extract(Fixtures::zip($entries, 'xlsx'));

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::Corrupt, $result->reason);
    }

    private function extract(string $path, ?Options $options = null): Result
    {
        return (new TextExtractor($options, extractors: [new XlsxExtractor]))->extract($path);
    }
}
