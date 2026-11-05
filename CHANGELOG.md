# Changelog

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `TextExtractor` with `extract()`, `extractFromString()` and `extractFromStream()`, returning a
  `Result` with a four-way `Status` (`Extracted`, `Empty`, `NotExtractable`, `Failed`), a
  machine-readable `Reason`, the detected `Format` and a `truncated` flag.
- Content-based format detection for PDF, PNG, JPEG, TIFF, BMP, GIF, WebP, RTF, the OOXML and
  OpenDocument containers and plain-text flavours.
- Extractors for TXT, Markdown, CSV, TSV, JSON, XML, HTML, RTF, DOCX, PPTX, XLSX, ODT, ODS, ODP,
  PDF (`pdftotext`) and images (`tesseract`), with no Composer dependencies.
- Streaming readers for the zip-based formats, with decompressed-byte budgets that hold even when
  a file misstates its sizes, and an XML reader that refuses DOCTYPE declarations.
- External tools run without a shell, with a timeout, bounded output, a per-process memory cap on
  Linux and a private empty working directory.
- `Options` for size limits, timeouts, tool paths, OCR languages and the fallback text encoding,
  including a built-in Windows-1250 decoder.
- The `Extractor` contract for custom extractors, registered with `withExtractor()`.

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu).
