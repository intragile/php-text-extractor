<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Tests\Unit\Extractors;

use IntrAgile\TextExtractor\Extractors\HtmlExtractor;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
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

    private function extract(string $html): Result
    {
        return (new TextExtractor(extractors: [new HtmlExtractor]))->extract(Fixtures::file($html, 'html'));
    }
}
