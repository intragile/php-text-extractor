<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Extractors;

use IntrAgile\TextExtractor\Extractors\ImageExtractor;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Process\ProcessRunner;
use IntrAgile\TextExtractor\Process\ToolLocator;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Status;
use IntrAgile\TextExtractor\Tests\Support\Fixtures;
use IntrAgile\TextExtractor\Tests\Support\TestCase;
use IntrAgile\TextExtractor\TextExtractor;

final class ImageExtractorTest extends TestCase
{
    public function test_supports_every_image_format_and_nothing_else(): void
    {
        $extractor = $this->extractor();

        foreach (Format::cases() as $format) {
            $this->assertSame($format->isImage(), $extractor->supports($format), $format->value);
        }
    }

    public function test_text_image_is_recognised(): void
    {
        $this->requireTesseract();

        $result = $this->orchestrator(new Options)->extract(Fixtures::committed('image/text.png'));

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertSame(Format::Png, $result->format);
        $this->assertStringContainsString('invoice', strtolower($result->text));
    }

    public function test_blank_image_is_empty(): void
    {
        $this->requireTesseract();

        $result = $this->orchestrator(new Options)->extract(Fixtures::committed('image/blank.png'));

        $this->assertSame(Status::Empty, $result->status);
        $this->assertNull($result->reason);
    }

    public function test_oversized_image_is_refused_without_running_tesseract(): void
    {
        $flag = Fixtures::path('flag');
        $binary = $this->fakeTesseract('touch '.escapeshellarg($flag));

        $result = $this->orchestrator(new Options(maxImagePixels: 100, tesseractBinary: $binary))
            ->extract(Fixtures::committed('image/text.png'));

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::ImageTooLarge, $result->reason);
        $this->assertFileDoesNotExist($flag);
    }

    public function test_unreadable_header_is_left_to_tesseract(): void
    {
        $flag = Fixtures::path('flag');
        $binary = $this->fakeTesseract('touch '.escapeshellarg($flag));

        $this->extractor()->extract(
            Fixtures::file("\xFF\xD8\xFF garbage", 'jpg'),
            Format::Jpeg,
            new Options(maxImagePixels: 1, tesseractBinary: $binary),
        );

        $this->assertFileExists($flag);
    }

    public function test_missing_language_pack_is_failed_tool_failed(): void
    {
        $this->requireTesseract();

        $result = $this->orchestrator(new Options(ocrLanguages: 'zzz'))->extract(Fixtures::committed('image/text.png'));

        $this->assertSame(Status::Failed, $result->status);
        $this->assertSame(Reason::ToolFailed, $result->reason);
        $this->assertStringContainsString('zzz', (string) $result->message);
    }

    public function test_missing_binary_is_failed_missing_tool(): void
    {
        $result = $this->orchestrator(new Options(tesseractBinary: '/nonexistent/bin/tesseract'))
            ->extract(Fixtures::committed('image/text.png'));

        $this->assertSame(Status::Failed, $result->status);
        $this->assertSame(Reason::MissingTool, $result->reason);
    }

    public function test_tesseract_receives_the_file_and_the_language_list(): void
    {
        $binary = $this->fakeTesseract('printf "%s|" "$@"');
        $path = Fixtures::committed('image/text.png');

        $result = $this->extractor()->extract($path, Format::Png, new Options(tesseractBinary: $binary, ocrLanguages: 'hun+eng'));

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertSame($path.'|stdout|-l|hun+eng|quiet|', $result->text);
    }

    public function test_benign_diagnostics_with_empty_output_are_empty(): void
    {
        $binary = $this->fakeTesseract('echo "Estimating resolution as 642" >&2; echo "Empty page!!" >&2');

        $result = $this->orchestrator(new Options(tesseractBinary: $binary))->extract(Fixtures::committed('image/blank.png'));

        $this->assertSame(Status::Empty, $result->status);
    }

    public function test_empty_output_with_an_error_on_stderr_is_failed(): void
    {
        $binary = $this->fakeTesseract('echo "Estimating resolution as 642" >&2; echo "Error in pixCreateNoInit: pix_malloc fail for data" >&2');

        $result = $this->orchestrator(new Options(tesseractBinary: $binary))->extract(Fixtures::committed('image/blank.png'));

        $this->assertSame(Status::Failed, $result->status);
        $this->assertSame(Reason::ToolFailed, $result->reason);
        $this->assertStringContainsString('pix_malloc fail', (string) $result->message);
    }

    public function test_non_zero_exit_is_failed(): void
    {
        $binary = $this->fakeTesseract('echo "Error during processing." >&2; exit 1');

        $result = $this->extractor()->extract(Fixtures::committed('image/text.png'), Format::Png, new Options(tesseractBinary: $binary));

        $this->assertSame(Status::Failed, $result->status);
        $this->assertSame(Reason::ToolFailed, $result->reason);
    }

    public function test_timeout_is_failed_tool_timeout(): void
    {
        $binary = $this->fakeTesseract('exec sleep 5');

        $result = $this->extractor()->extract(
            Fixtures::committed('image/text.png'),
            Format::Png,
            new Options(processTimeout: 1, tesseractBinary: $binary),
        );

        $this->assertSame(Status::Failed, $result->status);
        $this->assertSame(Reason::ToolTimeout, $result->reason);
    }

    public function test_output_is_truncated_on_a_utf8_boundary(): void
    {
        $binary = $this->fakeTesseract("printf '\\303\\241\\303\\241\\303\\241'");

        $result = $this->extractor()->extract(
            Fixtures::committed('image/text.png'),
            Format::Png,
            new Options(maxOutputBytes: 5, tesseractBinary: $binary),
        );

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertSame('áá', $result->text);
        $this->assertTrue($result->truncated);
    }

    private function extractor(): ImageExtractor
    {
        return new ImageExtractor(new ProcessRunner, new ToolLocator);
    }

    private function orchestrator(Options $options): TextExtractor
    {
        return new TextExtractor($options, extractors: [$this->extractor()]);
    }

    private function requireTesseract(): void
    {
        if ((new ToolLocator)->find('tesseract') === null) {
            $this->markTestSkipped('tesseract is not installed.');
        }
    }

    private function fakeTesseract(string $body): string
    {
        $path = Fixtures::path('sh');
        file_put_contents($path, "#!/bin/sh\n".$body."\n");
        chmod($path, 0755);

        return $path;
    }
}
