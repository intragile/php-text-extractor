# Security Policy

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

## Reporting a vulnerability

Please report security issues privately to **info@intragile.eu** rather than opening a public
issue. Include the affected version and, if possible, a sample file and steps to reproduce. You
will receive a reply within a few working days.

## Scope

This package parses untrusted files. Reports are especially welcome for:

- XML external entity (XXE) or entity-expansion issues in any XML-based format;
- zip bombs or other inputs that bypass the size limits and exhaust memory or disk;
- command injection or argument smuggling into `pdftotext` or `tesseract`;
- temporary files that are left behind or created with unsafe permissions.

Bugs in poppler or Tesseract themselves should be reported to those projects; the package limits
their impact with a timeout and, on Linux, a per-process memory cap.

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu).
