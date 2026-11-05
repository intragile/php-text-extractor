<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Detection;

use IntrAgile\TextExtractor\Detection\FormatDetector;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Tests\Support\Fixtures;
use IntrAgile\TextExtractor\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class FormatDetectorTest extends TestCase
{
    /**
     * @return \Generator<string, array{string, Format}>
     */
    public static function signatures(): \Generator
    {
        yield 'pdf' => ["%PDF-1.7\n%\xE2\xE3\xCF\xD3\n", Format::Pdf];
        yield 'png' => ["\x89PNG\r\n\x1a\n\0\0\0\rIHDR", Format::Png];
        yield 'jpeg' => ["\xFF\xD8\xFF\xE0\0\x10JFIF\0", Format::Jpeg];
        yield 'tiff little endian' => ["II*\0\x08\0\0\0", Format::Tiff];
        yield 'tiff big endian' => ["MM\0*\0\0\0\x08", Format::Tiff];
        yield 'bmp' => ["BM\x36\0\0\0\0\0\0\0\x36\0\0\0", Format::Bmp];
        yield 'gif87a' => ["GIF87a\x01\0\x01\0", Format::Gif];
        yield 'gif89a' => ["GIF89a\x01\0\x01\0", Format::Gif];
        yield 'webp' => ["RIFF\x24\0\0\0WEBPVP8 ", Format::Webp];
        yield 'rtf' => ['{\rtf1\ansi Hello}', Format::Rtf];
    }

    #[DataProvider('signatures')]
    public function test_binary_signatures(string $bytes, Format $expected): void
    {
        $this->assertSame($expected, (new FormatDetector)->detect(Fixtures::file($bytes, 'dat')));
    }

    public function test_a_pdf_named_txt_is_detected_as_pdf(): void
    {
        $this->assertSame(Format::Pdf, (new FormatDetector)->detect(Fixtures::file("%PDF-1.4\n", 'txt')));
    }

    public function test_riff_that_is_not_webp_is_unknown(): void
    {
        $this->assertNull((new FormatDetector)->detect(Fixtures::file("RIFF\x24\0\0\0WAVEfmt ", 'webp')));
    }

    /**
     * @return \Generator<string, array{array<string, string>, Format}>
     */
    public static function officeZips(): \Generator
    {
        yield 'docx' => [['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w:document/>'], Format::Docx];
        yield 'xlsx' => [['[Content_Types].xml' => '<Types/>', 'xl/workbook.xml' => '<workbook/>'], Format::Xlsx];
        yield 'pptx' => [['[Content_Types].xml' => '<Types/>', 'ppt/presentation.xml' => '<p:presentation/>'], Format::Pptx];
        yield 'odt' => [['mimetype' => 'application/vnd.oasis.opendocument.text', 'content.xml' => '<x/>'], Format::Odt];
        yield 'ods' => [['mimetype' => 'application/vnd.oasis.opendocument.spreadsheet', 'content.xml' => '<x/>'], Format::Ods];
        yield 'odp' => [['mimetype' => 'application/vnd.oasis.opendocument.presentation', 'content.xml' => '<x/>'], Format::Odp];
    }

    /**
     * @param  array<string, string>  $entries
     */
    #[DataProvider('officeZips')]
    public function test_office_containers_are_recognised_by_their_parts(array $entries, Format $expected): void
    {
        $this->assertSame($expected, (new FormatDetector)->detect(Fixtures::zip($entries, 'zip')));
    }

    public function test_odt_mimetype_wins_over_docx_extension(): void
    {
        $path = Fixtures::zip(['mimetype' => 'application/vnd.oasis.opendocument.text', 'content.xml' => '<x/>'], 'docx');

        $this->assertSame(Format::Odt, (new FormatDetector)->detect($path));
    }

    public function test_zip_without_office_markers_is_unknown(): void
    {
        $this->assertNull((new FormatDetector)->detect(Fixtures::zip(['readme.txt' => 'hello'], 'docx')));
    }

    public function test_unknown_opendocument_mimetype_is_unknown(): void
    {
        $path = Fixtures::zip(['mimetype' => 'application/vnd.oasis.opendocument.graphics', 'content.xml' => '<x/>'], 'odg');

        $this->assertNull((new FormatDetector)->detect($path));
    }

    public function test_ole_container_is_unknown(): void
    {
        $this->assertNull((new FormatDetector)->detect(Fixtures::file("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 64), 'doc')));
    }

    public function test_binary_with_nul_and_no_known_signature_is_unknown(): void
    {
        $this->assertNull((new FormatDetector)->detect(Fixtures::file("\x01\x02\x00\x03garbage", 'txt')));
    }

    /**
     * @return \Generator<string, array{string, Format}>
     */
    public static function textExtensions(): \Generator
    {
        yield 'txt' => ['txt', Format::Txt];
        yield 'log' => ['log', Format::Txt];
        yield 'text' => ['text', Format::Txt];
        yield 'md' => ['md', Format::Markdown];
        yield 'markdown' => ['markdown', Format::Markdown];
        yield 'csv' => ['csv', Format::Csv];
        yield 'tsv' => ['tsv', Format::Tsv];
        yield 'json' => ['json', Format::Json];
        yield 'xml' => ['xml', Format::Xml];
        yield 'html' => ['html', Format::Html];
        yield 'htm' => ['htm', Format::Html];
        yield 'xhtml' => ['xhtml', Format::Html];
        yield 'upper case' => ['CSV', Format::Csv];
    }

    #[DataProvider('textExtensions')]
    public function test_text_files_are_typed_by_extension(string $extension, Format $expected): void
    {
        $this->assertSame($expected, (new FormatDetector)->detect(Fixtures::file('a,b', $extension)));
    }

    public function test_filename_hint_wins_over_path_extension(): void
    {
        $path = Fixtures::file('a;b', 'tmp');

        $this->assertSame(Format::Csv, (new FormatDetector)->detect($path, 'export.csv'));
    }

    public function test_html_without_extension_is_sniffed(): void
    {
        $this->assertSame(Format::Html, (new FormatDetector)->detect(Fixtures::file("  \n<!DOCTYPE html><html><body>x</body></html>", 'tmp')));
        $this->assertSame(Format::Html, (new FormatDetector)->detect(Fixtures::file('<HTML><body>x</body></HTML>', 'tmp')));
    }

    public function test_xml_without_extension_is_sniffed(): void
    {
        $this->assertSame(Format::Xml, (new FormatDetector)->detect(Fixtures::file('<?xml version="1.0"?><a>x</a>', 'tmp')));
    }

    public function test_other_text_without_extension_is_plain_text(): void
    {
        $this->assertSame(Format::Txt, (new FormatDetector)->detect(Fixtures::file('just words', 'tmp')));
    }

    public function test_empty_file_is_plain_text(): void
    {
        $this->assertSame(Format::Txt, (new FormatDetector)->detect(Fixtures::file('', 'tmp')));
    }

    public function test_utf16_text_with_bom_is_text(): void
    {
        $bytes = "\xFF\xFE".mb_convert_encoding('hello', 'UTF-16LE', 'UTF-8');

        $this->assertSame(Format::Csv, (new FormatDetector)->detect(Fixtures::file($bytes, 'csv')));
    }

    public function test_missing_file_is_unknown(): void
    {
        $this->assertNull((new FormatDetector)->detect('/nonexistent/file.pdf'));
    }
}
