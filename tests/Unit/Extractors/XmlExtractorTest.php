<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Extractors;

use IntrAgile\TextExtractor\Extractors\XmlExtractor;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Status;
use IntrAgile\TextExtractor\Tests\Support\Fixtures;
use IntrAgile\TextExtractor\Tests\Support\TestCase;
use IntrAgile\TextExtractor\TextExtractor;

final class XmlExtractorTest extends TestCase
{
    public function test_supports_only_xml(): void
    {
        $extractor = new XmlExtractor;

        $this->assertTrue($extractor->supports(Format::Xml));
        $this->assertFalse($extractor->supports(Format::Html));
        $this->assertFalse($extractor->supports(Format::Docx));
    }

    public function test_xml_text_nodes_are_joined(): void
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<root>\n  <a>Hello</a>\n  <b><![CDATA[Győr & <co>]]></b>\n  <c>tűz <d>víz</d></c>\n</root>";

        $result = (new XmlExtractor)->extract(Fixtures::file($xml, 'xml'), Format::Xml, new Options);

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertSame('Hello Győr & <co> tűz víz', $result->text);
    }

    public function test_xml_with_doctype_is_corrupt_not_leaking(): void
    {
        $secret = Fixtures::file('TOP-SECRET-CONTENT', 'txt');
        $xml = '<?xml version="1.0"?>'."\n"
            .'<!DOCTYPE r [<!ENTITY x SYSTEM "file://'.$secret.'">]>'."\n"
            .'<r>before &x; after</r>';

        $result = (new TextExtractor(extractors: [new XmlExtractor]))->extract(Fixtures::file($xml, 'xml'));

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::Corrupt, $result->reason);
        $this->assertStringNotContainsString('TOP-SECRET-CONTENT', $result->text);
        $this->assertStringNotContainsString('TOP-SECRET-CONTENT', (string) $result->message);
    }

    public function test_malformed_xml_is_corrupt(): void
    {
        $result = (new TextExtractor(extractors: [new XmlExtractor]))->extract(Fixtures::file('<?xml version="1.0"?><root><a>open</root>', 'xml'));

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::Corrupt, $result->reason);
    }

    public function test_element_only_xml_is_empty(): void
    {
        $result = (new TextExtractor(extractors: [new XmlExtractor]))->extract(Fixtures::file("<?xml version=\"1.0\"?>\n<root>\n  <a/>\n  <b> </b>\n</root>", 'xml'));

        $this->assertSame(Status::Empty, $result->status);
    }

    public function test_output_is_truncated_at_the_limit(): void
    {
        $xml = '<?xml version="1.0"?><root>'.str_repeat('<item>árvíztűrő tükörfúrógép</item>', 10_000).'</root>';

        $result = (new XmlExtractor)->extract(Fixtures::file($xml, 'xml'), Format::Xml, new Options(maxOutputBytes: 100));

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertTrue($result->truncated);
        $this->assertLessThanOrEqual(100, strlen($result->text));
        $this->assertTrue(mb_check_encoding($result->text, 'UTF-8'));
    }
}
