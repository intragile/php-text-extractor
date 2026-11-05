<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Extractors\Ooxml;

use IntrAgile\TextExtractor\Archive\SafeZip;
use IntrAgile\TextExtractor\Contracts\Extractor;
use IntrAgile\TextExtractor\Exception\CorruptInput;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Output\TextBuffer;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Result;
use IntrAgile\TextExtractor\Xml\SafeXmlReader;
use XMLReader;

/**
 * Streams the cell values of an Excel workbook (SpreadsheetML), one sheet after the other.
 *
 * Each sheet is introduced by a "## <name>" line; a row becomes its non-empty cell values joined by
 * tabs. Cell positions are deliberately not reconstructed, so sparse or padded sheets (millions of
 * empty rows or cells) produce no output instead of runs of empty separators. Values are emitted as
 * stored: number formats and styles are not applied, so dates appear as serial numbers.
 */
final class XlsxExtractor implements Extractor
{
    private const RELATIONSHIP_NAMESPACES = [
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'http://purl.oclc.org/ooxml/officeDocument/relationships',
    ];

    private const TEXT_NODES = [
        XMLReader::TEXT,
        XMLReader::CDATA,
        XMLReader::WHITESPACE,
        XMLReader::SIGNIFICANT_WHITESPACE,
    ];

    public function supports(Format $format): bool
    {
        return $format === Format::Xlsx;
    }

    public function extract(string $path, Format $format, Options $options): Result
    {
        foreach (['zip', 'xmlreader'] as $extension) {
            if (! extension_loaded($extension)) {
                return Result::failed(Reason::MissingExtension, $format, sprintf('ext-%s is required to read XLSX files.', $extension));
            }
        }

        $zip = SafeZip::open($path, $options);

        try {
            $workbook = $this->locateWorkbook($zip);
            $workbookRels = self::relsPathFor($workbook);
            $targets = Relationships::parse($zip, $workbookRels);
            $sheets = $this->readSheetList($zip, $workbook, $targets);

            $sharedStringsPath = $this->targetOfType($zip, $workbookRels, $targets, '/sharedStrings') ?? self::sibling($workbook, 'sharedStrings.xml');
            $truncated = false;
            $sharedStrings = $zip->has($sharedStringsPath)
                ? $this->readSharedStrings($zip, $sharedStringsPath, $options->maxOutputBytes, $truncated)
                : [];

            $buffer = new TextBuffer($options->maxOutputBytes);
            $first = true;

            foreach ($sheets as [$name, $part]) {
                if (! $zip->has($part)) {
                    continue;
                }

                $header = ($first ? '' : "\n").'## '.$name."\n";

                if (! $this->readSheet($zip, $part, $sharedStrings, $buffer, $header)) {
                    break;
                }

                if ($header === '') {
                    $first = false;
                }
            }
        } finally {
            $zip->close();
        }

        return Result::extracted($buffer->toString(), $format, $truncated || $buffer->isTruncated());
    }

    private function locateWorkbook(SafeZip $zip): string
    {
        $workbook = $this->targetOfType($zip, '_rels/.rels', Relationships::parse($zip, '_rels/.rels'), '/officeDocument');

        if ($workbook !== null && $zip->has($workbook)) {
            return $workbook;
        }

        if ($zip->has('xl/workbook.xml')) {
            return 'xl/workbook.xml';
        }

        throw new CorruptInput('The workbook part is missing.');
    }

    /**
     * @param  array<string, string>  $targets  relationship id => part path
     * @return list<array{string, string}> sheet name and part path, in workbook order
     */
    private function readSheetList(SafeZip $zip, string $workbook, array $targets): array
    {
        $reader = $zip->xmlReader($workbook);
        $sheets = [];

        try {
            while ($reader->read()) {
                if ($reader->nodeType() !== XMLReader::ELEMENT || $reader->localName() !== 'sheet') {
                    continue;
                }

                $id = null;

                foreach (self::RELATIONSHIP_NAMESPACES as $namespace) {
                    $id ??= $reader->attribute('id', $namespace);
                }

                if ($id !== null && isset($targets[$id])) {
                    $name = str_replace(["\r\n", "\r", "\n"], ' ', (string) $reader->attribute('name'));
                    $sheets[] = [$name, $targets[$id]];
                }
            }
        } finally {
            $reader->close();
        }

        return $sheets;
    }

    /**
     * Stores string items until their cumulative size would exceed $limit, then stops reading;
     * cells referencing an index past the end of the list resolve to ''.
     *
     * @return list<string>
     */
    private function readSharedStrings(SafeZip $zip, string $part, int $limit, bool &$truncated): array
    {
        $reader = $zip->xmlReader($part);
        $strings = [];
        $used = 0;

        try {
            while ($reader->read()) {
                if ($reader->nodeType() !== XMLReader::ELEMENT || $reader->localName() !== 'si') {
                    continue;
                }

                $text = $reader->isEmptyElement() ? '' : $this->readStringItem($reader, $limit - $used);

                if ($used + strlen($text) > $limit) {
                    $truncated = true;

                    break;
                }

                $used += strlen($text);
                $strings[] = $text;
            }
        } finally {
            $reader->close();
        }

        return $strings;
    }

    /**
     * Streams a worksheet into the buffer. The header is written before the first non-empty row
     * and is cleared once used, so the caller can tell whether the sheet produced any output.
     *
     * @param  list<string>  $sharedStrings
     * @return bool false once the buffer is full
     */
    private function readSheet(SafeZip $zip, string $part, array $sharedStrings, TextBuffer $buffer, string &$header): bool
    {
        $reader = $zip->xmlReader($part);
        $cells = [];

        try {
            while ($reader->read()) {
                $type = $reader->nodeType();

                if ($type === XMLReader::ELEMENT && $reader->localName() === 'c') {
                    $value = $reader->isEmptyElement() ? '' : $this->readCell($reader, $sharedStrings);

                    if ($value !== '') {
                        $cells[] = $value;
                    }

                    continue;
                }

                if ($type === XMLReader::END_ELEMENT && $reader->localName() === 'row' && $cells !== []) {
                    $line = $header.implode("\t", $cells)."\n";
                    $header = '';
                    $cells = [];

                    if (! $buffer->append($line)) {
                        return false;
                    }
                }
            }
        } finally {
            $reader->close();
        }

        return true;
    }

    /**
     * Expects the reader on a non-empty <c> element and leaves it on the matching end tag.
     *
     * @param  list<string>  $sharedStrings
     */
    private function readCell(SafeXmlReader $reader, array $sharedStrings): string
    {
        $type = $reader->attribute('t');
        $depth = $reader->depth();
        $value = null;
        $inline = null;
        $moved = $reader->read();

        while ($moved) {
            $nodeType = $reader->nodeType();

            if ($nodeType === XMLReader::END_ELEMENT && $reader->depth() === $depth) {
                break;
            }

            if ($nodeType === XMLReader::ELEMENT) {
                $name = $reader->localName();

                if ($name === 'v') {
                    $value = $reader->isEmptyElement() ? '' : $this->readElementText($reader);
                } elseif ($name === 'is') {
                    $inline = $reader->isEmptyElement() ? '' : $this->readStringItem($reader, PHP_INT_MAX);
                } else {
                    // Formulas and extension data are never part of the displayed value.
                    $moved = $reader->next();

                    continue;
                }
            }

            $moved = $reader->read();
        }

        switch ($type) {
            case 's':
                $index = trim((string) $value);

                return ctype_digit($index) ? ($sharedStrings[(int) $index] ?? '') : '';
            case 'inlineStr':
                return $inline ?? (string) $value;
            case 'b':
                return match (trim((string) $value)) {
                    '1' => 'TRUE',
                    '0' => 'FALSE',
                    default => (string) $value,
                };
            default:
                return (string) $value;
        }
    }

    /**
     * Concatenates the <t> runs of a rich-text item (<si> or <is>), leaving out phonetic (<rPh>)
     * guides. Expects the reader on a non-empty start tag and leaves it on the matching end tag.
     * Text past $limit bytes is consumed but not kept.
     */
    private function readStringItem(SafeXmlReader $reader, int $limit): string
    {
        $depth = $reader->depth();
        $textDepth = null;
        $text = '';
        $moved = $reader->read();

        while ($moved) {
            $nodeType = $reader->nodeType();

            if ($nodeType === XMLReader::END_ELEMENT && $reader->depth() === $depth) {
                break;
            }

            if ($nodeType === XMLReader::ELEMENT) {
                if ($reader->localName() === 'rPh') {
                    $moved = $reader->next();

                    continue;
                }

                if ($reader->localName() === 't' && ! $reader->isEmptyElement()) {
                    $textDepth = $reader->depth();
                }
            } elseif ($nodeType === XMLReader::END_ELEMENT && $reader->depth() === $textDepth) {
                $textDepth = null;
            } elseif ($textDepth !== null && in_array($nodeType, self::TEXT_NODES, true) && strlen($text) <= $limit) {
                $text .= $reader->value();
            }

            $moved = $reader->read();
        }

        return $text;
    }

    /**
     * Expects the reader on a non-empty start tag and leaves it on the matching end tag.
     */
    private function readElementText(SafeXmlReader $reader): string
    {
        $depth = $reader->depth();
        $text = '';

        while ($reader->read()) {
            $nodeType = $reader->nodeType();

            if ($nodeType === XMLReader::END_ELEMENT && $reader->depth() === $depth) {
                break;
            }

            if (in_array($nodeType, self::TEXT_NODES, true)) {
                $text .= $reader->value();
            }
        }

        return $text;
    }

    /**
     * The relationships API resolves targets but drops the Type attribute, so the types are read
     * separately and matched up by relationship id.
     *
     * @param  array<string, string>  $targets  relationship id => part path, as parsed from $relsPath
     */
    private function targetOfType(SafeZip $zip, string $relsPath, array $targets, string $typeSuffix): ?string
    {
        if ($targets === [] || ! $zip->has($relsPath)) {
            return null;
        }

        $reader = $zip->xmlReader($relsPath);

        try {
            while ($reader->read()) {
                if ($reader->nodeType() !== XMLReader::ELEMENT || $reader->localName() !== 'Relationship') {
                    continue;
                }

                $id = $reader->attribute('Id');

                if ($id !== null && isset($targets[$id]) && str_ends_with((string) $reader->attribute('Type'), $typeSuffix)) {
                    return $targets[$id];
                }
            }
        } finally {
            $reader->close();
        }

        return null;
    }

    private static function relsPathFor(string $part): string
    {
        $slash = strrpos($part, '/');

        return $slash === false
            ? '_rels/'.$part.'.rels'
            : substr($part, 0, $slash).'/_rels/'.substr($part, $slash + 1).'.rels';
    }

    private static function sibling(string $part, string $name): string
    {
        $slash = strrpos($part, '/');

        return $slash === false ? $name : substr($part, 0, $slash + 1).$name;
    }
}
