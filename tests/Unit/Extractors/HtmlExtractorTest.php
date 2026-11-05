<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Extractors;

use IntrAgile\TextExtractor\Extractors\HtmlExtractor;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Result;
use IntrAgile\TextExtractor\Status;
use IntrAgile\TextExtractor\Tests\Support\Fixtures;
use IntrAgile\TextExtractor\Tests\Support\TestCase;
use IntrAgile\TextExtractor\TextExtractor;

final class HtmlExtractorTest extends TestCase
{
    public function test_supports_only_html(): void
    {
        $extractor = new HtmlExtractor;

        $this->assertTrue($extractor->supports(Format::Html));
        $this->assertFalse($extractor->supports(Format::Xml));
        $this->assertFalse($extractor->supports(Format::Txt));
    }

    public function test_script_and_style_content_is_dropped(): void
    {
        $html = '<!doctype html><html><head><title>Page title</title><style>body { color: red }</style>'
            .'<script>var hidden = 1;</script><meta name="description" content="meta text"></head>'
            .'<body><p>Visible</p><script>alert("x")</script><noscript>No script</noscript>'
            .'<template><p>Template</p></template><style>.a{}</style></body></html>';

        $this->assertSame("Page title\nVisible", $this->extract($html)->text);
    }

    public function test_block_elements_become_lines(): void
    {
        $html = "<html><body>\n  <h1>Heading</h1>\n  <div>First <b>bold</b>\n    text</div>"
            .'<p>Line one<br>Line two</p><ul><li>apple</li><li>pear</li></ul>'
            .'<table><tr><th>Name</th><th>City</th></tr><tr><td>Árpád</td><td>Győr</td></tr></table>'
            .'<blockquote>Quoted</blockquote><pre>  keep   this'."\n".'  spacing</pre>'
            .'<span>inline</span> <span>words</span></body></html>';

        $this->assertSame(
            "Heading\nFirst bold text\nLine one\nLine two\napple\npear\nName\tCity\nÁrpád\tGyőr\nQuoted\n  keep   this\n  spacing\ninline words",
            $this->extract($html)->text,
        );
    }

    public function test_entities_are_decoded(): void
    {
        $this->assertSame('áő & <tag> "q"', $this->extract('<p>&aacute;&#337; &amp; &lt;tag&gt; &quot;q&quot;</p>')->text);
    }

    public function test_latin2_meta_charset_is_honoured(): void
    {
        $html = "<html><head><meta charset=\"ISO-8859-2\"><title>T\xFBz</title></head>"
            ."<body><p>Gy\xF5r, \xE1rv\xEDzt\xFBr\xF5 t\xFCk\xF6rf\xFAr\xF3g\xE9p</p></body></html>";

        $this->assertSame("Tűz\nGyőr, árvíztűrő tükörfúrógép", $this->extract($html)->text);
    }

    public function test_windows1250_http_equiv_charset_is_honoured(): void
    {
        $html = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=windows-1250"></head>'
            ."<body><p>\x8Akoda \xF5\xFB \x84id\xE9zet\x94</p></body></html>";

        $this->assertSame('Škoda őű „idézet”', $this->extract($html)->text);
    }

    public function test_undeclared_non_utf8_html_uses_the_fallback_encoding(): void
    {
        $result = (new TextExtractor(new Options(fallbackEncoding: 'Windows-1250'), extractors: [new HtmlExtractor]))
            ->extract(Fixtures::file("<p>Gy\xF5r</p>", 'html'));

        $this->assertSame('Győr', $result->text);
    }

    public function test_utf8_meta_charset_keeps_utf8(): void
    {
        $this->assertSame('árvíztűrő', $this->extract('<html><head><meta charset="utf-8"></head><body><p>árvíztűrő</p></body></html>')->text);
    }

    public function test_doctype_entities_do_not_leak_file_contents(): void
    {
        $secret = Fixtures::file('TOP-SECRET-CONTENT', 'txt');
        $html = '<!DOCTYPE html [<!ENTITY x SYSTEM "file://'.$secret.'">]><html><body><p>a &x; b</p></body></html>';

        $result = $this->extract($html);

        $this->assertStringNotContainsString('TOP-SECRET-CONTENT', $result->text);
    }

    public function test_markup_without_text_is_empty(): void
    {
        $this->assertSame(Status::Empty, $this->extract('<html><head><script>x()</script></head><body><div> </div></body></html>')->status);
    }

    public function test_output_is_truncated_at_the_limit(): void
    {
        $html = '<html><body>'.str_repeat('<p>árvíztűrő tükörfúrógép</p>', 10_000).'</body></html>';

        $result = (new HtmlExtractor)->extract(Fixtures::file($html, 'html'), Format::Html, new Options(maxOutputBytes: 100));

        $this->assertSame(Status::Extracted, $result->status);
        $this->assertTrue($result->truncated);
        $this->assertLessThanOrEqual(100, strlen($result->text));
        $this->assertTrue(mb_check_encoding($result->text, 'UTF-8'));
    }

    public function test_table_rows_are_lines_of_tab_separated_cells(): void
    {
        $html = '<html><body><p>Before</p><table><caption>Prices</caption>'
            .'<thead><tr><th><p>Item</p></th><th><div>Price</div></th></tr></thead>'
            .'<tbody><tr><td><p>Apple</p><p>red</p></td><td>1<br>EUR</td></tr>'
            .'<tr><td></td><td><table><tr><td>in1</td><td>in2</td></tr><tr><td>in3</td></tr></table></td></tr>'
            .'<tr><td> </td><td></td></tr></tbody></table><p>After</p></body></html>';

        $this->assertSame("Before\nPrices\nItem\tPrice\nApple red\t1 EUR\n\tin1 in2 in3\nAfter", $this->extract($html)->text);
    }

    public function test_only_the_document_title_is_used(): void
    {
        $html = '<html><head><title>Real title</title></head><body>'
            .'<svg><title>icon</title><text>drawn</text></svg><p>Body</p></body></html>';

        $this->assertSame("Real title\ndrawn\nBody", $this->extract($html)->text);
    }

    public function test_svg_title_without_a_document_title_is_not_used_as_title(): void
    {
        $html = '<html><body><svg><title>icon</title></svg><p>Body</p></body></html>';

        $this->assertSame('Body', $this->extract($html)->text);
    }

    public function test_meta_declared_utf16_is_treated_as_utf8(): void
    {
        $this->assertSame('plain ascii and ő', $this->extract('<html><head><meta charset="utf-16"></head><body><p>plain ascii and ő</p></body></html>')->text);
        $this->assertSame('le', $this->extract('<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-16LE"></head><body><p>le</p></body></html>')->text);
    }

    public function test_bom_detected_utf16_is_decoded(): void
    {
        $html = "\xFF\xFE".mb_convert_encoding('<html><head><meta charset="utf-16"></head><body><p>árvíztűrő</p></body></html>', 'UTF-16LE', 'UTF-8');

        $this->assertSame('árvíztűrő', $this->extract($html)->text);
    }

    public function test_html_above_the_size_limit_is_not_extractable(): void
    {
        $path = Fixtures::file('<html><body><p>'.str_repeat('x', 200).'</p></body></html>', 'html');

        $result = (new HtmlExtractor)->extract($path, Format::Html, new Options(maxHtmlBytes: 100));

        $this->assertSame(Status::NotExtractable, $result->status);
        $this->assertSame(Reason::TooLarge, $result->reason);
        $this->assertSame(Format::Html, $result->format);
        $this->assertSame(Status::Extracted, (new HtmlExtractor)->extract($path, Format::Html, new Options(maxHtmlBytes: 1000))->status);
    }

    public function test_parse_errors_are_not_collected_in_memory(): void
    {
        if (! function_exists('memory_reset_peak_usage')) {
            $this->markTestSkipped('memory_reset_peak_usage() requires PHP 8.2.');
        }

        // Every unterminated tag is a parse error; collecting them would cost about fifteen times
        // the size of this 2.8 MB input.
        $path = Fixtures::file(str_repeat('<meta charset ', 200_000).'<p>text</p>', 'html');
        $previous = libxml_use_internal_errors(true);

        try {
            memory_reset_peak_usage();
            $before = memory_get_usage();
            $result = (new HtmlExtractor)->extract($path, Format::Html, new Options);

            $this->assertSame(Status::Extracted, $result->status);
            $this->assertLessThan(16 * 1048576, memory_get_peak_usage() - $before);
            $this->assertSame([], libxml_get_errors());
            $this->assertTrue(libxml_use_internal_errors(true), 'The caller\'s libxml error mode is restored.');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function test_extractor_keeps_no_state_between_calls(): void
    {
        $extractor = new HtmlExtractor;
        $extractor->extract(Fixtures::file('<p>secret text</p>', 'html'), Format::Html, new Options);

        $this->assertStringNotContainsString('secret', serialize((array) $extractor));
        $this->assertSame('other', (new TextExtractor(extractors: [$extractor]))->extract(Fixtures::file('<p>other</p>', 'html'))->text);
    }

    private function extract(string $html): Result
    {
        return (new TextExtractor(extractors: [new HtmlExtractor]))->extract(Fixtures::file($html, 'html'));
    }
}
