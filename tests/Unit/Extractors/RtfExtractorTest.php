<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Extractors;

use IntrAgile\TextExtractor\Extractors\RtfExtractor;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Output\TextBuffer;
use IntrAgile\TextExtractor\Result;
use IntrAgile\TextExtractor\Status;
use IntrAgile\TextExtractor\Tests\Support\Fixtures;
use IntrAgile\TextExtractor\Tests\Support\TestCase;
use IntrAgile\TextExtractor\TextExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionObject;

final class RtfExtractorTest extends TestCase
{
    public function test_supports_only_rtf(): void
    {
        $extractor = new RtfExtractor;

        $this->assertTrue($extractor->supports(Format::Rtf));
        $this->assertFalse($extractor->supports(Format::Txt));
        $this->assertFalse($extractor->supports(Format::Docx));
    }

    public function test_plain_paragraphs(): void
    {
        $result = $this->extract('{\rtf1\ansi\deff0 First paragraph.\par Second\line line.\par\pard Third.}');

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertSame(Format::Rtf, $result->format);
        $this->assertSame("First paragraph.\nSecond\nline.\nThird.", $result->text);
    }

    public function test_ansi_codepage_1250_hex_escapes(): void
    {
        $this->assertSame('árvíztűrő', $this->extract("{\\rtf1\\ansi\\ansicpg1250 \\'e1rv\\'edzt\\'fbr\\'f5}")->text);
    }

    public function test_ansi_codepage_1252_hex_escapes(): void
    {
        $this->assertSame('café “x”', $this->extract("{\\rtf1\\ansi\\ansicpg1252 caf\\'e9 \\'93x\\'94}")->text);
    }

    public function test_missing_codepage_uses_the_fallback_encoding(): void
    {
        $result = $this->extract("{\\rtf1\\ansi Gy\\'f5r}", new Options(fallbackEncoding: 'Windows-1250'));

        $this->assertSame('Győr', $result->text);
    }

    public function test_multibyte_codepage_hex_bytes_are_decoded_together(): void
    {
        // Shift-JIS: 日 = 93 FA, 本 = 96 7B.
        $this->assertSame('日本語', $this->extract("{\\rtf1\\ansi\\ansicpg932 \\'93\\'fa\\'96\\'7b\\'8c\\'ea}")->text);
    }

    public function test_font_charset_overrides_the_document_codepage(): void
    {
        $rtf = '{\\rtf1\\ansi\\ansicpg1252\\deff0{\\fonttbl{\\f0\\fswiss\\fcharset0 Arial;}{\\f1\\fswiss\\fcharset238 Arial CE;}}'
            ."\\f0 caf\\'e9 {\\f1 \\'f5r}\\f1 \\'fb}";

        $this->assertSame('café őrű', $this->extract($rtf)->text);
    }

    public function test_unicode_escape_skips_fallback_char(): void
    {
        $this->assertSame('ő', $this->extract('{\rtf1\ansi \u337?}')->text);
    }

    public function test_unicode_skip_count_follows_uc_and_counts_hex_escape_as_one(): void
    {
        $this->assertSame('őx', $this->extract("{\\rtf1\\ansi\\uc2 \\u337\\'f5\\'f5x}")->text);
        $this->assertSame('ab', $this->extract('{\rtf1\ansi\uc0 a\u98 }')->text);
    }

    public function test_uc_value_is_scoped_to_its_group(): void
    {
        $this->assertSame('őőx', $this->extract('{\rtf1\ansi{\uc2\u337??}\u337?x}')->text);
    }

    public function test_negative_unicode_values_wrap_around(): void
    {
        $this->assertSame('！', $this->extract('{\rtf1\ansi \u-255?}')->text);
    }

    public function test_surrogate_pairs_combine(): void
    {
        $this->assertSame('a😀b', $this->extract('{\rtf1\ansi a\u-10179?\u-8704?b}')->text);
    }

    public function test_lone_surrogate_becomes_replacement_character(): void
    {
        $this->assertSame("a\u{FFFD}b", $this->extract('{\rtf1\ansi a\u-10179?b}')->text);
    }

    public function test_font_table_and_pictures_are_skipped(): void
    {
        $rtf = '{\rtf1\ansi{\fonttbl{\f0\froman Times New Roman;}{\f1 Courier;}}'
            .'{\colortbl;\red255\green0\blue0;}'
            .'{\stylesheet{\s0 Normal;}}'
            .'{\info{\title Secret Title}{\author Someone}}'
            .'Visible {\pict\pngblip\picw10 89504e470d0a1a0a}text'
            .'{\object\objemb{\*\objdata 01050000}}'
            .'{\header Page header}{\footerr Page footer}.}';

        $this->assertSame('Visible text.', $this->extract($rtf)->text);
    }

    public function test_ignorable_destinations_and_field_instructions_are_skipped(): void
    {
        $rtf = '{\rtf1\ansi{\*\generator Riched20 10.0.19041}{\*\unknownthing hidden}'
            .'See {\field{\*\fldinst{HYPERLINK "https://example.com"}}{\fldrslt{\ul Link text}}}.}';

        $this->assertSame('See Link text.', $this->extract($rtf)->text);
    }

    public function test_wordpad_header_does_not_leak(): void
    {
        $rtf = "{\\rtf1\\ansi\\ansicpg1250\\deff0\\nouicompat\\deflang1038{\\fonttbl{\\f0\\fnil\\fcharset238 Calibri;}{\\f1\\fnil\\fcharset0 Segoe UI Symbol;}}\r\n"
            ."{\\colortbl ;\\red0\\green0\\blue255;\\red0\\green77\\blue187;}\r\n"
            ."{\\*\\generator Riched20 10.0.19041}\\viewkind4\\uc1 \r\n"
            ."\\pard\\sa200\\sl276\\slmult1\\f0\\fs22\\lang14 Tisztelt \\'dcgyf\\'e9l\\'fcnk!\\par\r\n"
            ."\\b Fontos:\\b0  a sz\\'e1mla mell\\'e9kelve.\\par\r\n"
            ."}\r\n\0";

        $this->assertSame("Tisztelt Ügyfélünk!\nFontos: a számla mellékelve.", $this->extract($rtf)->text);
    }

    public function test_word_style_header_does_not_leak(): void
    {
        $rtf = '{\rtf1\adeflang1025\ansi\ansicpg1252\uc1\adeff31507\deff0\stshfdbch31505{\fonttbl{\f0\fbidi \froman\fcharset0\fprq2{\*\panose 02020603050405020304}Times New Roman;}'
            .'{\flomajor\f31500\fbidi \froman\fcharset0\fprq2{\*\panose 02020603050405020304}Times New Roman;}}'
            .'{\colortbl;\red0\green0\blue0;}{\*\defchp \fs22\loch\af31506}{\stylesheet{\ql \li0\ri0\widctlpar\wrapdefault\aspalpha\aspnum\faauto\adjustright\rin0\lin0\itap0 \rtlch\fcs1 \af31507\afs22 \snext0 \sqformat \spriority0 Normal;}}'
            .'{\*\listtable{\list\listtemplateid1{\listlevel{\leveltext\\\'02\\\'00.;}{\levelnumbers\\\'01;}}{\listname ;}\listid1}}'
            .'{\*\listoverridetable{\listoverride\listid1\listoverridecount0\ls1}}'
            .'{\*\rsidtbl \rsid1}{\mmathPr\mmathFont34}{\info{\author Jane}{\operator Jane}{\creatim\yr2026\mo11\dy5}}'
            .'{\*\xmlnstbl {\xmlns1 http://schemas.microsoft.com/office/word/2003/wordml}}'
            .'{\*\latentstyles\lsdstimax376\lsdlockeddef0{\lsdlockedexcept \lsdqformat1 Normal;\lsdqformat1 heading 1;}}'
            .'{\*\themedata 504b030414}{\*\datastore 0105000002}'
            .'\paperw12240\paperh15840\margl1440 \sectd \pard\plain \ql\rtlch\fcs1 \af31507 Hello \ldblquote World\rdblquote  \endash  ok.\par}';

        $this->assertSame('Hello “World” – ok.', $this->extract($rtf)->text);
    }

    public function test_tabs_and_table_cells(): void
    {
        $rtf = '{\rtf1\ansi a\tab b\par\trowd\cellx1000\cellx2000 \intbl x\cell y\cell\row z}';

        $this->assertSame("a\tb\nx\ty\nz", $this->extract($rtf)->text);
    }

    public function test_literal_escapes_and_special_characters(): void
    {
        $this->assertSame("a{b}c\\d\u{A0}ef\u{2011}g", $this->extract('{\rtf1\ansi a\{b\}c\\\\d\~e\-f\_g}')->text);
    }

    public function test_control_word_delimiter_space_is_consumed(): void
    {
        $this->assertSame('bold  twox', $this->extract('{\rtf1\ansi\b bold\b0   two\fs24 x}')->text);
    }

    public function test_bin_skips_raw_bytes(): void
    {
        $this->assertSame('AZ', $this->extract("{\\rtf1\\ansi A\\bin4 }{\\\x00Z}")->text);
    }

    public function test_bin_inside_skipped_picture_does_not_break_grouping(): void
    {
        $this->assertSame('AZ', $this->extract('{\\rtf1\\ansi A{\\pict\\bin6 }}}{{\\}Z}')->text);
    }

    public function test_raw_line_breaks_are_ignored_but_escaped_ones_are_paragraphs(): void
    {
        $this->assertSame("one two\nthree", $this->extract("{\\rtf1\\ansi one\r\n two\\\nthree}")->text);
    }

    public function test_unbalanced_braces_do_not_crash(): void
    {
        $cases = [
            '{\rtf1\ansi unclosed text' => 'unclosed text',
            '{\rtf1\ansi abc}}}} def' => 'abc def',
            '{\rtf1}}}}{\ansi xyz' => 'xyz',
            '{\rtf1\ansi {{{{deep' => 'deep',
            '{\rtf1\ansi ok\\' => 'ok',
            "{\\rtf1\\ansi ok\\'4" => 'ok',
            '{\rtf1\ansi ok\u' => 'ok',
            '{\rtf1\ansi ok\bin999999999 xx' => 'ok',
            '{\rtf1\ansi ok\bin-5 xx' => 'okxx',
        ];

        foreach ($cases as $rtf => $expected) {
            $result = $this->extract($rtf);

            $this->assertSame(Status::Extracted, $result->status, $rtf);
            $this->assertSame($expected, $result->text, $rtf);
        }

        $unclosedTable = $this->extract('{\rtf1\ansi{\fonttbl{\f0 Arial;}');
        $this->assertNotSame(Status::Failed, $unclosedTable->status);
    }

    public function test_deep_nesting_is_bounded(): void
    {
        $rtf = '{\rtf1\ansi '.str_repeat('{', 200_000).'deep'.str_repeat('}', 200_000).' end}';

        $this->assertSame('deep end', $this->extract($rtf)->text);
    }

    public function test_output_is_truncated_at_the_limit(): void
    {
        $rtf = '{\rtf1\ansi '.str_repeat('abc \par ', 100_000).'}';
        $result = (new RtfExtractor)->extract(Fixtures::file($rtf, 'rtf'), Format::Rtf, new Options(maxOutputBytes: 100));

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertLessThanOrEqual(100, strlen($result->text));
        $this->assertTrue($result->truncated);
    }

    public function test_document_without_text_is_empty(): void
    {
        $this->assertSame(Status::Empty, $this->extract('{\rtf1\ansi{\fonttbl{\f0 Arial;}}\pard\par}')->status);
    }

    public function test_unreadable_file_fails(): void
    {
        $result = (new RtfExtractor)->extract(Fixtures::path('rtf'), Format::Rtf, new Options);

        $this->assertSame(Status::Failed, $result->status);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function builtInCodePages(): iterable
    {
        yield 'ansicpg 1253' => ["{\\rtf1\\ansi\\ansicpg1253 \\'e1\\'e2\\'e3}", "\u{03B1}\u{03B2}\u{03B3}"];
        yield 'ansicpg 874' => ["{\\rtf1\\ansi\\ansicpg874 \\'a1\\'a2}", "\u{0E01}\u{0E02}"];
        yield 'ansicpg 437' => ["{\\rtf1\\pc\\ansicpg437 caf\\'82}", "caf\u{00E9}"];
        yield 'fcharset 161 Greek' => ["{\\rtf1\\ansi\\ansicpg1252{\\fonttbl{\\f0\\fcharset161 Arial;}}\\f0 \\'e1\\'e2\\'e3}", "\u{03B1}\u{03B2}\u{03B3}"];
        yield 'fcharset 177 Hebrew' => ["{\\rtf1\\ansi\\ansicpg1252{\\fonttbl{\\f0\\fcharset177 Arial;}}\\f0 \\'f9\\'ec\\'e5\\'ed}", "\u{05E9}\u{05DC}\u{05D5}\u{05DD}"];
        yield 'fcharset 178 Arabic' => ["{\\rtf1\\ansi\\ansicpg1252{\\fonttbl{\\f0\\fcharset178 Arial;}}\\f0 \\'e3\\'d1\\'cd\\'c8\\'c7}", "\u{0645}\u{0631}\u{062D}\u{0628}\u{0627}"];
        yield 'fcharset 186 Baltic' => ["{\\rtf1\\ansi\\ansicpg1252{\\fonttbl{\\f0\\fcharset186 Arial;}}\\f0 \\'d0\\'e0\\'eb}", "\u{0160}\u{0105}\u{0117}"];
        yield 'fcharset 163 Vietnamese' => ["{\\rtf1\\ansi\\ansicpg1252{\\fonttbl{\\f0\\fcharset163 Arial;}}\\f0 \\'d0\\'f5\\'fd}", "\u{0110}\u{01A1}\u{01B0}"];
        yield 'fcharset 222 Thai' => ["{\\rtf1\\ansi\\ansicpg1252{\\fonttbl{\\f0\\fcharset222 Tahoma;}}\\f0 \\'a1\\'a2}", "\u{0E01}\u{0E02}"];
    }

    #[DataProvider('builtInCodePages')]
    public function test_code_pages_missing_from_mbstring_are_decoded(string $rtf, string $expected): void
    {
        $this->assertSame($expected, $this->extract($rtf)->text);
    }

    public function test_a_reused_extractor_keeps_no_document_state_between_calls(): void
    {
        $extractor = new RtfExtractor;
        $extractor->extract(Fixtures::file('{\rtf1\ansi '.str_repeat('abc \par ', 10_000).'}', 'rtf'), Format::Rtf, new Options);

        foreach ((new ReflectionObject($extractor))->getProperties() as $property) {
            $value = $property->getValue($extractor);

            $this->assertFalse(
                $value instanceof TextBuffer && $value->toString() !== '',
                sprintf('Property "%s" still holds the previous document text.', $property->getName()),
            );
        }
    }

    private function extract(string $rtf, ?Options $options = null): Result
    {
        return TextExtractor::create($options)
            ->withExtractor(new RtfExtractor)
            ->extract(Fixtures::file($rtf, 'rtf'));
    }
}
