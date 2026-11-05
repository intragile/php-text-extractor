<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Extractors\Ooxml;

use IntrAgile\TextExtractor\Archive\SafeZip;
use IntrAgile\TextExtractor\Exception\CorruptInput;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Output\LayoutWriter;
use IntrAgile\TextExtractor\Output\TextBuffer;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Result;
use IntrAgile\TextExtractor\Support\Deadline;
use IntrAgile\TextExtractor\Support\DeadlineAwareExtractor;
use IntrAgile\TextExtractor\Xml\SafeXmlReader;
use XMLReader;

/**
 * Streams the text of a PowerPoint presentation slide by slide.
 *
 * Slides are read in the order the presentation lists them, which is not necessarily the order of
 * the slideN.xml file names. Speaker notes are left out. Tables are written one line per row with
 * tab-separated cells.
 */
final class PptxExtractor implements DeadlineAwareExtractor
{
    private const PRESENTATIONML = [
        'http://schemas.openxmlformats.org/presentationml/2006/main' => true,
        'http://purl.oclc.org/ooxml/presentationml/main' => true,
    ];

    private const DRAWINGML = [
        'http://schemas.openxmlformats.org/drawingml/2006/main' => true,
        'http://purl.oclc.org/ooxml/drawingml/main' => true,
    ];

    private const RELATIONSHIPS = [
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'http://purl.oclc.org/ooxml/officeDocument/relationships',
    ];

    private const MARKUP_COMPATIBILITY = 'http://schemas.openxmlformats.org/markup-compatibility/2006';

    public function supports(Format $format): bool
    {
        return $format === Format::Pptx;
    }

    public function extract(string $path, Format $format, Options $options): Result
    {
        return $this->extractWithin($path, $format, $options, Deadline::fromOptions($options));
    }

    public function extractWithin(string $path, Format $format, Options $options, Deadline $deadline): Result
    {
        if (! extension_loaded('zip') || ! extension_loaded('xmlreader')) {
            return Result::failed(Reason::MissingExtension, $format, 'ext-zip and ext-xmlreader are required to read PPTX files.');
        }

        $zip = SafeZip::open($path, $options, $deadline);
        $buffer = new TextBuffer($options->maxOutputBytes);

        try {
            $presentation = PartLocator::mainPart($zip, 'ppt/presentation.xml');

            if ($presentation === null) {
                throw new CorruptInput('The presentation has no main part.');
            }

            $first = true;

            foreach ($this->slides($zip, $presentation) as $slide) {
                if (! $first && ! $buffer->append("\n")) {
                    break;
                }

                $first = false;

                if (! $this->readSlide($zip->xmlReader($slide), new LayoutWriter($buffer))) {
                    break;
                }
            }
        } finally {
            $zip->close();
        }

        return Result::extracted($buffer->toString(), $format, $buffer->isTruncated());
    }

    /**
     * @return list<string> slide parts in presentation order; ids pointing to missing parts are dropped
     */
    private function slides(SafeZip $zip, string $presentation): array
    {
        $ids = [];
        $reader = $zip->xmlReader($presentation);

        try {
            while ($reader->read()) {
                if ($reader->nodeType() !== XMLReader::ELEMENT
                    || $reader->localName() !== 'sldId'
                    || ! isset(self::PRESENTATIONML[$reader->namespaceUri()])) {
                    continue;
                }

                foreach (self::RELATIONSHIPS as $namespace) {
                    $id = $reader->attribute('id', $namespace);

                    if ($id !== null) {
                        $ids[] = $id;

                        break;
                    }
                }
            }
        } finally {
            $reader->close();
        }

        $targets = Relationships::parse($zip, PartLocator::relationshipsPath($presentation));
        $slides = [];

        foreach ($ids as $id) {
            if (isset($targets[$id]) && $zip->has($targets[$id])) {
                $slides[] = $targets[$id];
            }
        }

        return $slides;
    }

    /**
     * @return bool false once the buffer is full
     */
    private function readSlide(SafeXmlReader $reader, LayoutWriter $writer): bool
    {
        try {
            $inText = false;
            $moved = $reader->read();

            while ($moved) {
                $type = $reader->nodeType();
                $written = true;

                if ($type === XMLReader::ELEMENT) {
                    // Alternate content repeats the same shapes for older consumers; mc:Choice is kept.
                    if ($reader->namespaceUri() === self::MARKUP_COMPATIBILITY && $reader->localName() === 'Fallback') {
                        $moved = $reader->next();

                        continue;
                    }

                    if (isset(self::DRAWINGML[$reader->namespaceUri()])) {
                        $name = $reader->localName();

                        if ($name === 't') {
                            $inText = ! $reader->isEmptyElement();
                        } else {
                            $written = match ($name) {
                                'br' => $writer->lineBreak(),
                                'tbl' => $writer->tableStart(),
                                'tr' => $writer->rowStart(),
                                'tc' => $writer->cellStart(),
                                default => true,
                            };

                            // An empty element such as <a:p/> produces no end tag but still ends.
                            if ($written && $reader->isEmptyElement()) {
                                $written = $this->end($name, $writer);
                            }
                        }
                    }
                } elseif ($type === XMLReader::END_ELEMENT) {
                    if (isset(self::DRAWINGML[$reader->namespaceUri()])) {
                        $name = $reader->localName();

                        if ($name === 't') {
                            $inText = false;
                        } else {
                            $written = $this->end($name, $writer);
                        }
                    }
                } elseif ($inText && in_array($type, [XMLReader::TEXT, XMLReader::CDATA, XMLReader::WHITESPACE, XMLReader::SIGNIFICANT_WHITESPACE], true)) {
                    $written = $writer->text($reader->value());
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
