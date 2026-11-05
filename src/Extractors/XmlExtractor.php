<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Extractors;

use IntrAgile\TextExtractor\Contracts\Extractor;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Output\TextBuffer;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Result;
use IntrAgile\TextExtractor\Xml\SafeXmlReader;
use XMLReader;

/**
 * Collects the character data of a generic XML document.
 *
 * Arbitrary XML carries no layout semantics, so text and CDATA nodes are simply joined with a
 * single space. Parsing goes through SafeXmlReader, which refuses any DOCTYPE and so prevents
 * external-entity disclosure.
 */
final class XmlExtractor implements Extractor
{
    public function supports(Format $format): bool
    {
        return $format === Format::Xml;
    }

    public function extract(string $path, Format $format, Options $options): Result
    {
        if (! extension_loaded('xmlreader')) {
            return Result::failed(Reason::MissingExtension, $format, 'ext-xmlreader is required to extract text from XML.');
        }

        $reader = SafeXmlReader::fromUri($path);
        $buffer = new TextBuffer($options->maxOutputBytes);
        $separator = '';

        try {
            while ($reader->read()) {
                $type = $reader->nodeType();

                if ($type !== XMLReader::TEXT && $type !== XMLReader::CDATA) {
                    continue;
                }

                $value = trim($reader->value());

                if ($value === '') {
                    continue;
                }

                if (! $buffer->append($separator.$value)) {
                    break;
                }

                $separator = ' ';
            }
        } finally {
            $reader->close();
        }

        return Result::extracted($buffer->toString(), $format, $buffer->isTruncated());
    }
}
