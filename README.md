# phppdf

Modern PHP library for PDF generation. Requires PHP 8.4+.

## Status

Phase 0 — the library emits a valid empty PDF 1.7 document. Content (text, graphics, fonts, images, HTML) will land in subsequent phases. The public surface is expected to remain unchanged but is not frozen.

## Installation

```bash
composer require dragonofmercy/phppdf
```

## Usage

```php
use PhpPdf\Document;

$pdf = new Document();
$pdf->addPage();
$pdf->save('out.pdf');
```

`output()` returns the PDF bytes as a string instead of writing to disk.

## Development

Clone the repo and from inside `build/`:

```bash
composer install
composer check   # runs PHPStan (level max) + PHPUnit (unit + golden)
```

The golden test invokes `qpdf --check` to structurally validate generated PDFs. Install qpdf locally:

- Linux: `sudo apt-get install qpdf`
- macOS: `brew install qpdf`
- Windows: `choco install qpdf`

If qpdf is unavailable the structural assertion is skipped; the byte-match assertion still runs.

### Regenerating the golden fixture

When you intentionally change the generator output:

```bash
php tests/Golden/regenerate.php
```

Commit the new `tests/Golden/fixtures/empty-page.pdf` alongside the code change.

## License

MIT — see [LICENSE](LICENSE).
