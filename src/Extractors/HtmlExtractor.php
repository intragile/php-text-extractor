<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Extractors;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use IntrAgile\TextExtractor\Contracts\Extractor;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Output\Encoding;
use IntrAgile\TextExtractor\Output\TextBuffer;
use IntrAgile\TextExtractor\Output\TextNormalizer;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Result;

/**
 * Renders an HTML document as plain text with paragraph and table structure preserved.
 *
 * The document is decoded to UTF-8 up front and libxml is told so through a leading XML
 * declaration; otherwise it would assume ISO-8859-1 and mangle every multibyte character.
 */
final class HtmlExtractor implements Extractor
{
    private const SNIFF_BYTES = 4096;

    private const FLAGS = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD;

    private const SKIPPED = ['head', 'script', 'style', 'noscript', 'template'];

    private const BLOCKS = [
        'p', 'div', 'li', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'section', 'article',
        'header', 'footer', 'table', 'ul', 'ol', 'blockquote', 'pre',
    ];

    private const CELLS = ['td', 'th'];

    private TextBuffer $buffer;

    /** Whether the most recent output ended a line, so block boundaries do not stack up blank lines. */
    private bool $atLineStart = true;

    public function supports(Format $format): bool
    {
        return $format === Format::Html;
    }

    public function extract(string $path, Format $format, Options $options): Result
    {
        if (! extension_loaded('dom')) {
            return Result::failed(Reason::MissingExtension, $format, 'ext-dom is required to extract text from HTML.');
        }

        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            return Result::failed(Reason::Unreadable, $format, 'The file cannot be read.');
        }

        $document = $this->load($this->relabelAsUtf8($this->decode($bytes, $options->fallbackEncoding)));
        $this->buffer = new TextBuffer($options->maxOutputBytes);
        $this->atLineStart = true;

        $title = $document->getElementsByTagName('title')->item(0);

        if ($title !== null && $this->text($title->textContent, false)) {
            $this->lineBreak();
        }

        if ($document->documentElement !== null) {
            $this->walk($document->documentElement, false);
        }

        return Result::extracted($this->buffer->toString(), $format, $this->buffer->isTruncated());
    }

    private function decode(string $bytes, string $fallbackEncoding): string
    {
        $hasBom = str_starts_with($bytes, "\xEF\xBB\xBF") || str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF");
        $declared = $hasBom ? null : $this->declaredCharset(substr($bytes, 0, self::SNIFF_BYTES));

        if ($declared !== null && ! in_array(strtolower($declared), ['utf-8', 'utf8'], true) && Encoding::isSupported($declared)) {
            return Encoding::toUtf8($bytes, $declared);
        }

        return (new TextNormalizer($fallbackEncoding))->toUtf8($bytes);
    }

    /**
     * Finds the encoding named by <meta charset> or by a Content-Type http-equiv meta tag.
     */
    private function declaredCharset(string $head): ?string
    {
        if (preg_match('/<meta\b[^>]*?\bcharset\s*=\s*["\']?\s*([A-Za-z0-9._:\-]+)/i', $head, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    /**
     * Rewrites meta charset declarations to UTF-8, the encoding the text now actually has.
     *
     * libxml gives a meta charset precedence over the leading XML declaration, so a stale
     * declaration would make it decode the already converted text a second time.
     */
    private function relabelAsUtf8(string $html): string
    {
        return (string) preg_replace_callback(
            '/<meta\b[^>]*>/i',
            static fn (array $meta): string => (string) preg_replace('/(\bcharset\s*=\s*["\']?\s*)[A-Za-z0-9._:\-]+/i', '${1}utf-8', $meta[0]),
            $html,
        );
    }

    private function load(string $html): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, self::FLAGS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    /**
     * @return bool false once the output buffer is full
     */
    private function walk(DOMNode $node, bool $preformatted): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                if (! $this->text($child->data, $preformatted)) {
                    return false;
                }

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $name = strtolower($child->localName ?? '');

            if (in_array($name, self::SKIPPED, true)) {
                continue;
            }

            if ($name === 'br') {
                if (! $this->append("\n")) {
                    return false;
                }

                continue;
            }

            if (! $this->walk($child, $preformatted || $name === 'pre')) {
                return false;
            }

            if (in_array($name, self::CELLS, true)) {
                if (! $this->append("\t")) {
                    return false;
                }
            } elseif (in_array($name, self::BLOCKS, true) && ! $this->lineBreak()) {
                return false;
            }
        }

        return true;
    }

    private function text(string $text, bool $preformatted): bool
    {
        if (! $preformatted) {
            $text = (string) preg_replace('/[ \t\n\r\f]+/', ' ', $text);

            if ($this->atLineStart) {
                $text = ltrim($text, ' ');
            }
        }

        return $this->append($text);
    }

    private function lineBreak(): bool
    {
        return $this->atLineStart || $this->append("\n");
    }

    private function append(string $text): bool
    {
        if ($text === '') {
            return true;
        }

        $this->atLineStart = str_ends_with($text, "\n");

        return $this->buffer->append($text);
    }
}
