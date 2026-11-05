# PHP Text Extractor

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

[![Tests](https://github.com/intragile/php-text-extractor/actions/workflows/tests.yml/badge.svg)](https://github.com/intragile/php-text-extractor/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/intragile/php-text-extractor.svg)](https://packagist.org/packages/intragile/php-text-extractor)
[![PHP](https://img.shields.io/packagist/php-v/intragile/php-text-extractor.svg)](https://packagist.org/packages/intragile/php-text-extractor)
[![License](https://img.shields.io/packagist/l/intragile/php-text-extractor.svg)](LICENSE)

**Extract plain text from PDF, Word, Excel, PowerPoint, OpenDocument, RTF, HTML, CSV and images**
with one call, for search indexes, RAG pipelines and archives.

[Magyar nyelvű leírás → README.hu.md](README.hu.md) ·
Laravel? → [intragile/laravel-text-extractor](https://github.com/intragile/laravel-text-extractor)

```php
use IntrAgile\TextExtractor\TextExtractor;

$result = TextExtractor::create()->extract('/path/to/upload.docx');

if ($result->isSuccessful()) {
    $index->store($result->text);          // '' when the file simply has no text
} elseif ($result->isRetryable()) {
    $queue->retryLater();                  // timeout, missing tool, I/O error
} else {
    $log->info($result->reason->value);    // encrypted, corrupt, too_large, ...
}
```

- **No Composer dependencies.** DOCX, XLSX, PPTX, ODT, ODS and ODP are read with PHP's own
  `ZipArchive` and `XMLReader`; PDF and OCR use the standard `pdftotext` and `tesseract` tools.
- **Bounded memory.** Office files are streamed, never loaded whole; output is capped; external
  tools run with a timeout and, on Linux, a per-process memory cap, so one huge scan cannot take
  your worker down.
- **A result that tells "no text" from "failed".** A photo without text is a success with empty
  text, not an error, and a timeout is never mistaken for an empty file. Batch jobs can retry
  exactly the files worth retrying.
- **Safe on untrusted uploads.** Zip bombs are stopped on the bytes actually decompressed (not
  the sizes the file claims), XML with a DOCTYPE is refused (no XXE), and tools are started
  without a shell.
- **Content-based detection.** A PDF called `notes.txt` is read as a PDF.
- **PHP 8.1+, framework-free.**

## Installation

```bash
composer require intragile/php-text-extractor
```

Requires `ext-mbstring`. Install what your formats need:

| Formats | Needs |
|---|---|
| TXT, MD, CSV, TSV, JSON, RTF | nothing else |
| XML | `ext-xmlreader` |
| HTML | `ext-dom` |
| DOCX, XLSX, PPTX, ODT, ODS, ODP | `ext-zip`, `ext-xmlreader` |
| PDF | `pdftotext` from poppler-utils |
| PNG, JPEG, TIFF, GIF, WebP, BMP | `tesseract` and its language data |

```bash
# Debian / Ubuntu
apt-get install poppler-utils tesseract-ocr tesseract-ocr-eng
# Alpine
apk add poppler-utils tesseract-ocr tesseract-ocr-data-eng
# macOS
brew install poppler tesseract
```

A missing extension or tool never throws: files of that format come back as
`Failed` with reason `missing_extension` or `missing_tool`.

## Results

`extract()`, `extractFromString()` and `extractFromStream()` always return an
`IntrAgile\TextExtractor\Result` and never throw for problems with the input.

| Property | Type | |
|---|---|---|
| `status` | `Status` | `Extracted`, `Empty`, `NotExtractable` or `Failed` |
| `text` | `string` | UTF-8, normalised line endings; `''` unless `Extracted` |
| `format` | `?Format` | detected format, e.g. `Format::Docx` |
| `reason` | `?Reason` | why it is not a plain success |
| `message` | `?string` | human-readable detail for logs |
| `truncated` | `bool` | the output limit was reached |

| Status | Meaning | Retry? |
|---|---|---|
| `Extracted` | text was found | – |
| `Empty` | processed successfully, the file has no text | no |
| `NotExtractable` | the file can never be processed as it is | no |
| `Failed` | a technical problem; the file may succeed later | yes |

| Reason | Status | When |
|---|---|---|
| `unreadable` | Failed | the file is missing or cannot be read |
| `missing_extension` | Failed | `ext-zip`, `ext-xmlreader` or `ext-dom` is not installed |
| `missing_tool` | Failed | `pdftotext` or `tesseract` is not installed |
| `tool_timeout` | Failed | the tool exceeded the timeout |
| `tool_failed` | Failed | the tool crashed, ran out of memory or reported an error |
| `internal_error` | Failed | an extractor threw unexpectedly (please report it) |
| `unsupported_format` | NotExtractable | unknown format, legacy `.doc`/`.xls`/`.ppt`, encrypted Office file |
| `too_large` | NotExtractable | over `maxInputBytes` |
| `image_too_large` | NotExtractable | over `maxImagePixels` (checked before OCR starts) |
| `encrypted` | NotExtractable | password-protected PDF or OpenDocument file |
| `corrupt` | NotExtractable | damaged or structurally invalid file |
| `archive_limit_exceeded` | NotExtractable | a zip-bomb limit was hit |
| `no_text_layer` | Empty | a PDF without a text layer, typically a scan |

## Input

```php
$extractor = TextExtractor::create();

$extractor->extract('/tmp/php3hX9a');                    // a path
$extractor->extract('/tmp/php3hX9a', 'export.csv');      // + original name for .csv/.md/.json/...
$extractor->extractFromString($bytes, 'notes.txt');
$extractor->extractFromStream($stream, 'report.pdf');    // read from the current position
```

The file name only matters for text files, where it chooses between TXT, Markdown, CSV, TSV,
JSON, XML and HTML. Binary formats are recognised by their content.

## What you get per format

| Format | Output |
|---|---|
| DOCX | body, tables, text boxes, footnotes and endnotes; deleted tracked changes and field codes are left out |
| XLSX | every sheet as `## Sheet name`, then one line per non-empty row with cells separated by tabs; cached formula values; dates stay serial numbers |
| PPTX | slides in presentation order, separated by a blank line; speaker notes are left out |
| ODT / ODS / ODP | as for DOCX / XLSX / PPTX; annotations and tracked deletions are left out |
| RTF | text with paragraphs; font tables, pictures and other metadata are skipped |
| HTML | visible text with block elements on separate lines; scripts and styles are dropped; `<title>` first |
| XML | text and CDATA content |
| CSV / TSV / TXT / MD / JSON | the text itself, decoded to UTF-8 |
| PDF | `pdftotext` output, pages separated by a blank line |
| Images | Tesseract OCR in the configured languages |

## Options

```php
use IntrAgile\TextExtractor\Options;

$extractor = TextExtractor::create(new Options(
    ocrLanguages: 'hun+eng',
    fallbackEncoding: 'Windows-1250',
    processTimeout: 120,
));
```

| Option | Default | |
|---|---|---|
| `maxInputBytes` | 100 MiB | larger files are `too_large` |
| `maxOutputBytes` | 10 MiB | longer text is cut on a UTF-8 boundary and `truncated` is set |
| `maxArchiveEntryBytes` | 256 MiB | decompressed bytes allowed from one zip entry |
| `maxArchiveTotalBytes` | 512 MiB | decompressed bytes allowed from one file |
| `maxArchiveEntries` | 10 000 | entries allowed in one zip container |
| `maxImagePixels` | 16 000 000 | larger images are refused before OCR |
| `processTimeout` | 60 s | per external tool run |
| `processMemoryMb` | 512 | address-space cap per tool on Linux; `0` disables it |
| `pdftotextBinary` | `null` | path or command name; `null` searches the `PATH` |
| `tesseractBinary` | `null` | path or command name; `null` searches the `PATH` |
| `ocrLanguages` | `eng` | Tesseract languages joined with `+`; all must be installed |
| `fallbackEncoding` | `Windows-1252` | for text files that are not valid UTF-8 |

`$options->with(['maxOutputBytes' => 1_000_000])` returns a modified copy.

## Custom extractors

Implement `IntrAgile\TextExtractor\Contracts\Extractor` and register it; it is tried before the
built-in extractors.

```php
use IntrAgile\TextExtractor\Contracts\Extractor;
use IntrAgile\TextExtractor\Format;
use IntrAgile\TextExtractor\Options;
use IntrAgile\TextExtractor\Result;

final class CloudOcrExtractor implements Extractor
{
    public function supports(Format $format): bool
    {
        return $format->isImage();
    }

    public function extract(string $path, Format $format, Options $options): Result
    {
        return Result::extracted($this->client->recognise($path), $format);
    }
}

$extractor = TextExtractor::create()->withExtractor(new CloudOcrExtractor($client));
```

Return raw text; normalisation, the output cap and the empty-text check are applied for you.
Exceptions thrown by an extractor become `Failed/internal_error`.

## Security

The package is meant for untrusted uploads:

- **Zip bombs.** Every zip entry is read through a counting stream; decompression stops at
  `maxArchiveEntryBytes` / `maxArchiveTotalBytes` even when the file lies about its sizes.
  Entry names with `..` or absolute paths are never read.
- **XML.** Network access, DTD loading and entity substitution are off, and any document with
  a DOCTYPE is refused: no supported format uses one.
- **External tools.** Commands are passed as argument arrays, never through a shell, with a
  timeout, bounded output and a memory cap.
- **Temporary files** are created in `sys_get_temp_dir()` and deleted in `finally` blocks.

See [SECURITY.md](SECURITY.md) to report a vulnerability.

## Compared with

- **[spatie/pdf-to-text](https://github.com/spatie/pdf-to-text)** – PDF only; throws on
  failures, so empty and failed files have to be told apart by the caller.
- **[vaites/php-apache-tika](https://github.com/vaites/php-apache-tika)** – broader format
  coverage, but needs a Java Tika server.
- **[phpoffice/phpword](https://github.com/PHPOffice/PHPWord) and friends** – full document
  models for reading and writing; they load the whole document into memory.

## License

MIT, see [LICENSE](LICENSE).

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu).
