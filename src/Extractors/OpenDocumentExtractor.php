<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Extractors;

use DateTimeImmutable;
use DateTimeZone;
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
 * Streams the text of OpenDocument text documents, spreadsheets and presentations from content.xml.
 *
 * Spreadsheets and the tables of text documents and presentations are written one row per line
 * with tab-separated cells. Footnotes and endnotes are moved after the body text. Empty cells and rows are
 * never expanded from their repeat counts: a single row element may legally stand for millions of
 * blank rows, so only cells with content are repeated, and only up to a fixed cap.
 */
final class OpenDocumentExtractor implements DeadlineAwareExtractor
{
    private const NS_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';

    private const NS_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

    private const NS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';

    private const NS_DRAW = 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0';

    private const NS_PRESENTATION = 'urn:oasis:names:tc:opendocument:xmlns:presentation:1.0';

    private const NS_SVG = 'urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0';

    private const NS_MANIFEST = 'urn:oasis:names:tc:opendocument:xmlns:manifest:1.0';

    private const MAX_SPACES = 1000;

    private const MAX_REPEAT = 1000;

    /**
     * Subtrees whose text is not part of the visible document body: comments, deleted revisions,
     * footnote and endnote marks, accessibility metadata, speaker notes, embedded binaries and
     * style definitions.
     */
    private const SKIPPED = [
        self::NS_OFFICE => ['annotation', 'forms', 'automatic-styles', 'font-face-decls', 'scripts'],
        self::NS_TEXT => ['tracked-changes', 'sequence-decls', 'note-citation'],
        self::NS_TABLE => ['covered-table-cell'],
        self::NS_DRAW => ['image'],
        self::NS_SVG => ['desc', 'title'],
        self::NS_PRESENTATION => ['notes'],
    ];

    /** Table elements of text documents and presentations => their start and end layout events. */
    private const TABLE_EVENTS = [
        'table' => ['table', 'table-end'],
        'table-row' => ['row', 'row-end'],
        'table-cell' => ['cell', 'cell-end'],
    ];

    /** What a layout event adds to the text of a spreadsheet cell, which is flattened later. */
    private const CELL_EVENTS = [
        'tab' => "\t",
        'line' => "\n",
        'paragraph' => "\n",
    ];

    /** office:value-type => the attribute holding the typed value */
    private const VALUE_ATTRIBUTES = [
        'float' => 'value',
        'percentage' => 'value',
        'currency' => 'value',
        'date' => 'date-value',
        'time' => 'time-value',
        'boolean' => 'boolean-value',
        'string' => 'string-value',
    ];

    public function supports(Format $format): bool
    {
        return in_array($format, [Format::Odt, Format::Ods, Format::Odp], true);
    }

    public function extract(string $path, Format $format, Options $options): Result
    {
        return $this->extractWithin($path, $format, $options, Deadline::fromOptions($options));
    }

    public function extractWithin(string $path, Format $format, Options $options, Deadline $deadline): Result
    {
        foreach (['zip', 'xmlreader'] as $extension) {
            if (! extension_loaded($extension)) {
                return Result::failed(Reason::MissingExtension, $format, sprintf('ext-%s is required to read OpenDocument files.', $extension));
            }
        }

        $zip = SafeZip::open($path, $options, $deadline);

        try {
            // An encrypted content.xml is ciphertext, so this must be decided before parsing it.
            if ($this->isEncrypted($zip)) {
                return Result::notExtractable(Reason::Encrypted, $format, 'The document is password protected.');
            }

            if (! $zip->has('content.xml')) {
                throw new CorruptInput('The OpenDocument package has no content.xml.');
            }

            $buffer = new TextBuffer($options->maxOutputBytes);
            $notes = new TextBuffer($options->maxOutputBytes);
            $reader = $zip->xmlReader('content.xml');

            try {
                $dropped = $this->walk($reader, $buffer, $notes, $options->maxOutputBytes);
            } finally {
                $reader->close();
            }
        } finally {
            $zip->close();
        }

        // Footnotes and endnotes follow the body, as they do for Word documents.
        if ($notes->toString() !== '' && ! $buffer->isFull()) {
            if ($buffer->toString() === '' || $buffer->append("\n")) {
                $buffer->append($notes->toString());
            }
        }

        return Result::extracted($buffer->toString(), $format, $dropped || $buffer->isTruncated() || $notes->isTruncated());
    }

    private function isEncrypted(SafeZip $zip): bool
    {
        if (! $zip->has('META-INF/manifest.xml')) {
            return false;
        }

        $reader = $zip->xmlReader('META-INF/manifest.xml');

        try {
            while ($reader->read()) {
                if ($reader->nodeType() === XMLReader::ELEMENT
                    && $reader->localName() === 'encryption-data'
                    && $reader->namespaceUri() === self::NS_MANIFEST) {
                    return true;
                }
            }
        } finally {
            $reader->close();
        }

        return false;
    }

    /**
     * @return bool whether spreadsheet content was dropped while buffering a cell or row
     */
    private function walk(SafeXmlReader $reader, TextBuffer $buffer, TextBuffer $notesBuffer, int $maxBytes): bool
    {
        $dropped = false;
        $paragraphs = 0;
        $pages = 0;
        $spreadsheet = false;
        $nestedTables = 0;
        $tableName = '';
        $headerWritten = false;
        $anyTableWritten = false;
        $body = new LayoutWriter($buffer);
        $notes = new LayoutWriter($notesBuffer);
        $openNotes = 0;

        /** @var list<string>|null $row non-empty cell values of the open row */
        $row = null;
        $rowBytes = 0;
        $rowRepeat = 1;

        /** @var string|null $cell text of the open cell */
        $cell = null;
        $cellRepeat = 1;
        $cellHasParagraph = false;
        $cellValue = '';
        $cellIso = null;

        $moved = $reader->read();

        while ($moved) {
            $type = $reader->nodeType();
            $namespace = $reader->namespaceUri();
            $name = $reader->localName();
            // What the node contributes: text, or a layout event of the shared table convention.
            $chunk = '';
            $event = null;
            $closes = false;

            if ($type === XMLReader::ELEMENT) {
                if (in_array($name, self::SKIPPED[$namespace] ?? [], true)) {
                    $moved = $reader->next();

                    continue;
                }

                $closes = $reader->isEmptyElement();
                $structural = $spreadsheet && $nestedTables === 0 && $namespace === self::NS_TABLE;

                if ($namespace === self::NS_TEXT) {
                    switch ($name) {
                        case 'p':
                        case 'h':
                            $paragraphs++;
                            $cellHasParagraph = $cellHasParagraph || $cell !== null;
                            break;
                        case 's':
                            $chunk = str_repeat(' ', self::count($reader->attribute('c', self::NS_TEXT), self::MAX_SPACES));
                            break;
                        case 'tab':
                            $event = 'tab';
                            break;
                        case 'line-break':
                            $event = 'line';
                            break;
                        case 'note':
                            $openNotes += $closes ? 0 : 1;
                            $closes = false;
                            break;
                    }
                } elseif ($namespace === self::NS_OFFICE && $name === 'spreadsheet') {
                    $spreadsheet = true;
                } elseif ($namespace === self::NS_DRAW && $name === 'page') {
                    $event = $pages++ > 0 ? 'paragraph' : null;
                } elseif (! $spreadsheet && $namespace === self::NS_TABLE && isset(self::TABLE_EVENTS[$name])) {
                    $event = self::TABLE_EVENTS[$name][0];
                } elseif ($spreadsheet && $namespace === self::NS_TABLE && $name === 'table' && ($cell !== null || $nestedTables > 0)) {
                    $nestedTables += $closes ? 0 : 1;
                    $closes = false;
                } elseif ($structural && $name === 'table') {
                    $tableName = (string) $reader->attribute('name', self::NS_TABLE);
                    $headerWritten = false;
                } elseif ($structural && $name === 'table-row') {
                    $row = [];
                    $rowBytes = 0;
                    $rowRepeat = self::count($reader->attribute('number-rows-repeated', self::NS_TABLE), self::MAX_REPEAT);
                } elseif ($structural && $name === 'table-cell') {
                    $cell = '';
                    $cellHasParagraph = false;
                    $cellRepeat = self::count($reader->attribute('number-columns-repeated', self::NS_TABLE), self::MAX_REPEAT);
                    $cellValue = self::typedValue($reader);
                    $cellIso = self::isoValue($reader);
                }
            } elseif ($type === XMLReader::END_ELEMENT) {
                $closes = true;
            } elseif ($paragraphs > 0 && in_array($type, [XMLReader::TEXT, XMLReader::CDATA, XMLReader::WHITESPACE, XMLReader::SIGNIFICANT_WHITESPACE], true)) {
                // ODF treats any run of white space inside a paragraph as a single space.
                $chunk = (string) preg_replace('/[ \t\r\n]+/', ' ', $reader->value());
            }

            $writer = $openNotes > 0 ? $notes : $body;

            // The start event of an empty element has been taken; its end is handled below.
            if ($event !== null && ! $this->send($writer, $event, $chunk, $cell, $openNotes, $maxBytes, $dropped)) {
                return $dropped;
            }

            $event = null;

            if ($closes) {
                if ($namespace === self::NS_TEXT && ($name === 'p' || $name === 'h')) {
                    $paragraphs = max(0, $paragraphs - 1);
                    $event = 'paragraph';
                } elseif ($namespace === self::NS_TEXT && $name === 'note' && $type === XMLReader::END_ELEMENT) {
                    $openNotes = max(0, $openNotes - 1);
                } elseif (! $spreadsheet && $namespace === self::NS_TABLE && isset(self::TABLE_EVENTS[$name])) {
                    $event = self::TABLE_EVENTS[$name][1];
                } elseif ($spreadsheet && $namespace === self::NS_TABLE) {
                    if ($nestedTables > 0) {
                        $nestedTables -= $name === 'table' ? 1 : 0;
                    } elseif ($name === 'table-cell' && $cell !== null && $row !== null) {
                        $value = $cellIso ?? ($cellHasParagraph ? trim((string) preg_replace('/[\t\n]+/', ' ', $cell)) : $cellValue);

                        for ($i = 0; $value !== '' && $i < $cellRepeat; $i++) {
                            if ($rowBytes >= $maxBytes) {
                                $dropped = true;
                                break;
                            }

                            $row[] = $value;
                            $rowBytes += strlen($value) + 1;
                        }

                        $cell = null;
                    } elseif ($name === 'table-row' && $row !== null) {
                        if ($row !== [] && ! $headerWritten) {
                            if (! $buffer->append(($anyTableWritten ? "\n" : '').'## '.$tableName."\n")) {
                                return $dropped;
                            }

                            $headerWritten = true;
                            $anyTableWritten = true;
                        }

                        $line = implode("\t", $row)."\n";

                        // Appended one copy at a time so the buffer limit also bounds memory.
                        for ($i = 0; $row !== [] && $i < $rowRepeat; $i++) {
                            if (! $buffer->append($line)) {
                                return $dropped;
                            }
                        }

                        $row = null;
                    }
                }
            }

            if (($event !== null || $chunk !== '') && ! $this->send($writer, $event ?? 'text', $chunk, $cell, $openNotes, $maxBytes, $dropped)) {
                return $dropped;
            }

            $moved = $reader->read();
        }

        return $dropped;
    }

    /**
     * Routes text or a layout event to the open spreadsheet cell, the notes or the body.
     *
     * A full notes buffer does not stop the walk: the body is still wanted, and the result is
     * marked truncated afterwards.
     *
     * @return bool false once the body buffer is full
     */
    private function send(LayoutWriter $writer, string $event, string $chunk, ?string &$cell, int $openNotes, int $maxBytes, bool &$dropped): bool
    {
        if ($cell !== null && $openNotes === 0) {
            $text = $event === 'text' ? $chunk : (self::CELL_EVENTS[$event] ?? '');

            if (strlen($cell) < $maxBytes) {
                $cell .= $text;
            } elseif ($text !== '') {
                $dropped = true;
            }

            return true;
        }

        $written = match ($event) {
            'text' => $writer->text($chunk),
            'tab' => $writer->tab(),
            'line' => $writer->lineBreak(),
            'paragraph' => $writer->paragraphEnd(),
            'table' => $writer->tableStart(),
            'table-end' => $writer->tableEnd(),
            'row' => $writer->rowStart(),
            'row-end' => $writer->rowEnd(),
            'cell' => $writer->cellStart(),
            'cell-end' => $writer->cellEnd(),
            default => true,
        };

        return $written || $openNotes > 0;
    }

    /**
     * Parses a repeat or space count, which defaults to 1 and is capped to keep output bounded.
     */
    private static function count(?string $value, int $max): int
    {
        if ($value === null || preg_match('/^\s*\d+\s*$/', $value) !== 1) {
            return 1;
        }

        return max(1, min($max, (int) trim($value)));
    }

    /**
     * Dates and times in ISO 8601 form, whatever display format the cell uses, so that they read
     * the same as XLSX dates: YYYY-MM-DD, YYYY-MM-DDTHH:MM:SS or HH:MM:SS, rounded to whole
     * seconds. Durations of a day or more, negative durations and unparsable values return null,
     * leaving the displayed text.
     */
    private static function isoValue(SafeXmlReader $reader): ?string
    {
        $type = $reader->attribute('value-type', self::NS_OFFICE);

        if ($type === 'date') {
            $value = trim((string) $reader->attribute('date-value', self::NS_OFFICE));

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                return $value;
            }

            if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d+))?$/', $value, $match) !== 1) {
                return null;
            }

            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $match[1], new DateTimeZone('UTC'));

            if ($date === false || $date->format('Y-m-d\TH:i:s') !== $match[1]) {
                return null;
            }

            if (isset($match[2]) && $match[2][0] >= '5') {
                $date = $date->modify('+1 second');
            }

            return $date->format('Y-m-d\TH:i:s');
        }

        if ($type === 'time') {
            $value = trim((string) $reader->attribute('time-value', self::NS_OFFICE));

            if (preg_match('/^PT(\d+)H(\d+)M(\d+(?:\.\d+)?)S$/', $value, $match) !== 1) {
                return null;
            }

            $seconds = (int) $match[1] * 3600 + (int) $match[2] * 60 + (int) round((float) $match[3]);

            return $seconds < 86400 ? sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60) : null;
        }

        return null;
    }

    /**
     * The machine value of a cell, used only when the cell has no displayed text paragraph.
     */
    private static function typedValue(SafeXmlReader $reader): string
    {
        $type = $reader->attribute('value-type', self::NS_OFFICE);
        $attribute = $type === null ? null : (self::VALUE_ATTRIBUTES[$type] ?? null);

        return $attribute === null ? '' : trim((string) $reader->attribute($attribute, self::NS_OFFICE));
    }
}
