# Test fixtures

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

How each committed fixture was produced.

## PDF (`pdf/`)

Generated with Ghostscript 10.05.0 (`pdfwrite`) from the PostScript sources in `pdf/src/`, and
checked against poppler `pdftotext` 26.03.0. Run from `tests/fixtures/pdf`:

```sh
gs="gs -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=pdfwrite"
$gs -sOutputFile=text.pdf src/text.ps
$gs -sOutputFile=blank.pdf src/blank.ps
$gs -sOutputFile=multipage.pdf src/multipage.ps
$gs -sOwnerPassword=owner -sUserPassword=user -dEncryptionR=3 -dKeyLength=128 -sOutputFile=encrypted.pdf src/text.ps
$gs -sOwnerPassword=owner -dEncryptionR=3 -dKeyLength=128 -dPermissions=-3904 -sOutputFile=owner-only.pdf src/text.ps
php src/damage.php text.pdf damaged-stream.pdf
printf '%%PDF-1.7\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\nthis is not a valid PDF body: no page tree, no xref table, no trailer\n%%%%EOF\n' > corrupt.pdf
```

(In zsh, use `gs=(gs -q ...)` so that the options split into separate arguments.) Ghostscript
embeds a creation date, so regenerated files differ byte-wise but behave identically.

| File | Content | pdftotext result |
|---|---|---|
| `text.pdf` | One page, two ASCII lines in Helvetica | text, exit 0 |
| `multipage.pdf` | Two pages; Helvetica re-encoded so that Hungarian accents, including ő/ű/Ő/Ű, are reachable | `árvíztűrő tükörfúrógép` / `ÁRVÍZTŰRŐ TÜKÖRFÚRÓGÉP`, pages separated by a form feed |
| `blank.pdf` | One page with a filled rectangle and a line, no text operators | only `\f`, empty stderr, exit 0 |
| `encrypted.pdf` | `text.ps`, user password `user`, owner password `owner`, RC4 128-bit (R3) | `Command Line Error: Incorrect password`, exit 1 |
| `owner-only.pdf` | `text.ps`, owner password only, printing/copying not permitted | opens and extracts normally, exit 0 |
| `damaged-stream.pdf` | `text.pdf` with 38 bytes of the page content stream overwritten; xref intact | only `\f`, many `Syntax Error (…)` lines on stderr, exit 0 |
| `corrupt.pdf` | Starts with `%PDF-1.7` but has no page tree, xref table or trailer | `Couldn't read xref table`, exit 1 |

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
