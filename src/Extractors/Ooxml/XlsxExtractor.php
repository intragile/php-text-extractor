<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Extractors\Ooxml;

use IntrAgile\TextExtractor\Archive\ArchiveLimitExceeded;
use IntrAgile\TextExtractor\Archive\SafeZip;
use IntrAgile\TextExtractor\Exception\CorruptInput;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Output\TextBuffer;
use IntrAgile\TextExtractor\Reason;
use IntrAgile\TextExtractor\Result;
use IntrAgile\TextExtractor\Support\Deadline;
use IntrAgile\TextExtractor\Support\DeadlineAwareExtractor;
use IntrAgile\TextExtractor\Xml\MalformedXml;
use IntrAgile\TextExtractor\Xml\SafeXmlReader;
use XMLReader;

/**
 * Streams the cell values of an Excel workbook (SpreadsheetML), one sheet after the other.
 *
 * Each sheet is introduced by a "## <name>" line; a row becomes its non-empty cell values joined by
 * tabs, and line breaks inside a cell become spaces so that a row stays on one line. Cell positions
 * are deliberately not reconstructed, so sparse or padded sheets (millions of empty rows or cells)
 * produce no output instead of runs of empty separators. Values are emitted as stored, except that
 * numbers whose cell format is a date or time format are written in ISO 8601 form; other number
 * formats are not applied.
 *
 * Memory is bounded by the output limit: cells are written to the buffer one by one rather than
 * assembled into rows (one long shared string referenced by every cell of a row would otherwise
 * multiply), every value is read only up to the remaining budget, and the shared-string table keeps
 * at most maxOutputBytes of text. The cell-format table costs four bytes per entry and is capped
 * like the shared-string table.
 */
final class XlsxExtractor implements DeadlineAwareExtractor
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

    /**
     * Shared-string items allowed regardless of the output limit; above that, one item per four
     * bytes of output limit. Either way the offset table stays within the output limit (or 4 MiB).
     */
    private const MIN_SHARED_STRING_ITEMS = 1_048_576;

    /**
     * Values are read a few bytes past the remaining budget so that the final cut can land on a
     * UTF-8 character boundary.
     */
    private const UTF8_SLACK = 4;

    /**
     * Workbooks rarely define more than a few hundred custom number formats (Excel allows about
     * 250), so this only stops hostile styles parts.
     */
    private const MAX_CUSTOM_NUMBER_FORMATS = 65_536;

    /** Enough for any shared-string index or other short scalar, even when the budget is spent. */
    private const MIN_VALUE_BYTES = 64;

    public function supports(Format $format): bool
    {
        return $format === Format::Xlsx;
    }

    public function extract(string $path, Format $format, Options $options): Result
    {
        return $this->extractWithin($path, $format, $options, Deadline::fromOptions($options));
    }

    public function extractWithin(string $path, Format $format, Options $options, Deadline $deadline): Result
    {
        foreach (['zip', 'xmlreader'] as $extension) {
            if (! extension_loaded($extension)) {
                return Result::failed(Reason::MissingExtension, $format, sprintf('ext-%s is required to read XLSX files.', $extension));
            }
        }

        $zip = SafeZip::open($path, $options, $deadline);

        try {
            $workbook = PartLocator::mainPart($zip, 'xl/workbook.xml') ?? throw new CorruptInput('The workbook part is missing.');
            $date1904 = false;
            $sheets = $this->readSheetList($zip, $workbook, Relationships::parse($zip, PartLocator::relationshipsPath($workbook)), $date1904);

            $sharedStringsPath = PartLocator::byType($zip, $workbook, 'sharedStrings')[0] ?? self::sibling($workbook, 'sharedStrings.xml');
            $truncated = false;
            $sharedStrings = $zip->has($sharedStringsPath)
                ? $this->readSharedStrings($zip, $sharedStringsPath, $options->maxOutputBytes, $truncated)
                : new SharedStrings;

            $stylesPath = PartLocator::byType($zip, $workbook, 'styles')[0] ?? self::sibling($workbook, 'styles.xml');
            $formats = $zip->has($stylesPath)
                ? $this->readCellFormats($zip, $stylesPath, $options->maxOutputBytes)
                : new CellFormats;

            $buffer = new TextBuffer($options->maxOutputBytes);
            $first = true;

            foreach ($sheets as [$name, $part]) {
                if (! $zip->has($part)) {
                    continue;
                }

                $header = ($first ? '' : "\n").'## '.$name."\n";

                if (! $this->readSheet($zip, $part, $sharedStrings, $formats, $date1904, $buffer, $options->maxOutputBytes, $header)) {
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

    /**
     * @param  array<string, string>  $targets  relationship id => part path
     * @param  bool  $date1904  set to whether the workbook counts dates from 1904 instead of 1900
     * @return list<array{string, string}> sheet name and part path, in workbook order
     */
    private function readSheetList(SafeZip $zip, string $workbook, array $targets, bool &$date1904): array
    {
        $reader = $zip->xmlReader($workbook);
        $sheets = [];

        try {
            while ($reader->read()) {
                if ($reader->nodeType() !== XMLReader::ELEMENT) {
                    continue;
                }

                if ($reader->localName() === 'workbookPr') {
                    $date1904 = in_array(strtolower(trim((string) $reader->attribute('date1904'))), ['1', 'true'], true);

                    continue;
                }

                if ($reader->localName() !== 'sheet') {
                    continue;
                }

                $id = null;

                foreach (self::RELATIONSHIP_NAMESPACES as $namespace) {
                    $id ??= $reader->attribute('id', $namespace);
                }

                if ($id !== null && isset($targets[$id])) {
                    $sheets[] = [self::flattenLineBreaks((string) $reader->attribute('name')), $targets[$id]];
                }
            }
        } finally {
            $reader->close();
        }

        return $sheets;
    }

    /**
     * Keeps string items until their text reaches $limit bytes. The item crossing the limit is cut
     * on a character boundary and reading stops there, so every kept item retains its index and
     * cells referencing a later one resolve to ''.
     *
     * @throws ArchiveLimitExceeded when the table holds more items than its bookkeeping budget allows
     */
    private function readSharedStrings(SafeZip $zip, string $part, int $limit, bool &$truncated): SharedStrings
    {
        $limit = min($limit, SharedStrings::MAX_TEXT_BYTES);
        $maxItems = max(self::MIN_SHARED_STRING_ITEMS, intdiv($limit, 4));
        $strings = new SharedStrings;
        $reader = $zip->xmlReader($part);

        try {
            while ($reader->read()) {
                if ($reader->nodeType() !== XMLReader::ELEMENT || $reader->localName() !== 'si') {
                    continue;
                }

                if ($strings->count() >= $maxItems) {
                    throw new ArchiveLimitExceeded(sprintf('The shared-string table holds more than %d items.', $maxItems));
                }

                $remaining = $limit - $strings->textBytes();
                $text = $reader->isEmptyElement() ? '' : self::flattenLineBreaks($this->readStringItem($reader, $remaining));

                if (strlen($text) > $remaining) {
                    $strings->add(mb_strcut($text, 0, $remaining, 'UTF-8'));
                    $truncated = true;

                    break;
                }

                $strings->add($text);
            }
        } finally {
            $reader->close();
        }

        return $strings;
    }

    /**
     * Reads the number formats and the cell formats (cellXfs) of the styles part. Styles only
     * affect how numbers are shown, so a styles part that cannot be parsed is ignored as a whole
     * and every number is written as stored.
     *
     * @throws ArchiveLimitExceeded when the part holds more formats than the bookkeeping budget allows
     */
    private function readCellFormats(SafeZip $zip, string $part, int $limit): CellFormats
    {
        $maxItems = max(self::MIN_SHARED_STRING_ITEMS, intdiv(min($limit, SharedStrings::MAX_TEXT_BYTES), 4));
        $formats = new CellFormats;
        $reader = $zip->xmlReader($part);
        $cellXfsDepth = null;

        try {
            while ($reader->read()) {
                $type = $reader->nodeType();

                if ($type === XMLReader::END_ELEMENT && $reader->depth() === $cellXfsDepth) {
                    $cellXfsDepth = null;

                    continue;
                }

                if ($type !== XMLReader::ELEMENT) {
                    continue;
                }

                $name = $reader->localName();

                if ($name === 'numFmt') {
                    if ($formats->customCount() >= self::MAX_CUSTOM_NUMBER_FORMATS) {
                        throw new ArchiveLimitExceeded(sprintf('The styles part defines more than %d number formats.', self::MAX_CUSTOM_NUMBER_FORMATS));
                    }

                    $id = self::unsignedInt($reader->attribute('numFmtId'));

                    if ($id !== null) {
                        $formats->addNumberFormat($id, (string) $reader->attribute('formatCode'));
                    }
                } elseif ($name === 'cellXfs') {
                    $cellXfsDepth = $reader->isEmptyElement() ? null : $reader->depth();
                } elseif ($name === 'xf' && $cellXfsDepth !== null && $reader->depth() === $cellXfsDepth + 1) {
                    if ($formats->count() >= $maxItems) {
                        throw new ArchiveLimitExceeded(sprintf('The styles part holds more than %d cell formats.', $maxItems));
                    }

                    // Every xf keeps its position, as cells refer to it by index; a missing or
                    // invalid id means General.
                    $formats->addCellFormat(self::unsignedInt($reader->attribute('numFmtId')) ?? 0);
                }
            }
        } catch (MalformedXml) {
            return new CellFormats;
        } finally {
            $reader->close();
        }

        return $formats;
    }

    /**
     * Streams a worksheet into the buffer cell by cell. The header is written before the first
     * non-empty cell and is cleared once used, so the caller can tell whether the sheet produced
     * any output.
     *
     * @return bool false once the buffer is full
     */
    private function readSheet(SafeZip $zip, string $part, SharedStrings $sharedStrings, CellFormats $formats, bool $date1904, TextBuffer $buffer, int $maxBytes, string &$header): bool
    {
        $reader = $zip->xmlReader($part);
        $rowOpen = false;

        try {
            while ($reader->read()) {
                $type = $reader->nodeType();

                if ($type === XMLReader::ELEMENT && $reader->localName() === 'c') {
                    if ($reader->isEmptyElement()) {
                        continue;
                    }

                    $value = $this->readCell($reader, $sharedStrings, $formats, $date1904, $maxBytes - strlen($buffer->toString()));

                    if ($value === '') {
                        continue;
                    }

                    if (! $buffer->append($rowOpen ? "\t" : $header) || ! $buffer->append($value)) {
                        return false;
                    }

                    $header = '';
                    $rowOpen = true;

                    continue;
                }

                if ($type === XMLReader::END_ELEMENT && $reader->localName() === 'row' && $rowOpen) {
                    $rowOpen = false;

                    if (! $buffer->append("\n")) {
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
     * Expects the reader on a non-empty <c> element and leaves it on the matching end tag. The
     * returned value may run a few bytes past $limit; the buffer makes the final cut.
     */
    private function readCell(SafeXmlReader $reader, SharedStrings $sharedStrings, CellFormats $formats, bool $date1904, int $limit): string
    {
        $type = $reader->attribute('t');
        $style = self::unsignedInt($reader->attribute('s'));
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
                    $value = $reader->isEmptyElement() ? '' : $this->readElementText($reader, max($limit, self::MIN_VALUE_BYTES));
                } elseif ($name === 'is') {
                    $inline = $reader->isEmptyElement() ? '' : $this->readStringItem($reader, $limit);
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

                return ctype_digit($index) ? $sharedStrings->get((int) $index) : '';
            case 'inlineStr':
                return self::flattenLineBreaks($inline ?? (string) $value);
            case 'b':
                return match (trim((string) $value)) {
                    '1' => 'TRUE',
                    '0' => 'FALSE',
                    default => self::flattenLineBreaks((string) $value),
                };
            case null:
            case 'n':
                $date = $style === null ? null : SerialDate::format((string) $value, $formats->kindOf($style), $date1904);

                return $date ?? self::flattenLineBreaks((string) $value);
            default:
                return self::flattenLineBreaks((string) $value);
        }
    }

    /**
     * Concatenates the <t> runs of a rich-text item (<si> or <is>), leaving out phonetic (<rPh>)
     * guides. Expects the reader on a non-empty start tag and leaves it on the matching end tag.
     * Keeps at most $limit plus a few bytes, so the caller can both detect and cleanly cut an
     * overlong item; the rest is consumed but not kept.
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
            } elseif ($textDepth !== null && in_array($nodeType, self::TEXT_NODES, true)) {
                $text = self::appendBounded($text, $reader->value(), $limit);
            }

            $moved = $reader->read();
        }

        return $text;
    }

    /**
     * Expects the reader on a non-empty start tag and leaves it on the matching end tag. Keeps at
     * most $limit plus a few bytes.
     */
    private function readElementText(SafeXmlReader $reader, int $limit): string
    {
        $depth = $reader->depth();
        $text = '';

        while ($reader->read()) {
            $nodeType = $reader->nodeType();

            if ($nodeType === XMLReader::END_ELEMENT && $reader->depth() === $depth) {
                break;
            }

            if (in_array($nodeType, self::TEXT_NODES, true)) {
                $text = self::appendBounded($text, $reader->value(), $limit);
            }
        }

        return $text;
    }

    private static function appendBounded(string $text, string $chunk, int $limit): string
    {
        $room = max(0, $limit) + self::UTF8_SLACK - strlen($text);

        if ($room <= 0) {
            return $text;
        }

        return $text.(strlen($chunk) > $room ? substr($chunk, 0, $room) : $chunk);
    }

    /**
     * A plain non-negative decimal integer within 32 bits, or null.
     */
    private static function unsignedInt(?string $value): ?int
    {
        $value = trim((string) $value);

        if ($value === '' || strlen($value) > 10 || ! ctype_digit($value)) {
            return null;
        }

        $number = (int) $value;

        return $number <= 0xFFFFFFFF ? $number : null;
    }

    private static function flattenLineBreaks(string $text): string
    {
        return str_replace(["\r\n", "\r", "\n"], ' ', $text);
    }

    private static function sibling(string $part, string $name): string
    {
        $slash = strrpos($part, '/');

        return $slash === false ? $name : substr($part, 0, $slash + 1).$name;
    }
}
