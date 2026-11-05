<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Extractors;

use IntrAgile\TextExtractor\Extractors\PdfExtractor;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Process\ProcessRunner;
use IntrAgile\TextExtractor\Process\ToolLocator;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Result;
use IntrAgile\TextExtractor\Status;
use IntrAgile\TextExtractor\Tests\Support\Fixtures;
use IntrAgile\TextExtractor\Tests\Support\TestCase;
use IntrAgile\TextExtractor\TextExtractor;
use PHPUnit\Framework\Attributes\DataProvider;

final class PdfExtractorTest extends TestCase
{
    public function test_supports_only_pdf(): void
    {
        $extractor = $this->extractor();

        $this->assertTrue($extractor->supports(Format::Pdf));
        $this->assertFalse($extractor->supports(Format::Txt));
        $this->assertFalse($extractor->supports(Format::Png));
    }

    public function test_text_pdf(): void
    {
        $this->requirePdftotext();

        $result = $this->extract('pdf/text.pdf');

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertSame(Format::Pdf, $result->format);
        $this->assertSame("Hello PDF world\nSecond line of the text fixture", $result->text);
        $this->assertFalse($result->truncated);
    }

    public function test_pages_are_separated_by_a_blank_line_and_accents_survive(): void
    {
        $this->requirePdftotext();

        $result = $this->extract('pdf/multipage.pdf');

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertSame(
            "árvíztűrő tükörfúrógép\nFirst page\n\nÁRVÍZTŰRŐ TÜKÖRFÚRÓGÉP\nSecond page",
            $result->text,
        );
    }

    public function test_blank_pdf_is_empty_with_no_text_layer(): void
    {
        $this->requirePdftotext();

        $result = $this->extract('pdf/blank.pdf');

        $this->assertSame(Status::Empty, $result->status);
        $this->assertSame(Reason::NoTextLayer, $result->reason);
        $this->assertSame(Format::Pdf, $result->format);
    }

    public function test_encrypted_pdf_is_not_extractable(): void
    {
        $this->requirePdftotext();

        $result = $this->extract('pdf/encrypted.pdf');

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::Encrypted, $result->reason);
    }

    public function test_owner_password_only_pdf_is_extracted(): void
    {
        $this->requirePdftotext();

        $result = $this->extract('pdf/owner-only.pdf');

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertStringContainsString('Hello PDF world', $result->text);
    }

    public function test_corrupt_pdf_is_not_extractable(): void
    {
        $this->requirePdftotext();

        $result = $this->extract('pdf/corrupt.pdf');

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::Corrupt, $result->reason);
    }

    public function test_truncated_pdf_is_not_extractable(): void
    {
        $this->requirePdftotext();

        $bytes = (string) file_get_contents(Fixtures::committed('pdf/text.pdf'));
        $result = $this->orchestrator()->extract(Fixtures::file(substr($bytes, 0, intdiv(strlen($bytes), 2)), 'pdf'));

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::Corrupt, $result->reason);
    }

    public function test_damaged_content_stream_is_corrupt_not_failed(): void
    {
        $this->requirePdftotext();

        $result = $this->extract('pdf/damaged-stream.pdf');

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::Corrupt, $result->reason);
    }

    public function test_unknown_operator_on_a_blank_page_is_empty_without_a_text_layer(): void
    {
        $this->requirePdftotext();

        $result = $this->extract('pdf/unknown-operator.pdf');

        $this->assertSame(Status::Empty, $result->status);
        $this->assertSame(Reason::NoTextLayer, $result->reason);
    }

    public function test_content_syntax_errors_alone_keep_a_page_without_text_empty(): void
    {
        $result = $this->runFake('echo "Syntax Error (12): Unknown operator \'foo\'" >&2; echo "Syntax Error (40): Too few (1) args to \'m\' operator" >&2; printf "\f"; exit 0');

        $this->assertSame(Status::Empty, $result->status);
        $this->assertSame(Reason::NoTextLayer, $result->reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function structuralErrors(): iterable
    {
        yield 'xref' => ["Syntax Error: Couldn't read xref table"];
        yield 'trailer' => ["Syntax Error: Couldn't find trailer dictionary"];
        yield 'not a pdf' => ['Syntax Error: May not be a PDF file (continuing anyway)'];
        yield 'damaged stream' => ['Syntax Error (278): Unexpected end of file in flate stream'];
    }

    #[DataProvider('structuralErrors')]
    public function test_structural_errors_without_text_are_corrupt(string $line): void
    {
        $result = $this->runFake('echo '.escapeshellarg($line).' >&2; printf "\f"; exit 0');

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::Corrupt, $result->reason);
    }

    public function test_truncation_cuts_on_a_character_boundary(): void
    {
        $result = $this->runFake("printf '\303\241\303\241\303\241\303\241\303\241'", new Options(maxOutputBytes: 5));

        $this->assertTrue($result->truncated);
        $this->assertSame('áá', $result->text);
    }

    public function test_missing_binary_is_failed_missing_tool(): void
    {
        $result = $this->orchestrator(new Options(pdftotextBinary: '/nonexistent/pdftotext'))
            ->extract(Fixtures::committed('pdf/text.pdf'));

        $this->assertSame(Status::Failed, $result->status);
        $this->assertSame(Reason::MissingTool, $result->reason);
        $this->assertSame(Format::Pdf, $result->format);
    }

    public function test_empty_output_with_diagnostics_is_tool_failed(): void
    {
        $result = $this->runFake('echo "Out of memory" >&2; exit 0');

        $this->assertSame(Status::Failed, $result->status);
        $this->assertSame(Reason::ToolFailed, $result->reason);
    }

    public function test_harmless_parse_warnings_on_a_scan_keep_it_empty_without_a_text_layer(): void
    {
        $result = $this->runFake('echo "Syntax Warning: May not be a PDF file (continuing anyway)" >&2; printf "\f"; exit 0');

        $this->assertSame(Status::Empty, $result->status);
        $this->assertSame(Reason::NoTextLayer, $result->reason);
    }

    public function test_unknown_diagnostics_beside_parse_errors_are_tool_failed(): void
    {
        $result = $this->runFake('echo "Syntax Error: Illegal character" >&2; echo "Internal Error: something broke" >&2; exit 0');

        $this->assertSame(Reason::ToolFailed, $result->reason);
    }

    public function test_out_of_memory_on_open_is_tool_failed_not_corrupt(): void
    {
        $result = $this->runFake('echo "Out of memory" >&2; exit 1');

        $this->assertSame(Reason::ToolFailed, $result->reason);
    }

    public function test_permission_error_exit_code_is_encrypted(): void
    {
        $result = $this->runFake('exit 3');

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::Encrypted, $result->reason);
    }

    public function test_other_exit_codes_are_tool_failed(): void
    {
        $result = $this->runFake('echo "boom" >&2; exit 99');

        $this->assertSame(Status::Failed, $result->status);
        $this->assertSame(Reason::ToolFailed, $result->reason);
        $this->assertStringContainsString('boom', (string) $result->message);
    }

    public function test_slow_tool_times_out(): void
    {
        $result = $this->runFake('exec sleep 10', new Options(processTimeout: 1));

        $this->assertSame(Status::Failed, $result->status);
        $this->assertSame(Reason::ToolTimeout, $result->reason);
    }

    public function test_form_feeds_become_blank_lines(): void
    {
        $result = $this->runFake('printf "one\\f two\\f"');

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertSame("one\n\n two\n\n", $result->text);
    }

    public function test_output_beyond_the_limit_is_truncated(): void
    {
        $result = $this->runFake('printf "%0500d" 0', new Options(maxOutputBytes: 100));

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertTrue($result->truncated);
        $this->assertSame(100, strlen($result->text));
    }

    public function test_form_feed_expansion_is_bounded_by_the_limit(): void
    {
        $result = $this->runFake('printf "\\f\\f\\f\\fabcdef"', new Options(maxOutputBytes: 10));

        $this->assertTrue($result->truncated);
        $this->assertLessThanOrEqual(10, strlen($result->text));
    }

    private function extractor(): PdfExtractor
    {
        return new PdfExtractor(new ProcessRunner, new ToolLocator);
    }

    private function orchestrator(?Options $options = null): TextExtractor
    {
        return new TextExtractor($options, extractors: [$this->extractor()]);
    }

    private function extract(string $fixture): Result
    {
        return $this->orchestrator()->extract(Fixtures::committed($fixture));
    }

    /**
     * Runs the extractor against a stand-in pdftotext whose behaviour is the given shell body.
     */
    private function runFake(string $body, ?Options $options = null): Result
    {
        $script = Fixtures::path('sh');
        file_put_contents($script, "#!/bin/sh\n".$body."\n");
        chmod($script, 0755);

        $options = ($options ?? new Options)->with(['pdftotextBinary' => $script]);

        return $this->extractor()->extract(Fixtures::committed('pdf/text.pdf'), Format::Pdf, $options);
    }

    private function requirePdftotext(): void
    {
        if ((new ToolLocator)->find('pdftotext') === null) {
            $this->markTestSkipped('pdftotext is not installed.');
        }
    }
}
