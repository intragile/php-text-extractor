<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Extractors\Ooxml;

use IntrAgile\TextExtractor\Archive\SafeZip;
use IntrAgile\TextExtractor\Extractors\Ooxml\PartLocator;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Tests\Support\Fixtures;
use IntrAgile\TextExtractor\Tests\Support\TestCase;

final class PartLocatorTest extends TestCase
{
    private const RELS = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footnotes" Target="footnotes.xml"/>'
        .'<Relationship Id="rId3" Type="http://purl.oclc.org/ooxml/officeDocument/relationships/footnotes" Target="more-footnotes.xml"/>'
        .'<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footnotes" Target="missing.xml"/>'
        .'<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footnotes" Target="https://example.com/" TargetMode="External"/>'
        .'</Relationships>';

    protected function setUp(): void
    {
        if (! extension_loaded('zip') || ! extension_loaded('xmlreader')) {
            $this->markTestSkipped('ext-zip and ext-xmlreader are required.');
        }
    }

    public function test_parts_of_a_type_are_returned_in_document_order(): void
    {
        $zip = SafeZip::open($this->package(), new Options);

        $this->assertSame(['word/footnotes.xml', 'word/more-footnotes.xml'], PartLocator::byType($zip, 'word/document.xml', 'footnotes'));
        $this->assertSame(['word/styles.xml'], PartLocator::byType($zip, 'word/document.xml', 'styles'));
        $this->assertSame([], PartLocator::byType($zip, 'word/document.xml', 'endnotes'));
    }

    public function test_relationships_part_is_read_only_once(): void
    {
        // The archive-wide byte budget only covers one pass over the relationships part,
        // so a second read of it would exceed the limit.
        $budget = (int) (strlen(self::RELS) * 1.5);
        $zip = SafeZip::open($this->package(), new Options(maxArchiveTotalBytes: $budget));

        $this->assertSame(['word/footnotes.xml', 'word/more-footnotes.xml'], PartLocator::byType($zip, 'word/document.xml', 'footnotes'));
    }

    private function package(): string
    {
        return Fixtures::zip([
            'word/_rels/document.xml.rels' => self::RELS,
            'word/document.xml' => '<w:document/>',
            'word/styles.xml' => '<w:styles/>',
            'word/footnotes.xml' => '<w:footnotes/>',
            'word/more-footnotes.xml' => '<w:footnotes/>',
        ]);
    }
}
