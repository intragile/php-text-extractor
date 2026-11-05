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

    public function test_percent_sequences_in_the_path_are_not_unescaped(): void
    {
        $directory = sys_get_temp_dir().'/txx_xml_pct_'.bin2hex(random_bytes(4));
        mkdir($directory);

        try {
            file_put_contents($directory.'/reportA.xml', '<r>wrong file</r>');
            file_put_contents($directory.'/report%41.xml', '<r>right file</r>');

            $result = (new XmlExtractor)->extract($directory.'/report%41.xml', Format::Xml, new Options);

            $this->assertSame('right file', $result->text);
        } finally {
            @unlink($directory.'/reportA.xml');
            @unlink($directory.'/report%41.xml');
            @rmdir($directory);
        }
    }

    public function test_doctype_without_internal_subset_is_extracted(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">'."\n"
            .'<plist version="1.0"><dict><key>Title</key><string>Árvíztűrő</string></dict></plist>';

        $result = (new TextExtractor(extractors: [new XmlExtractor]))->extract(Fixtures::file($xml, 'xml'));

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertSame('Title Árvíztűrő', $result->text);
    }

    public function test_external_system_dtd_is_never_loaded(): void
    {
        $dtd = Fixtures::file('<!ENTITY x "TOP-SECRET-CONTENT">', 'dtd');
        $xml = '<?xml version="1.0"?><!DOCTYPE r SYSTEM "file://'.$dtd.'"><r>before &x; after</r>';

        $result = (new TextExtractor(extractors: [new XmlExtractor]))->extract(Fixtures::file($xml, 'xml'));

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertSame('before after', $result->text);
        $this->assertStringNotContainsString('TOP-SECRET-CONTENT', (string) $result->message);
    }

    public function test_billion_laughs_is_corrupt(): void
    {
        $subset = '<!ENTITY a0 "lol">';

        for ($i = 1; $i <= 9; $i++) {
            $subset .= '<!ENTITY a'.$i.' "'.str_repeat('&a'.($i - 1).';', 10).'">';
        }

        $result = (new TextExtractor(extractors: [new XmlExtractor]))->extract(Fixtures::file('<?xml version="1.0"?><!DOCTYPE r ['.$subset.']><r>&a9;</r>', 'xml'));

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::Corrupt, $result->reason);
    }

    public function test_undeclared_namespace_prefix_is_still_extracted(): void
    {
        $result = (new TextExtractor(extractors: [new XmlExtractor]))->extract(Fixtures::file('<r><a>one</a><x:b>two</x:b></r>', 'xml'));

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertSame('one two', $result->text);
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
