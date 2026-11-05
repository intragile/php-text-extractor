<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Detection;

use IntrAgile\TextExtractor\Format;
use ZipArchive;

/**
 * Identifies a file's format from its content, using the extension only for plain-text flavours.
 *
 * Content wins because uploaded files are routinely misnamed; trusting the extension would send
 * a PDF called "notes.txt" to the plain-text extractor and index binary garbage.
 */
final class FormatDetector
{
    private const SNIFF_BYTES = 8192;

    private const OPENDOCUMENT = [
        'application/vnd.oasis.opendocument.text' => Format::Odt,
        'application/vnd.oasis.opendocument.spreadsheet' => Format::Ods,
        'application/vnd.oasis.opendocument.presentation' => Format::Odp,
    ];

    private const OOXML_PARTS = [
        'word/document.xml' => Format::Docx,
        'xl/workbook.xml' => Format::Xlsx,
        'ppt/presentation.xml' => Format::Pptx,
    ];

    private const CONTAINER_EXTENSIONS = [
        'docx' => Format::Docx,
        'xlsx' => Format::Xlsx,
        'pptx' => Format::Pptx,
        'odt' => Format::Odt,
        'ods' => Format::Ods,
        'odp' => Format::Odp,
    ];

    /**
     * Extensions of formats that are recognised by their bytes alone, never as text.
     */
    private const BINARY_EXTENSIONS = self::CONTAINER_EXTENSIONS + [
        'pdf' => Format::Pdf,
        'png' => Format::Png,
        'jpg' => Format::Jpeg,
        'jpeg' => Format::Jpeg,
        'jpe' => Format::Jpeg,
        'tif' => Format::Tiff,
        'tiff' => Format::Tiff,
        'bmp' => Format::Bmp,
        'gif' => Format::Gif,
        'webp' => Format::Webp,
    ];

    private const TEXT_EXTENSIONS = [
        'txt' => Format::Txt,
        'text' => Format::Txt,
        'log' => Format::Txt,
        'md' => Format::Markdown,
        'markdown' => Format::Markdown,
        'csv' => Format::Csv,
        'tsv' => Format::Tsv,
        'json' => Format::Json,
        'xml' => Format::Xml,
        'html' => Format::Html,
        'htm' => Format::Html,
        'xhtml' => Format::Html,
    ];

    /**
     * @param  string|null  $filenameHint  Original file name, used when the path is a temporary file.
     */
    public function detect(string $path, ?string $filenameHint = null): ?Format
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $head = @file_get_contents($path, false, null, 0, self::SNIFF_BYTES);

        if ($head === false) {
            return null;
        }

        $extension = strtolower(pathinfo($filenameHint ?? $path, PATHINFO_EXTENSION));

        $binary = $this->detectSignature($head);

        if ($binary !== null) {
            return $binary;
        }

        if (strncmp($head, "PK\x03\x04", 4) === 0) {
            return $this->detectContainer($path, $extension);
        }

        if ($this->looksLikeText($head)) {
            return self::TEXT_EXTENSIONS[$extension] ?? $this->sniffText($head);
        }

        return null;
    }

    /**
     * Returns the binary format the file name claims when the content is plain text instead.
     *
     * An empty or text-only "report.docx" is a broken upload rather than a text document, and
     * reporting it as an empty text file would hide the damage. Content that is binary in its own
     * right (a misnamed PDF, a legacy Office file) is left to detect().
     *
     * @param  string|null  $filenameHint  Original file name, used when the path is a temporary file.
     */
    public function contradictedFormat(string $path, ?string $filenameHint = null): ?Format
    {
        $claimed = self::BINARY_EXTENSIONS[strtolower(pathinfo($filenameHint ?? $path, PATHINFO_EXTENSION))] ?? null;

        if ($claimed === null || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $head = @file_get_contents($path, false, null, 0, self::SNIFF_BYTES);

        if ($head === false || $this->detectSignature($head) !== null || strncmp($head, "PK\x03\x04", 4) === 0) {
            return null;
        }

        return $this->looksLikeText($head) ? $claimed : null;
    }

    private function detectSignature(string $head): ?Format
    {
        return match (true) {
            str_starts_with($head, '%PDF-') => Format::Pdf,
            str_starts_with($head, "\x89PNG\r\n\x1a\n") => Format::Png,
            str_starts_with($head, "\xFF\xD8\xFF") => Format::Jpeg,
            str_starts_with($head, "II*\0"), str_starts_with($head, "MM\0*") => Format::Tiff,
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => Format::Gif,
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => Format::Webp,
            str_starts_with($head, '{\rtf') => Format::Rtf,
            str_starts_with($head, 'BM') && strlen($head) >= 14 && $this->isBmpHeader($head) => Format::Bmp,
            default => null,
        };
    }

    /**
     * "BM" alone is too common a text prefix, so the pixel-data offset must also be plausible.
     */
    private function isBmpHeader(string $head): bool
    {
        $offset = unpack('V', substr($head, 10, 4));

        return is_array($offset) && $offset[1] >= 14 && $offset[1] < 65536 && str_contains(substr($head, 0, 14), "\0");
    }

    private function detectContainer(string $path, string $extension): ?Format
    {
        if (! extension_loaded('zip')) {
            return self::CONTAINER_EXTENSIONS[$extension] ?? null;
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            if ($zip->locateName('mimetype') !== false) {
                $mimetype = $zip->getFromName('mimetype', 128);

                return is_string($mimetype) ? (self::OPENDOCUMENT[trim($mimetype)] ?? null) : null;
            }

            foreach (self::OOXML_PARTS as $part => $format) {
                if ($zip->locateName($part) !== false) {
                    return $format;
                }
            }

            return null;
        } finally {
            $zip->close();
        }
    }

    private function looksLikeText(string $head): bool
    {
        if (str_starts_with($head, "\xFF\xFE") || str_starts_with($head, "\xFE\xFF")) {
            return true;
        }

        return ! str_contains($head, "\0");
    }

    private function sniffText(string $head): Format
    {
        $start = strtolower(ltrim(str_starts_with($head, "\xEF\xBB\xBF") ? substr($head, 3) : $head));

        return match (true) {
            str_starts_with($start, '<?xml') => Format::Xml,
            str_starts_with($start, '<!doctype html'), str_starts_with($start, '<html') => Format::Html,
            default => Format::Txt,
        };
    }
}
