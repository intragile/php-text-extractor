<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Extractors\Ooxml;

use IntrAgile\TextExtractor\Archive\SafeZip;
use IntrAgile\TextExtractor\Contracts\Extractor;
use IntrAgile\TextExtractor\Exception\CorruptInput;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Output\LayoutWriter;
use IntrAgile\TextExtractor\Output\TextBuffer;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Result;
use IntrAgile\TextExtractor\Xml\SafeXmlReader;
use XMLReader;

/**
 * Streams the text of a Word document: body first, then footnotes and endnotes.
 *
 * Headers and footers are left out because they repeat on every page and add noise rather than
 * content. Deleted tracked changes, moved-from text and field instructions are skipped so the
 * output reflects what a reader of the final document sees. Tables are written one line per row
 * with tab-separated cells.
 */
final class DocxExtractor implements Extractor
{
    private const WORDML = [
        'http://schemas.openxmlformats.org/wordprocessingml/2006/main' => true,
        'http://purl.oclc.org/ooxml/wordprocessingml/main' => true,
    ];

    private const MARKUP_COMPATIBILITY = 'http://schemas.openxmlformats.org/markup-compatibility/2006';

    /** Subtrees that hold no visible text, or text that would otherwise appear twice. */
    private const SKIPPED = [
        'delText' => true,
        'instrText' => true,
        'delInstrText' => true,
        'moveFrom' => true,
        'pPr' => true,
        'rPr' => true,
        'sectPr' => true,
    ];

    /** Word's built-in notes that only draw the separator line above the notes area. */
    private const SEPARATOR_NOTES = [
        'separator' => true,
        'continuationSeparator' => true,
        'continuationNotice' => true,
    ];

    public function supports(Format $format): bool
    {
        return $format === Format::Docx;
    }

    public function extract(string $path, Format $format, Options $options): Result
    {
        if (! extension_loaded('zip') || ! extension_loaded('xmlreader')) {
            return Result::failed(Reason::MissingExtension, $format, 'ext-zip and ext-xmlreader are required to read DOCX files.');
        }

        $zip = SafeZip::open($path, $options);
        $buffer = new TextBuffer($options->maxOutputBytes);

        try {
            $main = PartLocator::mainPart($zip, 'word/document.xml');

            if ($main === null) {
                throw new CorruptInput('The document has no main part.');
            }

            $parts = array_merge(
                [$main],
                PartLocator::byType($zip, $main, 'footnotes'),
                PartLocator::byType($zip, $main, 'endnotes'),
            );

            foreach ($parts as $index => $part) {
                if ($index > 0 && ! $buffer->append("\n")) {
                    break;
                }

                if (! $this->readPart($zip->xmlReader($part), new LayoutWriter($buffer))) {
                    break;
                }
            }
        } finally {
            $zip->close();
        }

        return Result::extracted($buffer->toString(), $format, $buffer->isTruncated());
    }

    /**
     * @return bool false once the buffer is full
     */
    private function readPart(SafeXmlReader $reader, LayoutWriter $writer): bool
    {
        try {
            $inText = false;
            $moved = $reader->read();

            while ($moved) {
                $type = $reader->nodeType();

                if ($type === XMLReader::ELEMENT) {
                    if ($this->isSkipped($reader)) {
                        $moved = $reader->next();

                        continue;
                    }

                    $written = $this->startElement($reader, $writer, $inText);
                } elseif ($type === XMLReader::END_ELEMENT) {
                    $written = $this->endElement($reader, $writer, $inText);
                } elseif ($inText && in_array($type, [XMLReader::TEXT, XMLReader::CDATA, XMLReader::WHITESPACE, XMLReader::SIGNIFICANT_WHITESPACE], true)) {
                    $written = $writer->text($reader->value());
                } else {
                    $written = true;
                }

                if (! $written) {
                    return false;
                }

                $moved = $reader->read();
            }

            return true;
        } finally {
            $reader->close();
        }
    }

    private function isSkipped(SafeXmlReader $reader): bool
    {
        $namespace = $reader->namespaceUri();
        $name = $reader->localName();

        if ($namespace === self::MARKUP_COMPATIBILITY) {
            // Alternate content repeats the same text for older consumers; mc:Choice is kept.
            return $name === 'Fallback';
        }

        if (! isset(self::WORDML[$namespace])) {
            return false;
        }

        if (isset(self::SKIPPED[$name])) {
            return true;
        }

        if ($name === 'footnote' || $name === 'endnote') {
            return isset(self::SEPARATOR_NOTES[(string) $reader->attribute('type', $namespace)]);
        }

        return false;
    }

    /**
     * @return bool false once the buffer is full
     */
    private function startElement(SafeXmlReader $reader, LayoutWriter $writer, bool &$inText): bool
    {
        if (! isset(self::WORDML[$reader->namespaceUri()])) {
            return true;
        }

        $name = $reader->localName();

        if ($name === 't') {
            $inText = ! $reader->isEmptyElement();

            return true;
        }

        $written = match ($name) {
            'tab' => $writer->tab(),
            'br', 'cr' => $writer->lineBreak(),
            'noBreakHyphen' => $writer->text('-'),
            'tbl' => $writer->tableStart(),
            'tr' => $writer->rowStart(),
            'tc' => $writer->cellStart(),
            default => true,
        };

        // An empty element such as <w:p/> or <w:tc/> produces no end tag but still ends.
        if ($written && $reader->isEmptyElement()) {
            return $this->end($name, $writer);
        }

        return $written;
    }

    /**
     * @return bool false once the buffer is full
     */
    private function endElement(SafeXmlReader $reader, LayoutWriter $writer, bool &$inText): bool
    {
        if (! isset(self::WORDML[$reader->namespaceUri()])) {
            return true;
        }

        $name = $reader->localName();

        if ($name === 't') {
            $inText = false;

            return true;
        }

        return $this->end($name, $writer);
    }

    private function end(string $name, LayoutWriter $writer): bool
    {
        return match ($name) {
            'p' => $writer->paragraphEnd(),
            'tc' => $writer->cellEnd(),
            'tr' => $writer->rowEnd(),
            'tbl' => $writer->tableEnd(),
            default => true,
        };
    }
}
