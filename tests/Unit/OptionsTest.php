<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit;

use IntrAgile\TextExtractor\Options;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    public function test_defaults_match_the_documented_values(): void
    {
        $options = new Options;

        $this->assertSame(104857600, $options->maxInputBytes);
        $this->assertSame(10485760, $options->maxOutputBytes);
        $this->assertSame(268435456, $options->maxArchiveEntryBytes);
        $this->assertSame(536870912, $options->maxArchiveTotalBytes);
        $this->assertSame(10000, $options->maxArchiveEntries);
        $this->assertSame(16000000, $options->maxImagePixels);
        $this->assertSame(60, $options->processTimeout);
        $this->assertSame(512, $options->processMemoryMb);
        $this->assertNull($options->pdftotextBinary);
        $this->assertNull($options->tesseractBinary);
        $this->assertSame('eng', $options->ocrLanguages);
        $this->assertSame('Windows-1252', $options->fallbackEncoding);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function positiveLimits(): iterable
    {
        foreach (['maxInputBytes', 'maxOutputBytes', 'maxArchiveEntryBytes', 'maxArchiveTotalBytes', 'maxArchiveEntries', 'maxImagePixels', 'processTimeout'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('positiveLimits')]
    public function test_negative_limit_is_rejected(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Options)->with([$name => -1]);
    }

    #[DataProvider('positiveLimits')]
    public function test_zero_limit_is_rejected(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Options)->with([$name => 0]);
    }

    public function test_zero_memory_cap_disables_the_cap(): void
    {
        $this->assertSame(0, (new Options(processMemoryMb: 0))->processMemoryMb);
    }

    public function test_negative_memory_cap_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Options(processMemoryMb: -1);
    }

    public function test_with_overrides_only_named_values(): void
    {
        $base = new Options(ocrLanguages: 'hun+eng');
        $changed = $base->with(['maxOutputBytes' => 100]);

        $this->assertSame(100, $changed->maxOutputBytes);
        $this->assertSame('hun+eng', $changed->ocrLanguages);
        $this->assertSame(10485760, $base->maxOutputBytes);
    }

    public function test_with_rejects_unknown_option_names(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Options)->with(['maxBananas' => 1]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidLanguages(): iterable
    {
        yield 'empty' => [''];
        yield 'shell' => ['eng; rm -rf /'];
        yield 'trailing plus' => ['eng+'];
        yield 'too short' => ['en'];
    }

    #[DataProvider('invalidLanguages')]
    public function test_invalid_ocr_languages_are_rejected(string $languages): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Options(ocrLanguages: $languages);
    }

    public function test_combined_ocr_languages_are_accepted(): void
    {
        $this->assertSame('hun+eng+chi_sim', (new Options(ocrLanguages: 'hun+eng+chi_sim'))->ocrLanguages);
    }

    public function test_unknown_fallback_encoding_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Options(fallbackEncoding: 'Klingon-1');
    }
}
