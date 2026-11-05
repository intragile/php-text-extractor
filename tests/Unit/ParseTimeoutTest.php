<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit;

use Closure;
use IntrAgile\TextExtractor\Contracts\Extractor;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Result;
use IntrAgile\TextExtractor\Status;
use IntrAgile\TextExtractor\Support\ParseTimedOut;
use IntrAgile\TextExtractor\Tests\Support\FakeClock;
use IntrAgile\TextExtractor\Tests\Support\Fixtures;
use IntrAgile\TextExtractor\Tests\Support\OdfFixtures;
use IntrAgile\TextExtractor\Tests\Support\OoxmlFixtures;
use IntrAgile\TextExtractor\Tests\Support\TestCase;
use IntrAgile\TextExtractor\Tests\Support\XlsxFixtures;
use IntrAgile\TextExtractor\TextExtractor;
use PHPUnit\Framework\Attributes\DataProvider;

final class ParseTimeoutTest extends TestCase
{
    /**
     * Each document is large enough to need several deadline checks, so text has already been
     * produced when the limit runs out.
     *
     * @return iterable<string, array{Closure(): string, Format}>
     */
    public static function longDocuments(): iterable
    {
        yield 'xml' => [static fn (): string => Fixtures::file('<r>'.str_repeat('<p>word</p>', 5000).'</r>', 'xml'), Format::Xml];
        yield 'docx' => [static fn (): string => OoxmlFixtures::docx(str_repeat(OoxmlFixtures::paragraph('word'), 2000)), Format::Docx];
        yield 'pptx' => [static fn (): string => OoxmlFixtures::pptx([OoxmlFixtures::textShape(...array_fill(0, 2000, 'word'))]), Format::Pptx];
        yield 'xlsx' => [static fn (): string => XlsxFixtures::xlsx(['Sheet' => self::sheetRows(3000)], ['word']), Format::Xlsx];
        yield 'odt' => [static fn (): string => OdfFixtures::odf(OdfFixtures::TEXT, '<office:text>'.str_repeat('<text:p>word</text:p>', 5000).'</office:text>'), Format::Odt];
        yield 'rtf' => [static fn (): string => Fixtures::file('{\rtf1\ansi '.str_repeat('word\par ', 5000).'}', 'rtf'), Format::Rtf];
        yield 'txt' => [static fn (): string => Fixtures::file(str_repeat('word ', 40000), 'txt'), Format::Txt];
        yield 'csv' => [static fn (): string => Fixtures::file(str_repeat("alpha;beta\n", 20000), 'csv'), Format::Csv];
        yield 'html' => [static fn (): string => Fixtures::file('<html><body>'.str_repeat('<p>word</p>', 5000).'</body></html>', 'html'), Format::Html];
    }

    /**
     * @param  Closure(): string  $build
     */
    #[DataProvider('longDocuments')]
    public function test_parsing_past_the_limit_fails_with_parse_timeout(Closure $build, Format $format): void
    {
        $result = $this->extractorWith(FakeClock::expiringAfter(1))->extract($build());

        $this->assertSame(Status::Failed, $result->status);
        $this->assertSame(Reason::ParseTimeout, $result->reason);
        $this->assertSame($format, $result->format);
        $this->assertSame('', $result->text, 'Text produced before the limit must not be returned.');
        $this->assertFalse($result->truncated);
        $this->assertTrue($result->isRetryable());
        $this->assertStringContainsString('1 seconds', (string) $result->message);
    }

    /**
     * @param  Closure(): string  $build
     */
    #[DataProvider('longDocuments')]
    public function test_a_clock_that_stands_still_never_times_out(Closure $build, Format $format): void
    {
        $result = $this->extractorWith(new FakeClock)->extract($build());

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertSame($format, $result->format);
    }

    /**
     * @param  Closure(): string  $build
     */
    #[DataProvider('longDocuments')]
    public function test_the_default_limit_leaves_normal_documents_alone(Closure $build, Format $format): void
    {
        $result = TextExtractor::create()->extract($build());

        $this->assertSame(60, TextExtractor::create()->options()->maxParseSeconds);
        $this->assertSame(Status::Extracted, $result->status);
        $this->assertStringContainsString($format === Format::Csv ? 'alpha' : 'word', $result->text);
    }

    public function test_extracting_from_a_string_honours_the_limit(): void
    {
        $result = $this->extractorWith(FakeClock::expiringAfter(1))
            ->extractFromString('<r>'.str_repeat('<p>word</p>', 5000).'</r>', 'big.xml');

        $this->assertSame(Reason::ParseTimeout, $result->reason);
    }

    public function test_each_extraction_starts_a_fresh_deadline(): void
    {
        $clock = new FakeClock;
        $extractor = (new TextExtractor(new Options(maxParseSeconds: 5)))->withClock($clock->closure());
        $path = Fixtures::file('<r>'.str_repeat('<p>word</p>', 5000).'</r>', 'xml');

        $first = $extractor->extract($path);

        // Time spent between two calls does not count against the next one.
        $clock->advance(10);
        $second = $extractor->extract($path);

        $this->assertSame(Status::Extracted, $first->status);
        $this->assertSame(Status::Extracted, $second->status);
    }

    public function test_a_custom_extractor_signalling_the_timeout_is_reported_as_parse_timeout(): void
    {
        $extractor = new class implements Extractor
        {
            public function supports(Format $format): bool
            {
                return $format === Format::Txt;
            }

            public function extract(string $path, Format $format, Options $options): Result
            {
                throw new ParseTimedOut('Parsing did not finish within 1 seconds.');
            }
        };

        $result = (new TextExtractor(extractors: [$extractor]))->extract(Fixtures::file('hello', 'txt'));

        $this->assertSame(Status::Failed, $result->status);
        $this->assertSame(Reason::ParseTimeout, $result->reason);
        $this->assertSame(Format::Txt, $result->format);
    }

    private function extractorWith(FakeClock $clock): TextExtractor
    {
        return (new TextExtractor(new Options(maxParseSeconds: 1)))->withClock($clock->closure());
    }

    private static function sheetRows(int $count): string
    {
        $rows = '';

        for ($row = 1; $row <= $count; $row++) {
            $rows .= sprintf('<row r="%1$d"><c r="A%1$d" t="s"><v>0</v></c></row>', $row);
        }

        return $rows;
    }
}
