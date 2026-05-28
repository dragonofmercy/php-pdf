<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

use DragonOfMercy\PhpPdf\{Color, Page};
use DragonOfMercy\PhpPdf\Barcode\Pdf417\{Encoder, Matrix};
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * PDF417 barcode (ISO/IEC 15438), standard variant.
 *
 * Stacked-row 2D symbology with Text/Byte/Numeric compaction and Reed-Solomon
 * error correction over GF(929). The encoder auto-fits dimensions (optionally
 * constrained by {@see self::withColumns()}) and auto-selects the EC level
 * (override via {@see self::withErrorCorrection()}). UTF-8 text emits an ECI 26
 * designator. Unlike the square 2D codes, PDF417 is rectangular: `h` is
 * optional and need not equal `w`.
 *
 * Use {@see self::of()} to construct.
 */
final readonly class Pdf417 implements Barcode
{
    private function __construct(
        public string $data,
        public ?int   $ecLevel,
        public ?int   $columns,
        public Color  $color,
    ) {}

    /**
     * Create a PDF417 barcode for the given payload.
     *
     * @param string $data Non-empty payload (any byte sequence; UTF-8 auto-encoded via ECI 26).
     *
     * @throws PdfException If $data is empty.
     */
    public static function of(string $data): self
    {
        if ($data === '') {
            throw new PdfException('PDF417 data must not be empty');
        }
        return new self($data, null, null, Color::rgb(0, 0, 0));
    }

    /**
     * Returns a copy with a fixed error-correction level (0-8).
     *
     * @throws PdfException If $level is out of range.
     */
    public function withErrorCorrection(int $level): self
    {
        if ($level < 0 || $level > 8) {
            throw new PdfException(sprintf('PDF417 error-correction level must be 0-8, got %d', $level));
        }
        return new self($this->data, $level, $this->columns, $this->color);
    }

    /**
     * Returns a copy constrained to the given number of data columns (1-30).
     *
     * @throws PdfException If $columns is out of range.
     */
    public function withColumns(int $columns): self
    {
        if ($columns < 1 || $columns > 30) {
            throw new PdfException(sprintf('PDF417 column count must be 1-30, got %d', $columns));
        }
        return new self($this->data, $this->ecLevel, $columns, $this->color);
    }

    /** Returns a copy with the given foreground color. */
    public function withColor(Color $color): self
    {
        return new self($this->data, $this->ecLevel, $this->columns, $color);
    }

    public function encode(): EncodedBarcode
    {
        // Replicate the matrix-building pipeline from draw() so encode() can be
        // called standalone.
        $result = Encoder::encode($this->data, $this->ecLevel, $this->columns);
        $rows = Matrix::build($result);

        return new EncodedBarcode(
            kind: BarcodeKind::MATRIX_2D,
            modules: self::padMatrix($rows, 2),
            humanTextSegments: [],
            color: $this->color,
            orientation: Orientation::Horizontal,
        );
    }

    /**
     * Symmetrically pads a 2D matrix with `false` modules on every side.
     * Works for rectangular matrices (PDF417 has rows independent of columns).
     *
     * @param list<list<bool>> $matrix
     * @return list<list<bool>>
     */
    private static function padMatrix(array $matrix, int $quiet): array
    {
        if ($matrix === []) {
            return [];
        }
        $cols = count($matrix[0]);
        $width = $cols + 2 * $quiet;
        $emptyRow = array_fill(0, $width, false);
        $result = [];
        for ($i = 0; $i < $quiet; $i++) {
            $result[] = $emptyRow;
        }
        foreach ($matrix as $row) {
            $padded = array_merge(
                array_fill(0, $quiet, false),
                $row,
                array_fill(0, $quiet, false),
            );
            $result[] = $padded;
        }
        for ($i = 0; $i < $quiet; $i++) {
            $result[] = $emptyRow;
        }
        return $result;
    }

    /**
     * Renders the PDF417 onto the page.
     *
     * A 2-module quiet zone per side is included within `$w` (and `$h` when provided).
     * PDF417 is rectangular: `$h` is optional and need not relate to `$w`; when
     * omitted, each row is rendered at a 3:1 (row-height to module-width) ratio.
     *
     * @throws PdfException If $w (or $h, when provided) is not positive.
     */
    public function draw(Page $page, float $x, float $y, float $w, ?float $h): void
    {
        if ($w <= 0) {
            throw new PdfException(sprintf('PDF417 width must be positive, got %g', $w));
        }
        if ($h !== null && $h <= 0) {
            throw new PdfException(sprintf('PDF417 height must be positive, got %g', $h));
        }

        $result = Encoder::encode($this->data, $this->ecLevel, $this->columns);
        $rows = Matrix::build($result);

        $totalCols = Matrix::modulesPerRow($result->columns) + 4; // 2-module quiet zone each side
        $unit = $page->unit;
        $xPt = $unit->toPoints($x);
        $yPt = $unit->toPoints($y);
        $wPt = $unit->toPoints($w);
        $moduleW = $wPt / $totalCols;
        $rowH = $h !== null ? ($unit->toPoints($h) / ($result->rows + 4)) : (3.0 * $moduleW);
        $quietX = 2 * $moduleW;
        $quietY = 2 * $rowH;

        $body = '';
        foreach ($rows as $r => $row) {
            $yRow = $yPt + $quietY + $r * $rowH;
            $body .= Renderer::runLengthRow($row, $xPt + $quietX, $yRow, $moduleW, $rowH);
        }
        $page->contentStream()->append(Renderer::wrap($body, $this->color));
    }
}
