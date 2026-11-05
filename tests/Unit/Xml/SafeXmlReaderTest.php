<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Xml;

use IntrAgile\TextExtractor\Tests\Support\Fixtures;
use IntrAgile\TextExtractor\Tests\Support\TestCase;
use IntrAgile\TextExtractor\Xml\MalformedXml;
use IntrAgile\TextExtractor\Xml\SafeXmlReader;
use XMLReader;

final class SafeXmlReaderTest extends TestCase
{
    protected function setUp(): void
    {
        if (! extension_loaded('xmlreader')) {
            $this->markTestSkipped('ext-xmlreader is required.');
        }
    }

    private static function drainText(SafeXmlReader $reader): string
    {
        $text = '';

        while ($reader->read()) {
            if (in_array($reader->nodeType(), [XMLReader::TEXT, XMLReader::CDATA], true)) {
                $text .= $reader->value();
            }
        }

        return $text;
    }

    public function test_reads_elements_attributes_and_namespaces(): void
    {
        $reader = SafeXmlReader::fromString('<w:doc xmlns:w="urn:w"><w:p w:id="7">hi</w:p><e/></w:doc>');
        $seen = [];

        while ($reader->read()) {
            if ($reader->nodeType() === XMLReader::ELEMENT) {
                $seen[] = [$reader->localName(), $reader->namespaceUri(), $reader->attribute('id', 'urn:w'), $reader->isEmptyElement(), $reader->depth()];
            }
        }

        $this->assertSame([
            ['doc', 'urn:w', null, false, 0],
            ['p', 'urn:w', '7', false, 1],
            ['e', '', null, true, 1],
        ], $seen);
    }

    public function test_doctype_is_refused_so_external_entities_never_resolve(): void
    {
        $secret = Fixtures::file('root:x:0:0:secret', 'txt');
        $xml = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file://'.$secret.'">]><x>&e;</x>';
        $text = '';

        try {
            $text = self::drainText(SafeXmlReader::fromString($xml));
            $this->fail('A document with a DOCTYPE must be refused.');
        } catch (MalformedXml) {
            $this->assertStringNotContainsString('root:', $text);
        }
    }

    public function test_doctype_in_a_file_is_refused(): void
    {
        $path = Fixtures::file('<!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;">]><lolz>&lol2;</lolz>', 'xml');

        $this->expectException(MalformedXml::class);

        self::drainText(SafeXmlReader::fromUri($path));
    }

    public function test_malformed_xml_throws_malformed_xml(): void
    {
        $this->expectException(MalformedXml::class);

        self::drainText(SafeXmlReader::fromString('<a><b></a>'));
    }

    public function test_empty_input_throws_malformed_xml(): void
    {
        $this->expectException(MalformedXml::class);

        self::drainText(SafeXmlReader::fromString(''));
    }

    public function test_cdata_and_predefined_entities(): void
    {
        $this->assertSame('a&b<c>', self::drainText(SafeXmlReader::fromString('<r>a&amp;b<![CDATA[<c>]]></r>')));
    }

    public function test_internal_error_state_is_restored(): void
    {
        $before = libxml_use_internal_errors(false);

        try {
            self::drainText(SafeXmlReader::fromString('<r>ok</r>'));
            $this->assertFalse(libxml_use_internal_errors(false));
        } finally {
            libxml_use_internal_errors($before);
        }
    }
}
