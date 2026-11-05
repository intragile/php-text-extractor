<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Extractors\Ooxml;

use IntrAgile\TextExtractor\Exception\CorruptInput;
use IntrAgile\TextExtractor\Extractors\Ooxml\DocxExtractor;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Result;
use IntrAgile\TextExtractor\Status;
use IntrAgile\TextExtractor\Tests\Support\Fixtures;
use IntrAgile\TextExtractor\Tests\Support\OoxmlFixtures;
use IntrAgile\TextExtractor\Tests\Support\TestCase;
use IntrAgile\TextExtractor\TextExtractor;

final class DocxExtractorTest extends TestCase
{
    protected function setUp(): void
    {
        if (! extension_loaded('zip') || ! extension_loaded('xmlreader')) {
            $this->markTestSkipped('ext-zip and ext-xmlreader are required.');
        }
    }

    public function test_supports_only_docx(): void
    {
        $extractor = new DocxExtractor;

        $this->assertTrue($extractor->supports(Format::Docx));
        $this->assertFalse($extractor->supports(Format::Pptx));
        $this->assertFalse($extractor->supports(Format::Odt));
    }

    public function test_docx_paragraphs_tabs_and_breaks(): void
    {
        $body = '<w:p><w:r><w:t>One</w:t><w:tab/><w:t>Two</w:t><w:br/><w:t>Three</w:t><w:cr/><w:t>Four</w:t></w:r></w:p>'
            .OoxmlFixtures::paragraph('Second ', 'paragraph')
            .'<w:p/>'
            .'<w:p><w:r><w:t>Last</w:t></w:r></w:p>';

        $result = $this->extract(OoxmlFixtures::docx($body));

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertSame("One\tTwo\nThree\nFour\nSecond paragraph\n\nLast\n", $result->text);
    }

    public function test_docx_hyphens_and_preserved_spaces(): void
    {
        $body = '<w:p><w:r><w:t xml:space="preserve">well</w:t><w:noBreakHyphen/><w:t>known co</w:t>'
            .'<w:softHyphen/><w:t>operation</w:t><w:t xml:space="preserve"> </w:t><w:t>end</w:t></w:r></w:p>';

        $this->assertSame("well-known cooperation end\n", $this->extract(OoxmlFixtures::docx($body))->text);
    }

    public function test_docx_entities_are_decoded(): void
    {
        $body = '<w:p><w:r><w:t>Fish &amp; chips &lt;3 &#x151;</w:t></w:r></w:p>';

        $this->assertSame("Fish & chips <3 ő\n", $this->extract(OoxmlFixtures::docx($body))->text);
    }

    public function test_docx_deleted_tracked_changes_are_excluded(): void
    {
        $body = '<w:p><w:r><w:t xml:space="preserve">Kept </w:t></w:r>'
            .'<w:del w:id="1" w:author="A"><w:r><w:delText>REMOVED</w:delText></w:r></w:del>'
            .'<w:ins w:id="2" w:author="A"><w:r><w:t>inserted</w:t></w:r></w:ins></w:p>';

        $text = $this->extract(OoxmlFixtures::docx($body))->text;

        $this->assertSame("Kept inserted\n", $text);
    }

    public function test_docx_field_codes_are_excluded_but_results_kept(): void
    {
        $body = '<w:p><w:r><w:fldChar w:fldCharType="begin"/></w:r>'
            .'<w:r><w:instrText xml:space="preserve"> PAGE \* MERGEFORMAT </w:instrText></w:r>'
            .'<w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>7</w:t></w:r>'
            .'<w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>';

        $this->assertSame("7\n", $this->extract(OoxmlFixtures::docx($body))->text);
    }

    public function test_docx_moved_from_text_appears_once(): void
    {
        $body = '<w:p><w:moveFrom w:id="1" w:author="A"><w:r><w:t>Moved</w:t></w:r></w:moveFrom></w:p>'
            .'<w:p><w:moveTo w:id="2" w:author="A"><w:r><w:t>Moved</w:t></w:r></w:moveTo></w:p>';

        $this->assertSame(1, substr_count($this->extract(OoxmlFixtures::docx($body))->text, 'Moved'));
    }

    public function test_docx_table_cells_are_tab_separated(): void
    {
        $body = '<w:tbl><w:tblPr/><w:tblGrid><w:gridCol w:w="100"/><w:gridCol w:w="100"/></w:tblGrid>'
            .'<w:tr><w:tc><w:tcPr/>'.OoxmlFixtures::paragraph('A1').'</w:tc><w:tc>'.OoxmlFixtures::paragraph('B1').'</w:tc></w:tr>'
            .'<w:tr><w:tc>'.OoxmlFixtures::paragraph('A2').'</w:tc><w:tc>'.OoxmlFixtures::paragraph('B2').'</w:tc></w:tr>'
            .'</w:tbl>';

        $result = (new TextExtractor(extractors: [new DocxExtractor]))->extract(OoxmlFixtures::docx($body));

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertStringContainsString("A1\n\tB1", $result->text);
        $this->assertStringContainsString("A2\n\tB2", $result->text);
        $this->assertLessThan(strpos($result->text, 'A2'), strpos($result->text, 'B1'));
    }

    public function test_docx_text_box_alternate_content_appears_once(): void
    {
        $textBox = '<w:txbxContent>'.OoxmlFixtures::paragraph('Boxed text').'</w:txbxContent>';
        $body = '<w:p><w:r><w:t xml:space="preserve">Before </w:t></w:r><w:r><mc:AlternateContent>'
            .'<mc:Choice Requires="wps"><w:drawing><wp:anchor><a:graphic><a:graphicData>'
            .'<wps:wsp><wps:txbx>'.$textBox.'</wps:txbx></wps:wsp>'
            .'</a:graphicData></a:graphic></wp:anchor></w:drawing></mc:Choice>'
            .'<mc:Fallback><w:pict><v:shape><v:textbox>'.$textBox.'</v:textbox></v:shape></w:pict></mc:Fallback>'
            .'</mc:AlternateContent></w:r><w:r><w:t>after</w:t></w:r></w:p>';

        $text = $this->extract(OoxmlFixtures::docx($body))->text;

        $this->assertSame(1, substr_count($text, 'Boxed text'));
        $this->assertStringContainsString('Before ', $text);
        $this->assertStringContainsString('after', $text);
    }

    public function test_docx_footnotes_are_appended(): void
    {
        $footnotes = OoxmlFixtures::notes('footnote', '<w:footnote w:id="1">'.OoxmlFixtures::paragraph('Footnote text').'</w:footnote>');
        $endnotes = OoxmlFixtures::notes('endnote', '<w:endnote w:id="1">'.OoxmlFixtures::paragraph('Endnote text').'</w:endnote>');
        $body = '<w:p><w:r><w:t>Body</w:t></w:r><w:r><w:footnoteReference w:id="1"/></w:r></w:p>';

        $text = $this->extract(OoxmlFixtures::docx($body, [
            'word/footnotes.xml' => $footnotes,
            'word/endnotes.xml' => $endnotes,
        ]))->text;

        $this->assertStringStartsWith("Body\n", $text);
        $this->assertStringContainsString('Footnote text', $text);
        $this->assertStringContainsString('Endnote text', $text);
        $this->assertLessThan(strpos($text, 'Endnote text'), strpos($text, 'Footnote text'));
        $this->assertStringNotContainsString('CONTINUATION', $text);
    }

    public function test_notes_parts_are_found_through_relationships(): void
    {
        $footnotes = OoxmlFixtures::notes('footnote', '<w:footnote w:id="1">'.OoxmlFixtures::paragraph('Relocated note').'</w:footnote>');
        $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId7" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footnotes" Target="notes/fn.xml"/>'
            .'</Relationships>';

        $text = $this->extract(OoxmlFixtures::docx(OoxmlFixtures::paragraph('Body'), [
            'word/notes/fn.xml' => $footnotes,
            'word/_rels/document.xml.rels' => $rels,
        ]))->text;

        $this->assertStringContainsString('Relocated note', $text);
    }

    public function test_main_part_is_located_through_package_relationships(): void
    {
        $document = '<?xml version="1.0" encoding="UTF-8"?><w:document '.OoxmlFixtures::W_NAMESPACES.'><w:body>'
            .OoxmlFixtures::paragraph('Custom location').'</w:body></w:document>';
        $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="/content/main.xml"/>'
            .'</Relationships>';

        $path = Fixtures::zip([
            '_rels/.rels' => $rels,
            'content/main.xml' => $document,
        ], 'docx');

        $this->assertSame("Custom location\n", $this->extract($path)->text);
    }

    public function test_missing_main_part_is_corrupt(): void
    {
        $path = Fixtures::zip(['[Content_Types].xml' => '<Types/>', 'word/styles.xml' => '<w:styles/>'], 'docx');

        $this->expectException(CorruptInput::class);

        $this->extract($path);
    }

    public function test_empty_docx_is_empty(): void
    {
        $path = OoxmlFixtures::docx('<w:p/><w:p><w:pPr/></w:p><w:p><w:r><w:t></w:t></w:r></w:p>');

        $result = (new TextExtractor(extractors: [new DocxExtractor]))->extract($path);

        $this->assertSame(Status::Empty, $result->status);
        $this->assertSame(Format::Docx, $result->format);
        $this->assertSame('', $result->text);
    }

    public function test_docx_with_doctype_is_corrupt(): void
    {
        $document = '<?xml version="1.0"?><!DOCTYPE w:document [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<w:document '.OoxmlFixtures::W_NAMESPACES.'><w:body><w:p><w:r><w:t>&xxe;</w:t></w:r></w:p></w:body></w:document>';
        $path = OoxmlFixtures::docx('', ['word/document.xml' => $document]);

        $result = (new TextExtractor(extractors: [new DocxExtractor]))->extract($path);

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::Corrupt, $result->reason);
        $this->assertStringNotContainsString('root:', $result->text);
    }

    public function test_malformed_document_is_corrupt(): void
    {
        $path = OoxmlFixtures::docx('', ['word/document.xml' => '<w:document '.OoxmlFixtures::W_NAMESPACES.'><w:body><w:p>']);

        $result = (new TextExtractor(extractors: [new DocxExtractor]))->extract($path);

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::Corrupt, $result->reason);
    }

    public function test_not_a_zip_is_corrupt(): void
    {
        $this->expectException(CorruptInput::class);

        $this->extract(Fixtures::file('not a zip', 'docx'));
    }

    public function test_truncation_stops_reading(): void
    {
        $path = OoxmlFixtures::docx(str_repeat(OoxmlFixtures::paragraph('Paragraph text'), 10000));
        $options = new Options(maxOutputBytes: 50);

        $raw = (new DocxExtractor)->extract($path, Format::Docx, $options);

        $this->assertTrue($raw->truncated);
        $this->assertSame(50, strlen($raw->text));

        $result = (new TextExtractor($options, extractors: [new DocxExtractor]))->extract($path);

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertTrue($result->truncated);
        $this->assertLessThanOrEqual(50, strlen($result->text));
    }

    private function extract(string $path): Result
    {
        return (new DocxExtractor)->extract($path, Format::Docx, new Options);
    }
}
