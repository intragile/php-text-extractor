# Changelog

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-11-06

### Added

- `TextExtractor` with `extract()`, `extractFromString()` and `extractFromStream()`, returning a
  `Result` with a four-way `Status` (`Extracted`, `Empty`, `NotExtractable`, `Failed`), a
  machine-readable `Reason`, the detected `Format` and a `truncated` flag.
- Content-based format detection for PDF, PNG, JPEG, TIFF, BMP, GIF, WebP, RTF, the OOXML and
  OpenDocument containers and plain-text flavours.
- Extractors for TXT, Markdown, CSV, TSV, JSON, XML, HTML, RTF, DOCX, PPTX, XLSX, ODT, ODS, ODP,
  PDF (`pdftotext`) and images (`tesseract`), with no Composer dependencies.
- Streaming readers for the zip-based formats, with decompressed-byte budgets that hold even when
  a file misstates its sizes, and an XML reader that never loads external DTDs, refuses inline
  DTD subsets and keeps libxml's error list from growing.
- External tools run from argument arrays with quoted arguments, a timeout, bounded output, a
  per-process memory cap on Linux and a private empty working directory.
- `Options` for size limits (including a separate 10 MiB HTML limit and a per-page image pixel
  limit), timeouts, tool paths, OCR languages and the fallback text encoding, with built-in
  decoders for Windows-1250, 1253, 1255–1258, CP874 and CP437.
- `maxParseSeconds`, a cooperative time limit for parsing done in PHP, reported as
  `Failed` / `parse_timeout`; external tools keep their own `processTimeout`.
- Speaker notes of PPTX and ODP slides, written after the text of their slide.
- XLSX cells with a date or time number format and ODS date and time cells are written in ISO
  8601 notation (`2026-11-05`, `2026-11-05T10:30:00`, `10:30:00`) instead of their display text
  or serial number; for XLSX the number format decides which part is shown, and both the 1900 and
  the 1904 date systems are supported.
- The `Extractor` contract for custom extractors, registered with `withExtractor()`.

[1.0.0]: https://github.com/intragile/php-text-extractor/releases/tag/v1.0.0

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu).
