<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Extractors;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Output\Encoding;
use IntrAgile\TextExtractor\Output\LayoutWriter;
use IntrAgile\TextExtractor\Output\TextBuffer;
use IntrAgile\TextExtractor\Output\TextNormalizer;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Result;
use IntrAgile\TextExtractor\Support\Deadline;
use IntrAgile\TextExtractor\Support\DeadlineAwareExtractor;

/**
 * Renders an HTML document as plain text with paragraph and table structure preserved.
 *
 * Block elements start and end a line; tables follow the common layout of one line per row with
 * tab-separated cells.
 *
 * The document is decoded to UTF-8 up front and libxml is told so through a leading XML
 * declaration; otherwise it would assume ISO-8859-1 and mangle every multibyte character.
 */
final class HtmlExtractor implements DeadlineAwareExtractor
{
    private const SNIFF_BYTES = 4096;

    private const FLAGS = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD;

    /** The document title is written separately, so a title inside the body (e.g. SVG) is skipped. */
    private const SKIPPED = ['head', 'script', 'style', 'noscript', 'template', 'title'];

    private const BLOCKS = [
        'p', 'div', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'section', 'article',
        'header', 'footer', 'ul', 'ol', 'blockquote', 'pre', 'caption',
    ];

    private const CELLS = ['td', 'th'];

    /**
     * Charset labels the HTML standard tells a parser to read as UTF-8 when they come from a meta
     * tag: a document that could be parsed far enough to find the tag is not really UTF-16. UTF-32
     * is not a valid HTML encoding at all, so its declaration is ignored the same way.
     */
    private const META_UTF8_LABELS = '/^(utf-?16(le|be)?|utf-?32(le|be)?|unicode|unicodefeff|unicodefffe|ucs-?2|csunicode|iso-10646-ucs-2)$/i';

    public function supports(Format $format): bool
    {
        return $format === Format::Html;
    }

    public function extract(string $path, Format $format, Options $options): Result
    {
        return $this->extractWithin($path, $format, $options, Deadline::fromOptions($options));
    }

    public function extractWithin(string $path, Format $format, Options $options, Deadline $deadline): Result
    {
        if (! extension_loaded('dom')) {
            return Result::failed(Reason::MissingExtension, $format, 'ext-dom is required to extract text from HTML.');
        }

        // libxml builds a DOM many times the size of the markup in native memory, outside PHP's
        // memory_limit, so the size is checked before anything is read.
        $size = @filesize($path);

        if (is_int($size) && $size > $options->maxHtmlBytes) {
            return $this->tooLarge($size, $format, $options);
        }

        // One byte over the limit is enough to notice a file that grew after the size check. The
        // length is capped at the known size because PHP before 8.3 allocates the whole maximum
        // length up front.
        $limit = is_int($size) ? min($size, $options->maxHtmlBytes) : $options->maxHtmlBytes;
        $bytes = $this->read($path, $limit);

        if (is_string($bytes) && strlen($bytes) > $limit && $limit < $options->maxHtmlBytes) {
            $bytes = $this->read($path, $options->maxHtmlBytes);
        }

        if ($bytes === false) {
            return Result::failed(Reason::Unreadable, $format, 'The file cannot be read.');
        }

        if (strlen($bytes) > $options->maxHtmlBytes) {
            return $this->tooLarge(strlen($bytes), $format, $options);
        }

        // Each step drops its input so that at most two copies of the document are alive at once.
        $html = $this->decode($bytes, $options->fallbackEncoding);
        unset($bytes);

        if ($this->hasForeignCharsetDeclaration($html)) {
            $html = $this->relabelAsUtf8($html);
        }

        $document = $this->load($html);
        unset($html);

        $buffer = new TextBuffer($options->maxOutputBytes);
        $writer = new LayoutWriter($buffer);
        $title = $this->documentTitle($document);

        if ($title !== null && $this->text($writer, $title, false)) {
            $writer->blockEnd();
        }

        if ($document->documentElement !== null) {
            $this->walk($document->documentElement, false, $writer, $deadline);
        }

        return Result::extracted($buffer->toString(), $format, $buffer->isTruncated());
    }

    /**
     * Reads at most one byte more than $limit.
     */
    private function read(string $path, int $limit): string|false
    {
        return @file_get_contents($path, false, null, 0, max(1, min($limit, PHP_INT_MAX - 1) + 1));
    }

    private function tooLarge(int $size, Format $format, Options $options): Result
    {
        return Result::notExtractable(Reason::TooLarge, $format, sprintf('The HTML document is %d bytes; the limit is %d.', $size, $options->maxHtmlBytes));
    }

    private function decode(string $bytes, string $fallbackEncoding): string
    {
        $hasBom = str_starts_with($bytes, "\xEF\xBB\xBF") || str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF");
        $declared = $hasBom ? null : $this->declaredCharset(substr($bytes, 0, self::SNIFF_BYTES));

        if ($declared !== null
            && ! in_array(strtolower($declared), ['utf-8', 'utf8'], true)
            && preg_match(self::META_UTF8_LABELS, $declared) !== 1
            && Encoding::isSupported($declared)) {
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
     * Whether any meta tag names a charset other than UTF-8; most documents need no rewriting.
     */
    private function hasForeignCharsetDeclaration(string $html): bool
    {
        return preg_match('/<meta\b[^>]*?\bcharset\s*=\s*["\']?\s*(?!utf-?8(?![A-Za-z0-9._:\-]))[A-Za-z0-9._:\-]/i', $html) === 1;
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

    /**
     * Parses without collecting libxml errors: with internal errors enabled PHP keeps one object
     * per parse error, which for broken markup costs many times the document size. The parser
     * flags already silence error reporting; the handler only guarantees no warning escapes.
     */
    private function load(string $html): DOMDocument
    {
        $previous = libxml_use_internal_errors(false);
        set_error_handler(static fn (): bool => true);

        try {
            $document = new DOMDocument;
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, self::FLAGS);
        } finally {
            restore_error_handler();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    /**
     * The title of the document head; titles elsewhere, such as inside inline SVG, are not it.
     */
    private function documentTitle(DOMDocument $document): ?string
    {
        foreach ($document->documentElement->childNodes ?? [] as $head) {
            if (! $head instanceof DOMElement || strtolower($head->localName ?? '') !== 'head') {
                continue;
            }

            foreach ($head->childNodes as $child) {
                if ($child instanceof DOMElement && strtolower($child->localName ?? '') === 'title') {
                    return $child->textContent;
                }
            }
        }

        return null;
    }

    /**
     * @return bool false once the output buffer is full
     */
    private function walk(DOMNode $node, bool $preformatted, LayoutWriter $writer, Deadline $deadline): bool
    {
        foreach ($node->childNodes as $child) {
            $deadline->tick();

            if ($child instanceof DOMText) {
                if (! $this->text($writer, $child->data, $preformatted)) {
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
                if (! $writer->lineBreak()) {
                    return false;
                }

                continue;
            }

            if (! $this->open($name, $writer)
                || ! $this->walk($child, $preformatted || $name === 'pre', $writer, $deadline)
                || ! $this->close($name, $writer)) {
                return false;
            }
        }

        return true;
    }

    private function open(string $name, LayoutWriter $writer): bool
    {
        return match (true) {
            $name === 'table' => $writer->tableStart(),
            $name === 'tr' => $writer->rowStart(),
            in_array($name, self::CELLS, true) => $writer->cellStart(),
            in_array($name, self::BLOCKS, true) => $writer->blockEnd(),
            default => true,
        };
    }

    private function close(string $name, LayoutWriter $writer): bool
    {
        return match (true) {
            $name === 'table' => $writer->tableEnd(),
            $name === 'tr' => $writer->rowEnd(),
            in_array($name, self::CELLS, true) => $writer->cellEnd(),
            in_array($name, self::BLOCKS, true) => $writer->blockEnd(),
            default => true,
        };
    }

    private function text(LayoutWriter $writer, string $text, bool $preformatted): bool
    {
        if (! $preformatted) {
            $text = (string) preg_replace('/[ \t\n\r\f]+/', ' ', $text);

            if ($writer->atLineStart()) {
                $text = ltrim($text, ' ');
            }
        }

        return $writer->text($text);
    }
}
