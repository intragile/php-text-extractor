<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Archive;

use IntrAgile\TextExtractor\Archive\ArchiveLimitExceeded;
use IntrAgile\TextExtractor\Archive\SafeZip;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Tests\Support\Fixtures;
use IntrAgile\TextExtractor\Tests\Support\TestCase;
use IntrAgile\TextExtractor\Xml\MalformedXml;
use RuntimeException;
use XMLReader;

final class SafeZipTest extends TestCase
{
    protected function setUp(): void
    {
        if (! extension_loaded('zip') || ! extension_loaded('xmlreader')) {
            $this->markTestSkipped('ext-zip and ext-xmlreader are required.');
        }
    }

    public function test_reads_entries_and_lists_names(): void
    {
        $zip = SafeZip::open(Fixtures::zip(['a.txt' => 'alpha', 'dir/b.xml' => '<b>beta</b>']), new Options);

        $this->assertTrue($zip->has('a.txt'));
        $this->assertFalse($zip->has('missing.txt'));
        $this->assertSame(['a.txt', 'dir/b.xml'], $zip->names());
        $this->assertSame('alpha', $zip->read('a.txt', 1024));

        $zip->close();
    }

    public function test_read_refuses_more_than_the_requested_bytes(): void
    {
        $zip = SafeZip::open(Fixtures::zip(['a.txt' => str_repeat('x', 5000)]), new Options);

        $this->expectException(ArchiveLimitExceeded::class);

        $zip->read('a.txt', 100);
    }

    public function test_rejects_an_entry_declared_larger_than_the_limit(): void
    {
        $zip = SafeZip::open(Fixtures::zip(['a.txt' => str_repeat('x', 5000)]), new Options(maxArchiveEntryBytes: 1000));

        $this->expectException(ArchiveLimitExceeded::class);

        $zip->read('a.txt', 1_000_000);
    }

    public function test_an_entry_hiding_its_real_size_is_stopped_while_reading(): void
    {
        $path = Fixtures::zipWithFalseSize('big.txt', str_repeat('A', 5_000_000), 1000);
        $zip = SafeZip::open($path, new Options(maxArchiveEntryBytes: 1_000_000));

        $this->expectException(ArchiveLimitExceeded::class);

        $zip->read('big.txt', 10_000_000);
    }

    public function test_an_xml_entry_hiding_its_real_size_is_stopped_while_streaming(): void
    {
        $xml = '<root>'.str_repeat('<a>text</a>', 500_000).'</root>';
        $path = Fixtures::zipWithFalseSize('big.xml', $xml, 1000);
        $zip = SafeZip::open($path, new Options(maxArchiveEntryBytes: 1_000_000));
        $reader = $zip->xmlReader('big.xml');

        $this->expectException(ArchiveLimitExceeded::class);

        while ($reader->read()) {
            // Drain until the budget trips.
        }
    }

    public function test_the_total_budget_spans_entries(): void
    {
        $zip = SafeZip::open(
            Fixtures::zip(['a.txt' => str_repeat('a', 600), 'b.txt' => str_repeat('b', 600)]),
            new Options(maxArchiveTotalBytes: 1000),
        );

        $this->assertSame(600, strlen($zip->read('a.txt', 10_000)));

        $this->expectException(ArchiveLimitExceeded::class);

        $zip->read('b.txt', 10_000);
    }

    public function test_rejects_too_many_entries(): void
    {
        $entries = [];

        for ($i = 0; $i < 11; $i++) {
            $entries["f{$i}.txt"] = 'x';
        }

        $this->expectException(ArchiveLimitExceeded::class);

        SafeZip::open(Fixtures::zip($entries), new Options(maxArchiveEntries: 10));
    }

    public function test_refuses_path_traversal_names(): void
    {
        $zip = SafeZip::open(Fixtures::zip(['../evil.txt' => 'x', '/abs.txt' => 'y', 'ok.txt' => 'z']), new Options);

        $this->assertFalse($zip->has('../evil.txt'));
        $this->assertFalse($zip->has('/abs.txt'));
        $this->assertSame(['ok.txt'], $zip->names());

        $this->expectException(ArchiveLimitExceeded::class);

        $zip->read('../evil.txt', 100);
    }

    public function test_a_file_that_is_not_a_zip_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        SafeZip::open(Fixtures::file('not a zip at all', 'zip'), new Options);
    }

    public function test_reading_a_missing_entry_is_rejected(): void
    {
        $zip = SafeZip::open(Fixtures::zip(['a.txt' => 'x']), new Options);

        $this->expectException(RuntimeException::class);

        $zip->read('b.txt', 100);
    }

    public function test_streams_an_xml_entry(): void
    {
        $zip = SafeZip::open(Fixtures::zip(['doc.xml' => '<?xml version="1.0"?><r><t>hello</t><t>world</t></r>']), new Options);
        $reader = $zip->xmlReader('doc.xml');
        $texts = [];

        while ($reader->read()) {
            if ($reader->nodeType() === XMLReader::TEXT) {
                $texts[] = $reader->value();
            }
        }

        $reader->close();
        $this->assertSame(['hello', 'world'], $texts);
    }

    public function test_malformed_xml_entry_throws_malformed_xml(): void
    {
        $reader = SafeZip::open(Fixtures::zip(['doc.xml' => '<r><t>oops</r>']), new Options)->xmlReader('doc.xml');

        $this->expectException(MalformedXml::class);

        while ($reader->read()) {
            // Drain.
        }
    }
}
