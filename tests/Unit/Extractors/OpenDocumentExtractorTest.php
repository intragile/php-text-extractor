<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Extractors;

use IntrAgile\TextExtractor\Exception\CorruptInput;
use IntrAgile\TextExtractor\Extractors\OpenDocumentExtractor;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Result;
use IntrAgile\TextExtractor\Status;
use IntrAgile\TextExtractor\Tests\Support\Fixtures;
use IntrAgile\TextExtractor\Tests\Support\OdfFixtures;
use IntrAgile\TextExtractor\Tests\Support\TestCase;
use IntrAgile\TextExtractor\TextExtractor;

final class OpenDocumentExtractorTest extends TestCase
{
    protected function setUp(): void
    {
        if (! extension_loaded('zip') || ! extension_loaded('xmlreader')) {
            $this->markTestSkipped('ext-zip and ext-xmlreader are required.');
        }
    }

    public function test_supports_the_opendocument_formats(): void
    {
        $extractor = new OpenDocumentExtractor;

        foreach ([Format::Odt, Format::Ods, Format::Odp] as $format) {
            $this->assertTrue($extractor->supports($format), $format->value);
        }

        $this->assertFalse($extractor->supports(Format::Docx));
        $this->assertFalse($extractor->supports(Format::Xlsx));
    }

    public function test_odt_paragraphs_spaces_tabs(): void
    {
        $result = $this->extract(OdfFixtures::TEXT, '<office:text>'
            .'<text:sequence-decls><text:sequence-decl text:name="Figure"/></text:sequence-decls>'
            .'<text:h text:outline-level="1">Title</text:h>'
            .'<text:p>Hello<text:s text:c="3"/>world<text:tab/>tabbed<text:line-break/>next <text:span>line</text:span></text:p>'
            .'<text:p>one<text:s/>space</text:p>'
            .'<text:p/>'
            .'<text:list><text:list-item><text:p>item</text:p></text:list-item></text:list>'
            .'</office:text>');

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertSame("Title\nHello   world\ttabbed\nnext line\none space\n\nitem", $result->text);
        $this->assertStringNotContainsString('style noise', $result->text);
    }

    public function test_odt_space_run_is_capped(): void
    {
        $result = $this->extract(OdfFixtures::TEXT, '<office:text><text:p>a<text:s text:c="1000000"/>b</text:p></office:text>');

        $this->assertSame('a'.str_repeat(' ', 1000).'b', $result->text);
    }

    public function test_odt_annotations_tracked_changes_and_drawing_metadata_are_excluded(): void
    {
        $result = $this->extract(OdfFixtures::TEXT, '<office:text>'
            .'<text:tracked-changes><text:changed-region text:id="c1"><text:deletion><text:p>deleted text</text:p></text:deletion></text:changed-region></text:tracked-changes>'
            .'<office:forms><text:p>form noise</text:p></office:forms>'
            .'<text:p>kept<office:annotation><dc:creator>Bob</dc:creator><text:p>comment</text:p></office:annotation> text</text:p>'
            .'<text:p><draw:frame><svg:title>frame title</svg:title><svg:desc>frame desc</svg:desc><draw:image><text:p>image fallback</text:p></draw:image></draw:frame>after</text:p>'
            .'</office:text>');

        $this->assertSame("kept text\nafter", $result->text);
    }

    public function test_odt_notes_are_appended_after_the_body_without_citation_marks(): void
    {
        $result = $this->extract(OdfFixtures::TEXT, '<office:text>'
            .'<text:p>Hello<text:note text:note-class="footnote"><text:note-citation>1</text:note-citation>'
            .'<text:note-body><text:p>Note text</text:p></text:note-body></text:note> world</text:p>'
            .'<text:p>Second<text:note text:note-class="endnote"><text:note-citation text:label="*">i</text:note-citation>'
            .'<text:note-body><text:p>Endnote</text:p><text:p>two lines</text:p></text:note-body></text:note></text:p>'
            .'</office:text>');

        $this->assertSame("Hello world\nSecond\n\nNote text\nEndnote\ntwo lines", $result->text);
    }

    public function test_odt_table_rows_are_lines_of_tab_separated_cells(): void
    {
        $result = $this->extract(OdfFixtures::TEXT, '<office:text><text:p>Before</text:p><table:table table:name="T">'
            .'<table:table-column table:number-columns-repeated="2"/>'
            .'<table:table-header-rows><table:table-row><table:table-cell><text:p>H1</text:p></table:table-cell><table:table-cell><text:p>H2</text:p></table:table-cell></table:table-row></table:table-header-rows>'
            .'<table:table-row><table:table-cell><text:p>a</text:p><text:p>b<text:line-break/>c</text:p></table:table-cell>'
            .'<table:table-cell><table:table><table:table-row><table:table-cell><text:p>n1</text:p></table:table-cell><table:table-cell><text:p>n2</text:p></table:table-cell></table:table-row></table:table></table:table-cell></table:table-row>'
            .'<table:table-row><table:table-cell/><table:table-cell><text:p>x</text:p></table:table-cell></table:table-row>'
            .'</table:table><text:p>After</text:p></office:text>');

        $this->assertSame("Before\nH1\tH2\na b c\tn1 n2\n\tx\nAfter", $result->text);
    }

    public function test_odp_table_rows_are_lines_of_tab_separated_cells(): void
    {
        $result = $this->extract(OdfFixtures::PRESENTATION, '<office:presentation><draw:page draw:name="p1"><draw:frame><table:table>'
            .'<table:table-row><table:table-cell><text:p>A</text:p></table:table-cell><table:table-cell><text:p>B</text:p></table:table-cell></table:table-row>'
            .'</table:table></draw:frame></draw:page></office:presentation>');

        $this->assertSame("A\tB", $result->text);
    }

    public function test_ods_cell_line_breaks_become_spaces(): void
    {
        $result = $this->extract(OdfFixtures::SPREADSHEET, '<office:spreadsheet><table:table table:name="S">'
            .'<table:table-row><table:table-cell><text:p>one<text:line-break/>two</text:p></table:table-cell><table:table-cell><text:p>z</text:p></table:table-cell></table:table-row>'
            .'</table:table></office:spreadsheet>');

        $this->assertSame("## S\none two\tz", $result->text);
    }

    public function test_elements_are_matched_by_namespace_not_prefix(): void
    {
        $result = $this->extract(OdfFixtures::TEXT, '<office:text xmlns:t="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:x="urn:example:other">'
            .'<t:p>first<t:tab/>cell</t:p>'
            .'<x:p>not odf<x:tab/></x:p>'
            .'<t:p>second</t:p>'
            .'</office:text>');

        $this->assertSame("first\tcell\nsecond", $result->text);
    }

    public function test_ods_rows_and_sheet_names(): void
    {
        $result = $this->extract(OdfFixtures::SPREADSHEET, '<office:spreadsheet>'
            .'<table:table table:name="Summary">'
            .'<table:table-column table:number-columns-repeated="3"/>'
            .'<table:table-row><table:table-cell office:value-type="string"><text:p>Name</text:p></table:table-cell><table:table-cell><text:p>Total</text:p></table:table-cell></table:table-row>'
            .'<table:table-row><table:table-cell office:value-type="string"><text:p>Apples</text:p></table:table-cell><table:table-cell office:value-type="float" office:value="1234.5"><text:p>1 234,50</text:p></table:table-cell></table:table-row>'
            .'<table:table-row><table:table-cell office:value-type="float" office:value="42"/><table:table-cell office:value-type="date" office:date-value="2026-11-05"/></table:table-row>'
            .'</table:table>'
            .'<table:table table:name="Empty"><table:table-row><table:table-cell/></table:table-row></table:table>'
            .'<table:table table:name="Second"><table:table-row><table:table-cell><text:p>x</text:p></table:table-cell></table:table-row></table:table>'
            .'</office:spreadsheet>');

        $this->assertSame("## Summary\nName\tTotal\nApples\t1 234,50\n42\t2026-11-05\n\n## Second\nx", $result->text);
    }

    public function test_ods_dates_and_times_use_the_typed_value_in_iso_form(): void
    {
        $cell = static fn (string $attributes, string $shown): string => '<table:table-cell '.$attributes.'><text:p>'.$shown.'</text:p></table:table-cell>';
        $result = $this->extract(OdfFixtures::SPREADSHEET, '<office:spreadsheet><table:table table:name="S">'
            .'<table:table-row>'
            .$cell('office:value-type="date" office:date-value="2026-11-05"', '5/11/26')
            .$cell('office:value-type="date" office:date-value="2025-01-31T14:30:00"', '31/1/25 14:30')
            .$cell('office:value-type="date" office:date-value="2025-01-31T14:30:59.6"', 'x')
            .$cell('office:value-type="date" office:date-value="2025-12-31T23:59:59.5"', 'x')
            .$cell('office:value-type="time" office:time-value="PT14H30M00S"', '14:30')
            .$cell('office:value-type="time" office:time-value="PT07H05M09.4S"', '7:05')
            .'</table:table-row><table:table-row>'
            // Durations of a day or more, negative or unparsable values keep the displayed text.
            .$cell('office:value-type="time" office:time-value="PT36H00M00S"', '36:00')
            .$cell('office:value-type="time" office:time-value="-PT01H00M00S"', '-1:00')
            .$cell('office:value-type="date" office:date-value="next week"', 'soon')
            .$cell('office:value-type="date" office:date-value="2023-02-30"', 'bad day')
            .$cell('office:value-type="float" office:value="1234.5"', '1 234,50')
            .'</table:table-row>'
            .'</table:table></office:spreadsheet>');

        $this->assertSame("## S\n"
            ."2026-11-05\t2025-01-31T14:30:00\t2025-01-31T14:31:00\t2026-01-01T00:00:00\t14:30:00\t07:05:09\n"
            ."36:00\t-1:00\tsoon\tbad day\t1 234,50", $result->text);
    }

    public function test_ods_time_format_on_a_date_and_time_value_gives_the_time_of_day(): void
    {
        // LibreOffice writes a date and time shown with a time format as the hours since 1899-12-30.
        $cell = static fn (string $value, string $shown): string => '<table:table-cell office:value-type="time" office:time-value="'.$value.'"><text:p>'.$shown.'</text:p></table:table-cell>';
        $result = $this->extract(OdfFixtures::SPREADSHEET, '<office:spreadsheet><table:table table:name="S"><table:table-row>'
            .$cell('PT1111954H30M00S', '10:30')
            .$cell('PT1111954H30M00S', '10:30:00 AM')
            .$cell('PT1111966H05M09S', '10:05:09 PM')
            .$cell('PT36H00M00S', '36:00')
            .$cell('PT1111954H30M00S', '1111954:30')
            .'</table:table-row></table:table></office:spreadsheet>');

        $this->assertSame("## S\n10:30:00\t10:30:00\t22:05:09\t36:00\t1111954:30", $result->text);
    }

    public function test_repeated_empty_cells_are_not_expanded(): void
    {
        $result = $this->extract(OdfFixtures::SPREADSHEET, '<office:spreadsheet><table:table table:name="S">'
            .'<table:table-row>'
            .'<table:table-cell><text:p>a</text:p></table:table-cell>'
            .'<table:table-cell table:number-columns-repeated="16384"/>'
            .'<table:covered-table-cell table:number-columns-repeated="16384"/>'
            .'<table:table-cell><text:p>b</text:p></table:table-cell>'
            .'<table:table-cell table:number-columns-repeated="16384"/>'
            .'</table:table-row>'
            .'<table:table-row table:number-rows-repeated="1048576"><table:table-cell table:number-columns-repeated="16384"/></table:table-row>'
            .'<table:table-row><table:table-cell><text:p>c</text:p></table:table-cell></table:table-row>'
            .'</table:table></office:spreadsheet>');

        $this->assertSame("## S\na\tb\nc", $result->text);
        $this->assertLessThanOrEqual(100, strlen($result->text));
    }

    public function test_repeated_non_empty_cells_and_rows_are_capped(): void
    {
        $result = $this->extract(OdfFixtures::SPREADSHEET, '<office:spreadsheet><table:table table:name="S">'
            .'<table:table-row table:number-rows-repeated="2"><table:table-cell table:number-columns-repeated="3"><text:p>x</text:p></table:table-cell></table:table-row>'
            .'<table:table-row table:number-rows-repeated="5000"><table:table-cell table:number-columns-repeated="5000"><text:p>y</text:p></table:table-cell></table:table-row>'
            .'</table:table></office:spreadsheet>');

        $lines = explode("\n", $result->text);

        $this->assertSame(['## S', "x\tx\tx", "x\tx\tx"], array_slice($lines, 0, 3));
        $this->assertCount(3 + 1000, $lines);
        $this->assertSame(implode("\t", array_fill(0, 1000, 'y')), $lines[3]);
    }

    public function test_ods_cell_paragraphs_and_tabs_stay_on_one_line(): void
    {
        $result = $this->extract(OdfFixtures::SPREADSHEET, '<office:spreadsheet><table:table table:name="S">'
            .'<table:table-row><table:table-cell><text:p>first</text:p><text:p>second<text:tab/>third</text:p><office:annotation><text:p>note</text:p></office:annotation></table:table-cell><table:table-cell><text:p>z</text:p></table:table-cell></table:table-row>'
            .'</table:table></office:spreadsheet>');

        $this->assertSame("## S\nfirst second third\tz", $result->text);
    }

    public function test_encrypted_odf_is_not_extractable(): void
    {
        $manifest = OdfFixtures::manifest(
            OdfFixtures::TEXT,
            '<manifest:encryption-data manifest:checksum-type="SHA1/1K" manifest:checksum="x"><manifest:algorithm manifest:algorithm-name="Blowfish CFB" manifest:initialisation-vector="y"/></manifest:encryption-data>',
        );
        $path = OdfFixtures::package(OdfFixtures::TEXT, [
            'content.xml' => random_bytes(256),
            'META-INF/manifest.xml' => $manifest,
        ]);

        $result = $this->orchestrator()->extract($path);

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::Encrypted, $result->reason);
        $this->assertSame(Format::Odt, $result->format);
    }

    public function test_odp_pages(): void
    {
        $result = $this->extract(OdfFixtures::PRESENTATION, '<office:presentation>'
            .'<draw:page draw:name="p1"><draw:frame><draw:text-box><text:p>One</text:p><text:p>More</text:p></draw:text-box></draw:frame>'
            .'<presentation:notes><draw:frame><draw:text-box><text:p>speaker notes</text:p></draw:text-box></draw:frame></presentation:notes></draw:page>'
            .'<draw:page draw:name="p2"><draw:frame><draw:text-box><text:p>Two</text:p></draw:text-box></draw:frame></draw:page>'
            .'</office:presentation>');

        $this->assertSame("One\nMore\n\nTwo", $result->text);
    }

    public function test_missing_content_is_corrupt(): void
    {
        $path = OdfFixtures::package(OdfFixtures::TEXT, ['META-INF/manifest.xml' => OdfFixtures::manifest(OdfFixtures::TEXT)]);

        $result = $this->orchestrator()->extract($path);

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::Corrupt, $result->reason);
    }

    public function test_content_with_doctype_is_corrupt(): void
    {
        $path = OdfFixtures::package(OdfFixtures::TEXT, [
            'content.xml' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>'
                .'<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><office:body><office:text><text:p>&e;</text:p></office:text></office:body></office:document-content>',
        ]);

        $result = $this->orchestrator()->extract($path);

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::Corrupt, $result->reason);
    }

    public function test_not_a_zip_is_corrupt(): void
    {
        $this->expectException(CorruptInput::class);

        (new OpenDocumentExtractor)->extract(Fixtures::file('plain bytes', 'odt'), Format::Odt, new Options);
    }

    public function test_document_without_text_is_empty(): void
    {
        $result = $this->extract(OdfFixtures::TEXT, '<office:text><text:p/><text:p>   </text:p></office:text>');

        $this->assertSame(Status::Empty, $result->status);
    }

    public function test_truncation_stops_reading(): void
    {
        $body = '<office:text>'.str_repeat('<text:p>paragraph of text</text:p>', 10000).'</office:text>';
        $path = OdfFixtures::odf(OdfFixtures::TEXT, $body);

        $result = (new OpenDocumentExtractor)->extract($path, Format::Odt, new Options(maxOutputBytes: 50));

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertTrue($result->truncated);
        $this->assertLessThanOrEqual(50, strlen($result->text));
    }

    private function extract(string $mimetype, string $bodyXml, ?Options $options = null): Result
    {
        return $this->orchestrator($options)->extract(OdfFixtures::odf($mimetype, $bodyXml));
    }

    private function orchestrator(?Options $options = null): TextExtractor
    {
        return new TextExtractor($options, extractors: [new OpenDocumentExtractor]);
    }
}
