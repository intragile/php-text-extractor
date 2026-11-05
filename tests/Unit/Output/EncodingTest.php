<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Output;

use IntrAgile\TextExtractor\Output\Encoding;
use IntrAgile\TextExtractor\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class EncodingTest extends TestCase
{
    /**
     * Sample bytes per built-in code page, the expected text and a byte the code page leaves
     * undefined (null when every byte is assigned).
     *
     * @return iterable<string, array{string, string, string, string|null}>
     */
    public static function codePages(): iterable
    {
        yield 'Greek' => ['Windows-1253', "\xE1\xE2\xE3\xC1", "\u{03B1}\u{03B2}\u{03B3}\u{0391}", "\xAA"];
        yield 'Hebrew' => ['Windows-1255', "\xF9\xEC\xE5\xED", "\u{05E9}\u{05DC}\u{05D5}\u{05DD}", "\xFB"];
        yield 'Arabic' => ['Windows-1256', "\xE3\xD1\xCD\xC8\xC7", "\u{0645}\u{0631}\u{062D}\u{0628}\u{0627}", null];
        yield 'Baltic' => ['Windows-1257', "\xD0\xE0\xEB", "\u{0160}\u{0105}\u{0117}", "\xA1"];
        yield 'Vietnamese' => ['Windows-1258', "\xD0\xF5\xFD", "\u{0110}\u{01A1}\u{01B0}", "\x8A"];
        yield 'Thai' => ['CP874', "\xA1\xA2\xA3", "\u{0E01}\u{0E02}\u{0E03}", "\xDB"];
        yield 'IBM PC' => ['CP437', "\x82\x81\x90\xC9", "\u{00E9}\u{00FC}\u{00C9}\u{2554}", null];
    }

    #[DataProvider('codePages')]
    public function test_built_in_code_page_is_decoded(string $encoding, string $bytes, string $expected, ?string $undefined): void
    {
        $this->assertTrue(Encoding::isSupported($encoding));
        $this->assertSame('a'.$expected.'z', Encoding::toUtf8('a'.$bytes.'z', $encoding));

        if ($undefined !== null) {
            $this->assertSame("a\u{FFFD}z", Encoding::toUtf8('a'.$undefined.'z', $encoding));
        }
    }

    public function test_code_page_aliases_are_case_insensitive(): void
    {
        $this->assertSame("\u{03B1}", Encoding::toUtf8("\xE1", 'cp1253'));
        $this->assertSame("\u{03B1}", Encoding::toUtf8("\xE1", 'WINDOWS-1253'));
        $this->assertSame("\u{0E01}", Encoding::toUtf8("\xA1", 'windows-874'));
        $this->assertSame("\u{00E9}", Encoding::toUtf8("\x82", 'IBM437'));
    }

    public function test_windows_1250_still_uses_its_table(): void
    {
        $this->assertSame('árvíztűrő', Encoding::toUtf8("\xE1rv\xEDzt\xFBr\xF5", 'Windows-1250'));
        $this->assertSame("\u{FFFD}", Encoding::toUtf8("\x81", 'Windows-1250'));
    }

    public function test_other_encodings_go_through_mbstring(): void
    {
        $this->assertSame("\u{041F}", Encoding::toUtf8("\xCF", 'Windows-1251'));
        $this->assertFalse(Encoding::isSupported('no-such-encoding'));
    }
}
