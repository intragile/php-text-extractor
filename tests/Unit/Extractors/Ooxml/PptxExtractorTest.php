<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Extractors\Ooxml;

use IntrAgile\TextExtractor\Exception\CorruptInput;
use IntrAgile\TextExtractor\Extractors\Ooxml\PptxExtractor;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Result;
use IntrAgile\TextExtractor\Status;
use IntrAgile\TextExtractor\Tests\Support\Fixtures;
use IntrAgile\TextExtractor\Tests\Support\OoxmlFixtures;
use IntrAgile\TextExtractor\Tests\Support\TestCase;
use IntrAgile\TextExtractor\TextExtractor;

final class PptxExtractorTest extends TestCase
{
    protected function setUp(): void
    {
        if (! extension_loaded('zip') || ! extension_loaded('xmlreader')) {
            $this->markTestSkipped('ext-zip and ext-xmlreader are required.');
        }
    }

    public function test_supports_only_pptx(): void
    {
        $extractor = new PptxExtractor;

        $this->assertTrue($extractor->supports(Format::Pptx));
        $this->assertFalse($extractor->supports(Format::Docx));
    }

    public function test_slide_paragraphs_and_breaks(): void
    {
        $shape = '<p:sp><p:txBody><a:bodyPr/>'
            .'<a:p><a:r><a:t>Title</a:t></a:r></a:p>'
            .'<a:p><a:r><a:t>Line one</a:t></a:r><a:br><a:rPr/></a:br><a:r><a:t>line two</a:t></a:r></a:p>'
            .'<a:p><a:endParaRPr/></a:p>'
            .'</p:txBody></p:sp>';

        $result = $this->extract(OoxmlFixtures::pptx([$shape]));

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertSame("Title\nLine one\nline two\n", $result->text);
    }

    public function test_slides_are_separated_by_a_blank_line(): void
    {
        $path = OoxmlFixtures::pptx([OoxmlFixtures::textShape('First'), OoxmlFixtures::textShape('Second')]);

        $this->assertSame("First\n\nSecond\n", $this->extract($path)->text);
    }

    public function test_pptx_slides_follow_presentation_order_not_file_names(): void
    {
        $path = OoxmlFixtures::pptx(
            [OoxmlFixtures::textShape('Slide file one'), OoxmlFixtures::textShape('Slide file two'), OoxmlFixtures::textShape('Slide file three')],
            [2, 3, 1],
        );

        $this->assertSame("Slide file two\n\nSlide file three\n\nSlide file one\n", $this->extract($path)->text);
    }

    public function test_slide_ids_pointing_to_missing_parts_are_skipped(): void
    {
        $path = OoxmlFixtures::pptx([OoxmlFixtures::textShape('Present')], [99, 1]);

        $this->assertSame("Present\n", $this->extract($path)->text);
    }

    public function test_alternate_content_fallback_is_skipped(): void
    {
        $tree = '<mc:AlternateContent><mc:Choice Requires="p14">'.OoxmlFixtures::textShape('Shape text').'</mc:Choice>'
            .'<mc:Fallback>'.OoxmlFixtures::textShape('Shape text').'</mc:Fallback></mc:AlternateContent>'
            .OoxmlFixtures::textShape('Other');

        $text = $this->extract(OoxmlFixtures::pptx([$tree]))->text;

        $this->assertSame(1, substr_count($text, 'Shape text'));
        $this->assertStringContainsString('Other', $text);
    }

    public function test_table_text_is_included(): void
    {
        $table = '<p:graphicFrame><a:graphic><a:graphicData><a:tbl><a:tr h="1">'
            .'<a:tc><a:txBody><a:bodyPr/><a:p><a:r><a:t>Cell A</a:t></a:r></a:p></a:txBody></a:tc>'
            .'<a:tc><a:txBody><a:bodyPr/><a:p><a:r><a:t>Cell B</a:t></a:r></a:p></a:txBody></a:tc>'
            .'</a:tr></a:tbl></a:graphicData></a:graphic></p:graphicFrame>';

        $text = $this->extract(OoxmlFixtures::pptx([$table]))->text;

        $this->assertStringContainsString('Cell A', $text);
        $this->assertStringContainsString('Cell B', $text);
    }

    public function test_table_rows_are_lines_of_tab_separated_cells(): void
    {
        $cell = static fn (string ...$paragraphs): string => '<a:tc><a:txBody><a:bodyPr/>'
            .implode('', array_map(static fn (string $p): string => '<a:p><a:r><a:t>'.$p.'</a:t></a:r></a:p>', $paragraphs))
            .'</a:txBody><a:tcPr/></a:tc>';
        $table = '<p:graphicFrame><a:graphic><a:graphicData><a:tbl><a:tblGrid><a:gridCol w="1"/></a:tblGrid>'
            .'<a:tr h="1">'.$cell('A1', 'more').$cell('B1').'</a:tr>'
            .'<a:tr h="1">'.$cell('A2').'<a:tc hMerge="1"><a:txBody><a:bodyPr/><a:p/></a:txBody></a:tc>'.$cell('C2').'</a:tr>'
            .'</a:tbl></a:graphicData></a:graphic></p:graphicFrame>';

        $text = $this->extract(OoxmlFixtures::pptx([OoxmlFixtures::textShape('Title').$table]))->text;

        $this->assertSame("Title\nA1 more\tB1\nA2\t\tC2\n", $text);
    }

    public function test_presentation_without_slides_is_empty(): void
    {
        $result = (new TextExtractor(extractors: [new PptxExtractor]))->extract(OoxmlFixtures::pptx([]));

        $this->assertSame(Status::Empty, $result->status);
        $this->assertSame(Format::Pptx, $result->format);
    }

    public function test_missing_presentation_part_is_corrupt(): void
    {
        $path = Fixtures::zip(['ppt/slides/slide1.xml' => '<p:sld/>'], 'pptx');

        $this->expectException(CorruptInput::class);

        $this->extract($path);
    }

    public function test_slide_with_doctype_is_corrupt(): void
    {
        $slide = '<?xml version="1.0"?><!DOCTYPE p:sld [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<p:sld '.OoxmlFixtures::P_NAMESPACES.'><p:cSld><p:spTree><a:t>&xxe;</a:t></p:spTree></p:cSld></p:sld>';
        $path = $this->pptxWith(['ppt/slides/slide1.xml' => $slide]);

        $result = (new TextExtractor(extractors: [new PptxExtractor]))->extract($path);

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::Corrupt, $result->reason);
    }

    public function test_truncation_stops_reading(): void
    {
        $paragraphs = array_fill(0, 10000, 'Paragraph text');
        $path = OoxmlFixtures::pptx([OoxmlFixtures::textShape(...$paragraphs), OoxmlFixtures::textShape('Never reached')]);
        $options = new Options(maxOutputBytes: 50);

        $raw = (new PptxExtractor)->extract($path, Format::Pptx, $options);

        $this->assertTrue($raw->truncated);
        $this->assertSame(50, strlen($raw->text));

        $result = (new TextExtractor($options, extractors: [new PptxExtractor]))->extract($path);

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertTrue($result->truncated);
    }

    /**
     * Builds a one-slide package and then replaces some of its entries.
     *
     * @param  array<string, string>  $replacements
     */
    private function pptxWith(array $replacements): string
    {
        $zip = new \ZipArchive;
        $path = OoxmlFixtures::pptx([OoxmlFixtures::textShape('placeholder')]);
        $zip->open($path);

        foreach ($replacements as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }

    public function test_speaker_notes_follow_the_text_of_their_slide(): void
    {
        $notes = OoxmlFixtures::placeholderShape('sldImg')
            .OoxmlFixtures::placeholderShape('body', 'First note', 'Ékezetes jegyzet')
            .OoxmlFixtures::placeholderShape('sldNum', '1')
            .OoxmlFixtures::placeholderShape('ftr', 'Footer');
        $path = OoxmlFixtures::pptx(
            [OoxmlFixtures::textShape('Slide one'), OoxmlFixtures::textShape('Slide two')],
            null,
            [1 => $notes, 2 => '<p:grpSp>'.OoxmlFixtures::placeholderShape('body', 'Grouped note').'</p:grpSp>'],
        );

        $this->assertSame("Slide one\nFirst note\nÉkezetes jegyzet\n\nSlide two\nGrouped note\n", $this->extract($path)->text);
    }

    public function test_empty_paragraphs_do_not_add_blank_lines_inside_a_slide(): void
    {
        // A blank line only ever separates two slides, so empty placeholder paragraphs are dropped.
        $empty = '<p:sp><p:nvSpPr><p:cNvPr id="3" name="Body"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr/><p:txBody><a:bodyPr/><a:p><a:pPr/></a:p><a:p/></p:txBody></p:sp>';
        $path = OoxmlFixtures::pptx(
            [OoxmlFixtures::textShape('Title').$empty, OoxmlFixtures::textShape('Next')],
            null,
            [1 => OoxmlFixtures::placeholderShape('body', 'Note')],
        );

        $this->assertSame("Title\nNote\n\nNext\n", $this->extract($path)->text);
    }

    private function extract(string $path): Result
    {
        return (new PptxExtractor)->extract($path, Format::Pptx, new Options);
    }
}
