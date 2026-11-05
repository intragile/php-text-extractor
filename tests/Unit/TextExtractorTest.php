<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit;

use ErrorException;
use IntrAgile\TextExtractor\Archive\ArchiveLimitExceeded;
use IntrAgile\TextExtractor\Contracts\Extractor;
use IntrAgile\TextExtractor\Exception\CorruptInput;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Result;
use IntrAgile\TextExtractor\Status;
use IntrAgile\TextExtractor\Tests\Support\Fixtures;
use IntrAgile\TextExtractor\Tests\Support\TestCase;
use IntrAgile\TextExtractor\TextExtractor;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

final class TextExtractorTest extends TestCase
{
    private static function extractorReturning(Result|Throwable $outcome): Extractor
    {
        return new class($outcome) implements Extractor
        {
            public function __construct(private readonly Result|Throwable $outcome) {}

            public function supports(Format $format): bool
            {
                return $format === Format::Txt;
            }

            public function extract(string $path, Format $format, Options $options): Result
            {
                if ($this->outcome instanceof Throwable) {
                    throw $this->outcome;
                }

                return $this->outcome;
            }
        };
    }

    public function test_plain_text_is_extracted(): void
    {
        $result = TextExtractor::create()->extract(Fixtures::file("Hello\r\nworld\n", 'txt'));

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertSame("Hello\nworld", $result->text);
        $this->assertSame(Format::Txt, $result->format);
        $this->assertFalse($result->truncated);
    }

    public function test_missing_file_is_failed_unreadable(): void
    {
        $result = TextExtractor::create()->extract('/nonexistent/file.txt');

        $this->assertSame(Status::Failed, $result->status);
        $this->assertSame(Reason::Unreadable, $result->reason);
    }

    public function test_directory_is_failed_unreadable(): void
    {
        $this->assertSame(Reason::Unreadable, TextExtractor::create()->extract(sys_get_temp_dir())->reason);
    }

    public function test_oversized_input_is_not_extractable(): void
    {
        $result = TextExtractor::create(new Options(maxInputBytes: 10))->extract(Fixtures::file(str_repeat('a', 11), 'txt'));

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::TooLarge, $result->reason);
    }

    public function test_input_at_the_limit_is_accepted(): void
    {
        $result = TextExtractor::create(new Options(maxInputBytes: 10))->extract(Fixtures::file(str_repeat('a', 10), 'txt'));

        $this->assertSame(Status::Extracted, $result->status);
    }

    public function test_unknown_binary_is_unsupported(): void
    {
        $result = TextExtractor::create()->extract(Fixtures::file("\x00\x01\x02binary", 'bin'));

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::UnsupportedFormat, $result->reason);
        $this->assertNull($result->format);
    }

    public function test_detected_format_without_an_extractor_is_unsupported(): void
    {
        $result = new TextExtractor(extractors: []);

        $this->assertSame(Reason::UnsupportedFormat, $result->extract(Fixtures::file('text', 'txt'))->reason);
    }

    public function test_custom_extractor_wins(): void
    {
        $extractor = TextExtractor::create()->withExtractor(self::extractorReturning(Result::extracted('custom', Format::Txt)));

        $this->assertSame('custom', $extractor->extract(Fixtures::file('original', 'txt'))->text);
    }

    public function test_with_extractor_does_not_change_the_original(): void
    {
        $original = TextExtractor::create();
        $original->withExtractor(self::extractorReturning(Result::extracted('custom', Format::Txt)));

        $this->assertSame('original', $original->extract(Fixtures::file('original', 'txt'))->text);
    }

    public function test_extractor_exception_becomes_internal_error(): void
    {
        $result = TextExtractor::create()
            ->withExtractor(self::extractorReturning(new RuntimeException('kaboom')))
            ->extract(Fixtures::file('x', 'txt'));

        $this->assertSame(Status::Failed, $result->status);
        $this->assertSame(Reason::InternalError, $result->reason);
        $this->assertSame(Format::Txt, $result->format);
        $this->assertStringContainsString('kaboom', (string) $result->message);
    }

    public function test_archive_limit_exception_becomes_not_extractable(): void
    {
        $result = TextExtractor::create()
            ->withExtractor(self::extractorReturning(new ArchiveLimitExceeded('too big')))
            ->extract(Fixtures::file('x', 'txt'));

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::ArchiveLimitExceeded, $result->reason);
    }

    public function test_corrupt_input_exception_becomes_corrupt(): void
    {
        $result = TextExtractor::create()
            ->withExtractor(self::extractorReturning(new CorruptInput('broken')))
            ->extract(Fixtures::file('x', 'txt'));

        $this->assertSame(Reason::Corrupt, $result->reason);
    }

    public function test_whitespace_only_text_file_is_empty_not_failed(): void
    {
        $result = TextExtractor::create()->extract(Fixtures::file(" \n\t\r\n  ", 'txt'));

        $this->assertSame(Status::Empty, $result->status);
        $this->assertSame('', $result->text);
        $this->assertSame(Format::Txt, $result->format);
    }

    public function test_zero_byte_file_is_empty(): void
    {
        $this->assertSame(Status::Empty, TextExtractor::create()->extract(Fixtures::file('', 'txt'))->status);
    }

    public function test_extractor_empty_result_keeps_its_reason(): void
    {
        $result = TextExtractor::create()
            ->withExtractor(self::extractorReturning(Result::empty(Format::Txt, Reason::NoTextLayer)))
            ->extract(Fixtures::file('x', 'txt'));

        $this->assertSame(Status::Empty, $result->status);
        $this->assertSame(Reason::NoTextLayer, $result->reason);
    }

    public function test_output_from_a_custom_extractor_is_capped(): void
    {
        $result = TextExtractor::create(new Options(maxOutputBytes: 5))
            ->withExtractor(self::extractorReturning(Result::extracted('ááááá', Format::Txt)))
            ->extract(Fixtures::file('x', 'txt'));

        $this->assertSame('áá', $result->text);
        $this->assertTrue($result->truncated);
    }

    public function test_extract_from_string_and_stream_match_extract(): void
    {
        $extractor = TextExtractor::create();
        $path = Fixtures::file("alpha;beta\n1;2", 'csv');
        $stream = fopen($path, 'rb');
        $this->assertIsResource($stream);

        $fromPath = $extractor->extract($path);
        $fromString = $extractor->extractFromString("alpha;beta\n1;2", 'export.csv');
        $fromStream = $extractor->extractFromStream($stream, 'export.csv');
        fclose($stream);

        $this->assertSame(Format::Csv, $fromPath->format);
        $this->assertEquals($fromPath, $fromString);
        $this->assertEquals($fromPath, $fromStream);
    }

    public function test_oversized_string_and_stream_are_not_extractable(): void
    {
        $extractor = TextExtractor::create(new Options(maxInputBytes: 10));
        $stream = fopen('php://memory', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, str_repeat('a', 11));
        rewind($stream);

        $this->assertSame(Reason::TooLarge, $extractor->extractFromString(str_repeat('a', 11))->reason);
        $this->assertSame(Reason::TooLarge, $extractor->extractFromStream($stream)->reason);
        fclose($stream);
    }

    public function test_extract_from_stream_rejects_a_non_resource(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TextExtractor::create()->extractFromStream('not a stream');
    }

    public function test_temp_files_are_removed(): void
    {
        $pattern = sys_get_temp_dir().'/txx_*';
        $before = glob($pattern) ?: [];
        $extractor = TextExtractor::create();
        $stream = fopen('php://memory', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, 'streamed');
        rewind($stream);

        $extractor->extractFromString('from a string', 'a.txt');
        $extractor->extractFromStream($stream, 'b.txt');
        $extractor->withExtractor(self::extractorReturning(new RuntimeException('x')))->extractFromString('boom', 'c.txt');
        fclose($stream);

        $this->assertSame($before, glob($pattern) ?: []);
    }

    public function test_truncated_text_that_normalises_to_nothing_is_not_reported_as_empty(): void
    {
        $result = TextExtractor::create()
            ->withExtractor(self::extractorReturning(Result::extracted(" \n ", Format::Txt, true)))
            ->extract(Fixtures::file('x', 'txt'));

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertSame('', $result->text);
        $this->assertTrue($result->truncated);
    }

    public function test_text_cut_to_nothing_by_the_output_limit_stays_truncated(): void
    {
        $result = TextExtractor::create(new Options(maxOutputBytes: 1))
            ->withExtractor(self::extractorReturning(Result::extracted('ááá', Format::Txt)))
            ->extract(Fixtures::file('x', 'txt'));

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertSame('', $result->text);
        $this->assertTrue($result->truncated);
    }

    public function test_unreadable_stream_is_failed_without_raising_errors(): void
    {
        $directory = fopen(sys_get_temp_dir(), 'rb');
        $this->assertIsResource($directory);

        $result = $this->withErrorsAsExceptions(static fn (): Result => TextExtractor::create()->extractFromStream($directory, 'a.txt'));
        fclose($directory);

        $this->assertSame(Status::Failed, $result->status);
        $this->assertSame(Reason::Unreadable, $result->reason);
    }

    public function test_write_only_stream_is_reported_as_unreadable(): void
    {
        $stream = fopen(Fixtures::file('', 'txt'), 'wb');
        $this->assertIsResource($stream);

        $result = $this->withErrorsAsExceptions(static fn (): Result => TextExtractor::create()->extractFromStream($stream, 'a.txt'));
        fclose($stream);

        $this->assertSame(Reason::Unreadable, $result->reason);
        $this->assertSame('The input stream cannot be read.', $result->message);
    }

    public function test_stream_is_read_when_the_input_limit_is_unbounded(): void
    {
        $stream = fopen('php://memory', 'w+b');
        $this->assertIsResource($stream);
        fwrite($stream, 'unbounded');
        rewind($stream);

        $result = TextExtractor::create(new Options(maxInputBytes: PHP_INT_MAX))->extractFromStream($stream, 'a.txt');
        fclose($stream);

        $this->assertSame('unbounded', $result->text);
    }

    /**
     * @return iterable<string, array{string, string, Format}>
     */
    public static function binaryNamesWithTextContent(): iterable
    {
        yield 'empty docx' => ['', 'report.docx', Format::Docx];
        yield 'empty pdf' => ['', 'scan.pdf', Format::Pdf];
        yield 'empty xlsx' => ['', 'sheet.xlsx', Format::Xlsx];
        yield 'text named pptx' => ['plain words', 'deck.pptx', Format::Pptx];
        yield 'text named odt' => ['plain words', 'letter.odt', Format::Odt];
        yield 'text named png' => ['plain words', 'photo.png', Format::Png];
        yield 'empty jpg' => ['', 'photo.JPG', Format::Jpeg];
    }

    #[DataProvider('binaryNamesWithTextContent')]
    public function test_binary_file_name_with_mismatching_content_is_corrupt(string $contents, string $name, Format $format): void
    {
        $result = TextExtractor::create()->extractFromString($contents, $name);

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::Corrupt, $result->reason);
        $this->assertSame($format, $result->format);
    }

    public function test_misnamed_binary_content_still_wins_over_the_extension(): void
    {
        $result = TextExtractor::create()->extractFromString('{\\rtf1 hello}', 'report.docx');

        $this->assertSame(Format::Rtf, $result->format);
        $this->assertSame('hello', $result->text);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withErrorsAsExceptions(callable $callback): mixed
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }

    public function test_options_are_exposed(): void
    {
        $options = new Options(maxOutputBytes: 42);

        $this->assertSame($options, TextExtractor::create($options)->options());
        $this->assertSame(7, TextExtractor::create()->withOptions(new Options(maxOutputBytes: 7))->options()->maxOutputBytes);
    }
}
