<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Extractors\Ooxml;

use IntrAgile\TextExtractor\Archive\SafeZip;
use IntrAgile\TextExtractor\Extractors\Ooxml\Relationships;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Tests\Support\Fixtures;
use IntrAgile\TextExtractor\Tests\Support\TestCase;

final class RelationshipsTest extends TestCase
{
    protected function setUp(): void
    {
        if (! extension_loaded('zip') || ! extension_loaded('xmlreader')) {
            $this->markTestSkipped('ext-zip and ext-xmlreader are required.');
        }
    }

    public function test_targets_are_resolved_against_the_source_part(): void
    {
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId2" Type="t" Target="slides/slide2.xml"/>'
            .'<Relationship Id="rId3" Type="t" Target="/ppt/slides/slide3.xml"/>'
            .'<Relationship Id="rId4" Type="t" Target="../docProps/app.xml"/>'
            .'<Relationship Id="rId5" Type="t" Target="./slides/./slide5.xml"/>'
            .'<Relationship Id="rId6" Type="t" Target="https://example.com/" TargetMode="External"/>'
            .'<Relationship Id="rId7" Type="t" Target="../../../etc/passwd"/>'
            .'</Relationships>';
        $zip = SafeZip::open(Fixtures::zip(['ppt/_rels/presentation.xml.rels' => $rels]), new Options);

        $this->assertSame([
            'rId2' => 'ppt/slides/slide2.xml',
            'rId3' => 'ppt/slides/slide3.xml',
            'rId4' => 'docProps/app.xml',
            'rId5' => 'ppt/slides/slide5.xml',
        ], Relationships::parse($zip, 'ppt/_rels/presentation.xml.rels'));
    }

    public function test_missing_relationships_part_is_empty(): void
    {
        $zip = SafeZip::open(Fixtures::zip(['a.txt' => 'x']), new Options);

        $this->assertSame([], Relationships::parse($zip, 'word/_rels/document.xml.rels'));
    }

    public function test_relationships_at_the_package_root(): void
    {
        $rels = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="t" Target="word/document.xml"/></Relationships>';
        $zip = SafeZip::open(Fixtures::zip(['_rels/.rels' => $rels]), new Options);

        $this->assertSame(['rId1' => 'word/document.xml'], Relationships::parse($zip, '_rels/.rels'));
    }

    public function test_url_encoded_targets_are_decoded(): void
    {
        $rels = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="t" Target="worksheets/sheet%201.xml"/></Relationships>';
        $zip = SafeZip::open(Fixtures::zip(['xl/_rels/workbook.xml.rels' => $rels]), new Options);

        $this->assertSame(['rId1' => 'xl/worksheets/sheet 1.xml'], Relationships::parse($zip, 'xl/_rels/workbook.xml.rels'));
    }
}
