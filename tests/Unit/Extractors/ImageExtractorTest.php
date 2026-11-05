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
use PHPUnit\Framework\Attributes\DataProvider;

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

    /**
     * @return iterable<string, array{string}>
     */
    public static function multiPageImages(): iterable
    {
        yield 'little-endian TIFF' => ['image/two-pages-le.tif'];
        yield 'big-endian TIFF' => ['image/two-pages-be.tif'];
        yield 'GIF frame larger than the logical screen' => ['image/two-frames.gif'];
    }

    #[DataProvider('multiPageImages')]
    public function test_any_oversized_page_or_frame_is_refused(string $fixture): void
    {
        $flag = Fixtures::path('flag');
        $binary = $this->fakeTesseract('touch '.escapeshellarg($flag));

        // The first page has 200 pixels, the second 20 000.
        $result = $this->orchestrator(new Options(maxImagePixels: 1000, tesseractBinary: $binary))
            ->extract(Fixtures::committed($fixture));

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::ImageTooLarge, $result->reason);
        $this->assertStringContainsString('200 x 100', (string) $result->message);
        $this->assertFileDoesNotExist($flag);

        $this->assertSame(Status::Empty, $this->orchestrator(new Options(maxImagePixels: 20000, tesseractBinary: $binary))
            ->extract(Fixtures::committed($fixture))->status);
        $this->assertFileExists($flag);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function synthesizedTiffs(): iterable
    {
        $small = [[10, 10], [10, 10]];
        $large = [[10, 10], [10, 10], [4000, 1000]];

        yield 'little-endian, large third page' => [self::tiff('II', $large), 1];
        yield 'big-endian, large third page' => [self::tiff('MM', $large), 1];
        yield 'BigTIFF, large third page' => [self::tiff('II', $large, bigTiff: true), 1];
        yield 'big-endian BigTIFF, large third page' => [self::tiff('MM', $large, bigTiff: true), 1];
        yield 'tags out of order' => [self::tiff('II', $large, unsorted: true), 1];
        yield 'small pages' => [self::tiff('MM', $small), 0];
        yield 'directory chain that loops back' => [self::tiff('II', $small, loop: true), 0];
        yield 'directory offset past the end of the file' => [substr(self::tiff('II', $large), 0, 40), 0];
        yield 'directory offset inside the header' => ["II\x2A\x00\x02\x00\x00\x00", 0];
        yield 'too many pages to verify' => [self::tiff('II', array_fill(0, 10001, [1, 1])), 1];
    }

    #[DataProvider('synthesizedTiffs')]
    public function test_every_tiff_directory_is_checked(string $bytes, int $refused): void
    {
        $flag = Fixtures::path('flag');
        $binary = $this->fakeTesseract('touch '.escapeshellarg($flag));

        $result = $this->extractor()->extract(Fixtures::file($bytes, 'tif'), Format::Tiff, new Options(maxImagePixels: 100, tesseractBinary: $binary));

        if ($refused === 1) {
            $this->assertSame(Status::NotExtractable, $result->status);
            $this->assertSame(Reason::ImageTooLarge, $result->reason);
            $this->assertFileDoesNotExist($flag);
        } else {
            $this->assertNotSame(Reason::ImageTooLarge, $result->reason);
            $this->assertFileExists($flag);
        }
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function synthesizedGifs(): iterable
    {
        yield 'large second frame' => [self::gif(10, 10, [[10, 10], [500, 500]]), 1];
        yield 'large logical screen' => [self::gif(500, 500, [[10, 10]]), 1];
        yield 'small frames' => [self::gif(10, 10, [[10, 10], [5, 5]]), 0];
        yield 'truncated before the second frame' => [substr(self::gif(10, 10, [[10, 10], [500, 500]]), 0, 40), 0];
        yield 'not followed by any block' => ["GIF89a\x0A\x00\x0A\x00\x00\x00\x00", 0];
    }

    #[DataProvider('synthesizedGifs')]
    public function test_every_gif_frame_is_checked(string $bytes, int $refused): void
    {
        $flag = Fixtures::path('flag');
        $binary = $this->fakeTesseract('touch '.escapeshellarg($flag));

        $result = $this->extractor()->extract(Fixtures::file($bytes, 'gif'), Format::Gif, new Options(maxImagePixels: 100, tesseractBinary: $binary));

        if ($refused === 1) {
            $this->assertSame(Reason::ImageTooLarge, $result->reason);
            $this->assertFileDoesNotExist($flag);
        } else {
            $this->assertNotSame(Reason::ImageTooLarge, $result->reason);
            $this->assertFileExists($flag);
        }
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

    public function test_tesseract_runs_single_threaded_so_thread_stacks_fit_the_memory_cap(): void
    {
        $fake = $this->fakeTesseract('echo "threads=$OMP_THREAD_LIMIT"');
        $result = $this->orchestrator(new Options(tesseractBinary: $fake))->extract(Fixtures::committed('image/text.png'));

        $this->assertSame('threads=1', $result->text);
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

    /**
     * Builds the header and directory chain of a TIFF; pixel data is irrelevant to the size check.
     *
     * @param  list<array{int, int}>  $pages  width and height of each page
     */
    private static function tiff(string $order, array $pages, bool $bigTiff = false, bool $unsorted = false, bool $loop = false): string
    {
        $big = $order === 'MM';
        $u16 = static fn (int $v): string => pack($big ? 'n' : 'v', $v);
        $u32 = static fn (int $v): string => pack($big ? 'N' : 'V', $v);
        $u64 = static fn (int $v): string => $big ? pack('J', $v) : pack('P', $v);
        $offset = $bigTiff ? 16 : 8;
        $header = $order.($bigTiff ? $u16(43).$u16(8).$u16(0).$u64($offset) : $u16(42).$u32($offset));
        $entrySize = $bigTiff ? 20 : 12;
        $ifdSize = ($bigTiff ? 8 : 2) + 3 * $entrySize + ($bigTiff ? 8 : 4);
        $body = '';

        foreach ($pages as $index => [$width, $height]) {
            $entries = [
                [256, 4, $width],
                [257, 3, $height],
                [258, 3, 8],
            ];

            if ($unsorted) {
                $entries = [$entries[2], $entries[1], $entries[0]];
            }

            $next = $index === count($pages) - 1 ? ($loop ? $offset : 0) : $offset + ($index + 1) * $ifdSize;
            $body .= $bigTiff ? $u64(count($entries)) : $u16(count($entries));

            foreach ($entries as [$tag, $type, $value]) {
                $field = $type === 3 ? $u16($value).($bigTiff ? str_repeat("\0", 6) : "\0\0") : ($bigTiff ? $u32($value)."\0\0\0\0" : $u32($value));
                $body .= $u16($tag).$u16($type).($bigTiff ? $u64(1) : $u32(1)).$field;
            }

            $body .= $bigTiff ? $u64($next) : $u32($next);
        }

        return $header.$body;
    }

    /**
     * Builds a GIF with the given logical screen and frames, each holding a few bytes of image data.
     *
     * @param  list<array{int, int}>  $frames
     */
    private static function gif(int $width, int $height, array $frames): string
    {
        // Global colour table of two entries, then a looping extension before the frames.
        $gif = 'GIF89a'.pack('vv', $width, $height)."\x80\x00\x00".str_repeat("\x00\xFF", 3)
            ."\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";

        foreach ($frames as [$frameWidth, $frameHeight]) {
            $gif .= "\x21\xF9\x04\x00\x00\x00\x00\x00"
                ."\x2C".pack('vvvv', 0, 0, $frameWidth, $frameHeight)."\x00"
                ."\x02\x02\x4C\x01\x00";
        }

        return $gif."\x3B";
    }

    private function fakeTesseract(string $body): string
    {
        $path = Fixtures::path('sh');
        file_put_contents($path, "#!/bin/sh\n".$body."\n");
        chmod($path, 0755);

        return $path;
    }
}
