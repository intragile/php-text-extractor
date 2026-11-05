<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Extractors;

use IntrAgile\TextExtractor\Contracts\Extractor;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Output\Encoding;
use IntrAgile\TextExtractor\Output\TextBuffer;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Result;

/**
 * Extracts the visible text of an RTF document with a single-pass tokenizer.
 *
 * Group state (skipped destination, \uc value, current font) is kept on a stack so that it is
 * restored when a group closes. Malformed input is handled on a best-effort basis: unbalanced
 * braces, truncated escapes and oversized \bin lengths are tolerated rather than reported.
 */
final class RtfExtractor implements Extractor
{
    /**
     * Deeper groups share the state of the deepest tracked one, which bounds memory on hostile input.
     */
    private const MAX_DEPTH = 512;

    /**
     * Pending code page bytes are decoded in batches of roughly this size.
     */
    private const FLUSH_AT = 8192;

    /**
     * Destinations whose content is metadata or binary data rather than document text.
     */
    private const SKIPPED_DESTINATIONS = [
        'fonttbl' => true, 'colortbl' => true, 'stylesheet' => true, 'info' => true,
        'pict' => true, 'object' => true, 'objdata' => true,
        'header' => true, 'headerl' => true, 'headerr' => true, 'headerf' => true,
        'footer' => true, 'footerl' => true, 'footerr' => true, 'footerf' => true,
        'themedata' => true, 'datastore' => true, 'xmlnstbl' => true,
        'listtable' => true, 'listoverridetable' => true, 'rsidtbl' => true,
        'generator' => true, 'latentstyles' => true, 'fldinst' => true,
        'filetbl' => true, 'revtbl' => true, 'pgdsctbl' => true,
    ];

    private const SYMBOL_WORDS = [
        'par' => "\n", 'line' => "\n", 'row' => "\n", 'sect' => "\n", 'page' => "\n", 'nestrow' => "\n",
        'tab' => "\t", 'cell' => "\t", 'nestcell' => "\t",
        'lquote' => "\u{2018}", 'rquote' => "\u{2019}", 'ldblquote' => "\u{201C}", 'rdblquote' => "\u{201D}",
        'bullet' => "\u{2022}", 'endash' => "\u{2013}", 'emdash' => "\u{2014}",
        'enspace' => "\u{2002}", 'emspace' => "\u{2003}", 'qmspace' => "\u{2005}",
    ];

    /**
     * Windows code page for each \fcharset value that differs from the document code page.
     */
    private const CHARSET_CODEPAGES = [
        128 => 932, 129 => 949, 134 => 936, 136 => 950, 161 => 1253, 162 => 1254, 163 => 1258,
        177 => 1255, 178 => 1256, 186 => 1257, 204 => 1251, 222 => 874, 238 => 1250,
    ];

    private const REPLACEMENT = "\u{FFFD}";

    private string $data = '';

    private int $pos = 0;

    private int $length = 0;

    private TextBuffer $buffer;

    private bool $full = false;

    private string $fallbackEncoding = 'Windows-1252';

    private bool $skip = false;

    private int $uc = 1;

    private ?int $font = null;

    private bool $inFontTable = false;

    /** @var list<array{bool, int, int|null, bool}> */
    private array $saved = [];

    private int $overflow = 0;

    private ?int $overflowSkipFrom = null;

    private int $fallbackToSkip = 0;

    private ?int $highSurrogate = null;

    private string $pendingBytes = '';

    private string $pendingEncoding = '';

    private ?int $documentCodepage = null;

    private ?int $defaultFont = null;

    private ?int $definingFont = null;

    /** @var array<int, int> */
    private array $fontCodepages = [];

    /** @var array<int, string|null> */
    private array $encodingCache = [];

    public function __construct()
    {
        $this->buffer = new TextBuffer(1);
    }

    public function supports(Format $format): bool
    {
        return $format === Format::Rtf;
    }

    public function extract(string $path, Format $format, Options $options): Result
    {
        $data = @file_get_contents($path);

        if ($data === false) {
            return Result::failed(Reason::Unreadable, $format, 'The file cannot be read.');
        }

        // The tokenizer state lives on a throwaway instance, so an extractor kept as a long-lived
        // service never holds on to a document or up to maxOutputBytes of its text.
        $parser = new self;
        $parser->reset($data, $options);
        $parser->parse();

        return Result::extracted($parser->buffer->toString(), $format, $parser->buffer->isTruncated());
    }

    private function reset(string $data, Options $options): void
    {
        $this->data = $data;
        $this->pos = 0;
        $this->length = strlen($data);
        $this->buffer = new TextBuffer($options->maxOutputBytes);
        $this->full = false;
        $this->fallbackEncoding = $options->fallbackEncoding;
        $this->skip = false;
        $this->uc = 1;
        $this->font = null;
        $this->inFontTable = false;
        $this->saved = [];
        $this->overflow = 0;
        $this->overflowSkipFrom = null;
        $this->fallbackToSkip = 0;
        $this->highSurrogate = null;
        $this->pendingBytes = '';
        $this->pendingEncoding = '';
        $this->documentCodepage = null;
        $this->defaultFont = null;
        $this->definingFont = null;
        $this->fontCodepages = [];
    }

    private function parse(): void
    {
        while ($this->pos < $this->length && ! $this->full) {
            $char = $this->data[$this->pos];

            if ($char === '{') {
                $this->pos++;
                $this->openGroup();
            } elseif ($char === '}') {
                $this->pos++;
                $this->closeGroup();
            } elseif ($char === '\\') {
                $this->pos++;
                $this->controlSequence();
            } elseif ($char === "\r" || $char === "\n") {
                // Raw line breaks are formatting of the RTF source, not of the document.
                $this->pos++;
            } else {
                $run = strcspn($this->data, "\\{}\r\n", $this->pos);
                $this->text(substr($this->data, $this->pos, $run));
                $this->pos += $run;
            }
        }

        $this->resolveLoneSurrogate();
        $this->flush();
    }

    private function openGroup(): void
    {
        $this->fallbackToSkip = 0;

        if (count($this->saved) >= self::MAX_DEPTH) {
            $this->overflow++;

            return;
        }

        $this->saved[] = [$this->skip, $this->uc, $this->font, $this->inFontTable];
    }

    private function closeGroup(): void
    {
        $this->fallbackToSkip = 0;

        if ($this->overflow > 0) {
            $this->overflow--;

            if ($this->overflowSkipFrom !== null && $this->overflow < $this->overflowSkipFrom) {
                $this->overflowSkipFrom = null;
            }

            return;
        }

        $state = array_pop($this->saved);

        // A closing brace without a matching opening one is ignored.
        if ($state === null) {
            return;
        }

        [$this->skip, $this->uc, $this->font, $this->inFontTable] = $state;

        if (! $this->inFontTable) {
            $this->definingFont = null;
        }
    }

    private function controlSequence(): void
    {
        if ($this->pos >= $this->length) {
            return;
        }

        if (preg_match('/\G([a-zA-Z]{1,32})(-?\d{1,10})? ?/', $this->data, $match, 0, $this->pos) === 1) {
            $this->pos += strlen($match[0]);
            $this->controlWord($match[1], isset($match[2]) ? (int) $match[2] : null);

            return;
        }

        $symbol = $this->data[$this->pos];
        $this->pos++;

        if ($symbol === "'") {
            $digits = strspn($this->data, '0123456789abcdefABCDEF', $this->pos, 2);
            $hex = substr($this->data, $this->pos, $digits);
            $this->pos += $digits;

            // An incomplete escape carries no usable byte; its partial digits are dropped with it.
            if ($digits !== 2) {
                return;
            }

            if (! $this->consumesFallback() && ! $this->isSkipping()) {
                $this->appendBytes(chr(((int) hexdec($hex)) & 0xFF));
            }

            return;
        }

        if ($this->consumesFallback()) {
            return;
        }

        match ($symbol) {
            '*' => $this->markSkip(),
            '\\', '{', '}' => $this->text($symbol),
            '~' => $this->emitUnlessSkipping("\u{00A0}"),
            '_' => $this->emitUnlessSkipping("\u{2011}"),
            "\n", "\r" => $this->emitUnlessSkipping("\n"),
            default => null,
        };
    }

    private function controlWord(string $word, ?int $param): void
    {
        if ($word === 'bin') {
            // Raw binary data may contain braces and backslashes, so it must be stepped over blindly.
            $this->pos = min($this->length, $this->pos + max(0, $param ?? 0));

            return;
        }

        if ($this->consumesFallback()) {
            return;
        }

        $word = strtolower($word);

        if ($word === 'u' && $param !== null) {
            if (! $this->isSkipping()) {
                $this->unicode($param < 0 ? $param + 65536 : $param);
            }

            $this->fallbackToSkip = $this->uc;

            return;
        }

        if (isset(self::SYMBOL_WORDS[$word])) {
            $this->emitUnlessSkipping(self::SYMBOL_WORDS[$word]);

            return;
        }

        if ($word === 'fonttbl') {
            $this->markSkip();
            $this->inFontTable = $this->overflow === 0;

            return;
        }

        if (isset(self::SKIPPED_DESTINATIONS[$word])) {
            $this->markSkip();

            return;
        }

        match ($word) {
            'uc' => $this->uc = max(0, $param ?? 1),
            'ansicpg' => $this->documentCodepage = $param,
            'deff' => $this->defaultFont = $param,
            'f' => $this->selectFont($param),
            'fcharset' => $this->defineFontCodepage($param === null ? null : (self::CHARSET_CODEPAGES[$param] ?? null)),
            'cpg' => $this->defineFontCodepage($param),
            default => null,
        };
    }

    private function selectFont(?int $font): void
    {
        if ($this->inFontTable) {
            $this->definingFont = $font;
        } else {
            $this->font = $font;
        }
    }

    private function defineFontCodepage(?int $codepage): void
    {
        if ($this->inFontTable && $this->definingFont !== null && $codepage !== null && $codepage > 0) {
            $this->fontCodepages[$this->definingFont] = $codepage;
        }
    }

    private function text(string $run): void
    {
        if ($this->fallbackToSkip > 0) {
            $drop = min($this->fallbackToSkip, strlen($run));
            $this->fallbackToSkip -= $drop;
            $run = substr($run, $drop);
        }

        if ($run !== '' && ! $this->isSkipping()) {
            $this->appendBytes($run);
        }
    }

    private function unicode(int $code): void
    {
        if ($code >= 0xD800 && $code <= 0xDBFF) {
            $this->resolveLoneSurrogate();
            $this->highSurrogate = $code;

            return;
        }

        if ($code >= 0xDC00 && $code <= 0xDFFF) {
            $high = $this->highSurrogate;
            $this->highSurrogate = null;
            $this->emit($high === null ? self::REPLACEMENT : $this->chr(0x10000 + (($high - 0xD800) << 10) + ($code - 0xDC00)));

            return;
        }

        $this->emit($this->chr($code));
    }

    private function chr(int $code): string
    {
        if ($code < 0 || $code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) {
            return self::REPLACEMENT;
        }

        return mb_chr($code, 'UTF-8');
    }

    private function consumesFallback(): bool
    {
        if ($this->fallbackToSkip > 0) {
            $this->fallbackToSkip--;

            return true;
        }

        return false;
    }

    private function isSkipping(): bool
    {
        return $this->skip || $this->overflowSkipFrom !== null;
    }

    private function markSkip(): void
    {
        if ($this->overflow > 0) {
            $this->overflowSkipFrom ??= $this->overflow;
        } else {
            $this->skip = true;
        }
    }

    private function emitUnlessSkipping(string $utf8): void
    {
        if (! $this->isSkipping()) {
            $this->emit($utf8);
        }
    }

    private function emit(string $utf8): void
    {
        $this->resolveLoneSurrogate();
        $this->flush();
        $this->write($utf8);
    }

    /**
     * Queues code page bytes so that a multi-byte character split across several \'hh escapes is
     * decoded as one unit.
     */
    private function appendBytes(string $bytes): void
    {
        $this->resolveLoneSurrogate();
        $encoding = $this->currentEncoding();

        if ($this->pendingBytes !== '') {
            // Lead bytes of every double-byte code page are >= 0x80, so cutting after a lower byte
            // never separates a character.
            $atBoundary = ord($this->pendingBytes[strlen($this->pendingBytes) - 1]) < 0x80;

            if ($encoding !== $this->pendingEncoding || ($atBoundary && strlen($this->pendingBytes) >= self::FLUSH_AT)) {
                $this->flush();
            }
        }

        $this->pendingEncoding = $encoding;
        $this->pendingBytes .= $bytes;
    }

    private function flush(): void
    {
        if ($this->pendingBytes === '') {
            return;
        }

        $bytes = $this->pendingBytes;
        $this->pendingBytes = '';
        $this->write(preg_match('/[\x80-\xFF]/', $bytes) === 1 ? Encoding::toUtf8($bytes, $this->pendingEncoding) : $bytes);
    }

    private function write(string $utf8): void
    {
        if (! $this->full && ! $this->buffer->append($utf8)) {
            $this->full = true;
        }
    }

    private function resolveLoneSurrogate(): void
    {
        if ($this->highSurrogate !== null) {
            $this->highSurrogate = null;
            $this->flush();
            $this->write(self::REPLACEMENT);
        }
    }

    private function currentEncoding(): string
    {
        $font = $this->font ?? $this->defaultFont;
        $codepage = $font !== null && isset($this->fontCodepages[$font]) ? $this->fontCodepages[$font] : $this->documentCodepage;

        return ($codepage === null ? null : $this->encodingFor($codepage)) ?? $this->fallbackEncoding;
    }

    private function encodingFor(int $codepage): ?string
    {
        if (! array_key_exists($codepage, $this->encodingCache)) {
            $name = match (true) {
                $codepage === 65001 => 'UTF-8',
                $codepage === 949 => 'UHC',
                $codepage >= 1250 && $codepage <= 1258 => 'Windows-'.$codepage,
                default => 'CP'.$codepage,
            };

            $this->encodingCache[$codepage] = Encoding::isSupported($name) ? $name : null;
        }

        return $this->encodingCache[$codepage];
    }
}
