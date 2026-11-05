<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Feature;

use Closure;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Process\ToolLocator;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Status;
use IntrAgile\TextExtractor\Tests\Support\Fixtures;
use IntrAgile\TextExtractor\Tests\Support\OdfFixtures;
use IntrAgile\TextExtractor\Tests\Support\OoxmlFixtures;
use IntrAgile\TextExtractor\Tests\Support\TestCase;
use IntrAgile\TextExtractor\Tests\Support\XlsxFixtures;
use IntrAgile\TextExtractor\TextExtractor;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every format through the public entry point with the default extractor set.
 */
final class EndToEndTest extends TestCase
{
    /**
     * @return \Generator<string, array{Closure(): string, Format, string, string|null}>
     */
    public static function documents(): \Generator
    {
        yield 'txt' => [static fn (): string => Fixtures::file("Árvíztűrő tükörfúrógép\r\n", 'txt'), Format::Txt, 'Árvíztűrő tükörfúrógép', null];
        yield 'md' => [static fn (): string => Fixtures::file("# Title\n\nBody", 'md'), Format::Markdown, "# Title\n\nBody", null];
        yield 'csv' => [static fn (): string => Fixtures::file("name;city\nÁrpád;Győr", 'csv'), Format::Csv, 'Árpád;Győr', null];
        yield 'json' => [static fn (): string => Fixtures::file('{"a":"b"}', 'json'), Format::Json, '{"a":"b"}', null];
        yield 'xml' => [static fn (): string => Fixtures::file('<?xml version="1.0"?><invoice><total>1200</total></invoice>', 'xml'), Format::Xml, '1200', 'ext-xmlreader'];
        yield 'html' => [static fn (): string => Fixtures::file('<html><head><title>T</title><script>x()</script></head><body><p>Hello <b>world</b></p></body></html>', 'html'), Format::Html, 'Hello world', 'ext-dom'];
        yield 'rtf' => [static fn (): string => Fixtures::file("{\\rtf1\\ansi\\ansicpg1250{\\fonttbl{\\f0 Arial;}}\\f0 Gy\\'f5r\\par}", 'rtf'), Format::Rtf, 'Győr', null];
        yield 'docx' => [static fn (): string => OoxmlFixtures::docx(OoxmlFixtures::paragraph('Contract ', 'number 42')), Format::Docx, 'Contract number 42', 'ext-zip'];
        yield 'pptx' => [static fn (): string => OoxmlFixtures::pptx([OoxmlFixtures::textShape('Quarterly results')]), Format::Pptx, 'Quarterly results', 'ext-zip'];
        yield 'xlsx' => [static fn (): string => XlsxFixtures::xlsx(['Data' => '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1"><v>12.5</v></c></row>'], ['Total']), Format::Xlsx, "## Data\nTotal\t12.5", 'ext-zip'];
        yield 'odt' => [static fn (): string => OdfFixtures::odf('application/vnd.oasis.opendocument.text', '<office:text><text:p>Open document</text:p></office:text>'), Format::Odt, 'Open document', 'ext-zip'];
        yield 'pdf' => [static fn (): string => Fixtures::committed('pdf/text.pdf'), Format::Pdf, '', 'pdftotext'];
        yield 'png' => [static fn (): string => Fixtures::committed('image/text.png'), Format::Png, 'Invoice', 'tesseract'];
    }

    /**
     * @param  Closure(): string  $make
     */
    #[DataProvider('documents')]
    public function test_every_format_is_extracted(Closure $make, Format $format, string $expected, ?string $requires): void
    {
        $this->requireDependency($requires);

        $result = TextExtractor::create()->extract($make());

        $this->assertSame(Status::Extracted, $result->status, (string) $result->message);
        $this->assertSame($format, $result->format);
        $this->assertStringContainsString($expected, $result->text);
        $this->assertTrue(mb_check_encoding($result->text, 'UTF-8'));
    }

    public function test_a_scanned_pdf_reports_no_text_layer(): void
    {
        $this->requireDependency('pdftotext');

        $result = TextExtractor::create()->extract(Fixtures::committed('pdf/blank.pdf'));

        $this->assertSame(Status::Empty, $result->status);
        $this->assertSame(Reason::NoTextLayer, $result->reason);
    }

    public function test_an_image_without_text_is_empty_not_failed(): void
    {
        $this->requireDependency('tesseract');

        $this->assertSame(Status::Empty, TextExtractor::create()->extract(Fixtures::committed('image/blank.png'))->status);
    }

    public function test_an_encrypted_pdf_is_not_retried(): void
    {
        $this->requireDependency('pdftotext');

        $result = TextExtractor::create()->extract(Fixtures::committed('pdf/encrypted.pdf'));

        $this->assertSame(Reason::Encrypted, $result->reason);
        $this->assertFalse($result->isRetryable());
    }

    public function test_a_misnamed_pdf_is_read_as_pdf(): void
    {
        $this->requireDependency('pdftotext');

        $copy = Fixtures::file((string) file_get_contents(Fixtures::committed('pdf/text.pdf')), 'txt');

        $this->assertSame(Format::Pdf, TextExtractor::create()->extract($copy)->format);
    }

    public function test_a_relative_path_reaches_external_tools(): void
    {
        $this->requireDependency('pdftotext');
        $previous = getcwd();
        $this->assertIsString($previous);
        chdir(dirname(Fixtures::committed('pdf/text.pdf')));

        try {
            $result = TextExtractor::create()->extract('text.pdf');
        } finally {
            chdir($previous);
        }

        $this->assertSame(Status::Extracted, $result->status, (string) $result->message);
    }

    private function requireDependency(?string $requires): void
    {
        if ($requires === null) {
            return;
        }

        if (str_starts_with($requires, 'ext-')) {
            $extension = substr($requires, 4);

            if (! extension_loaded($extension) || ($extension === 'zip' && ! extension_loaded('xmlreader'))) {
                $this->markTestSkipped($requires.' is not loaded.');
            }

            return;
        }

        if ((new ToolLocator)->find($requires) === null) {
            $this->markTestSkipped($requires.' is not installed.');
        }
    }
}
