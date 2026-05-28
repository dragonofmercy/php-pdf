<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Barcode;

use DragonOfMercy\PhpPdf\{Color, Page};
use DragonOfMercy\PhpPdf\Barcode\Qr\{Encoder, Mask, Matrix};
use DragonOfMercy\PhpPdf\Exception\PdfException;

/**
 * QR Code (ISO/IEC 18004), versions V1-V40 (full ISO range).
 *
 * Use {@see self::of()} to construct. The encoder picks the smallest version
 * (V1-V40) that fits the data + the chosen error-correction overhead. Default
 * error correction is M (~15% recovery).
 *
 * Coordinates use the document's unit and a top-down Y axis. The QR is square
 * by construction; pass `w` only (h derived) or `w` and `h` equal.
 */
final readonly class QrCode implements Barcode
{
    private function __construct(
        public string $data,
        public ErrorCorrection $errorCorrection,
        public Color $color,
    ) {}

    public static function of(string $data, ErrorCorrection $ec = ErrorCorrection::M): self
    {
        if ($data === '') {
            throw new PdfException('QR code data must not be empty');
        }
        return new self($data, $ec, Color::rgb(0, 0, 0));
    }

    public function withColor(Color $color): self
    {
        return new self($this->data, $this->errorCorrection, $color);
    }

    public function withErrorCorrection(ErrorCorrection $ec): self
    {
        return new self($this->data, $ec, $this->color);
    }

    public function encode(): EncodedBarcode
    {
        // Replicate the matrix-building pipeline from draw() so encode() can be
        // called standalone. Encoder is fast; double-running is acceptable.
        $encoded = Encoder::encode($this->data, $this->errorCorrection);
        $bits = '';
        foreach ($encoded->finalCodewords as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        $remainder = self::remainderBits($encoded->version);
        $bits .= str_repeat('0', $remainder);
        $matrix = Matrix::buildEmpty($encoded->version);
        $matrix->placeData($bits);
        $bestScore = PHP_INT_MAX;
        $bestModules = $matrix->modules;
        for ($m = 0; $m < 8; $m++) {
            $candidate = Mask::apply($matrix->modules, $matrix->reserved, $m);
            Mask::placeFormatBits($candidate, $this->errorCorrection, $m);
            $score = Mask::score($candidate);
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestModules = $candidate;
            }
        }
        if ($encoded->version >= 7) {
            Mask::placeVersionBits($bestModules, $encoded->version);
        }
        /** @var list<list<bool>> $matrixList */
        $matrixList = array_values(array_map('array_values', $bestModules));

        return new EncodedBarcode(
            kind: BarcodeKind::MATRIX_2D,
            modules: self::padMatrix($matrixList, 4),
            humanTextSegments: [],
            color: $this->color,
            orientation: Orientation::Horizontal,
        );
    }

    /**
     * Symmetrically pads a 2D matrix with `false` modules on every side.
     *
     * @param list<list<bool>> $matrix
     * @return list<list<bool>>
     */
    private static function padMatrix(array $matrix, int $quiet): array
    {
        $size = count($matrix);
        $width = $size + 2 * $quiet;
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

    public function draw(Page $page, float $x, float $y, float $w, ?float $h): void
    {
        if ($h !== null && abs($h - $w) > 0.0001) {
            throw new PdfException(sprintf(
                'QR code must be square: w (%g) != h (%g)',
                $w, $h,
            ));
        }
        $size = $w;

        // Encode data.
        $encoded = Encoder::encode($this->data, $this->errorCorrection);

        // Convert codewords back to a bit string (interleaved final stream).
        $bits = '';
        foreach ($encoded->finalCodewords as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        // Append remainder bits per ISO 18004 Table 1.
        $remainder = self::remainderBits($encoded->version);
        $bits .= str_repeat('0', $remainder);

        // Build matrix + place data.
        $matrix = Matrix::buildEmpty($encoded->version);
        $matrix->placeData($bits);

        // Try every mask, pick best.
        // Version info bits are identical across all 8 candidates, so they are
        // placed once on $bestModules after the loop to avoid 8x redundant work.
        $bestScore = PHP_INT_MAX;
        $bestModules = $matrix->modules;
        for ($m = 0; $m < 8; $m++) {
            $candidate = Mask::apply($matrix->modules, $matrix->reserved, $m);
            // Format bits MUST be placed before scoring.
            Mask::placeFormatBits($candidate, $this->errorCorrection, $m);
            $score = Mask::score($candidate);
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestModules = $candidate;
            }
        }
        // Version info bits required for V7+.
        if ($encoded->version >= 7) {
            Mask::placeVersionBits($bestModules, $encoded->version);
        }

        // Render: 4-module quiet zone INCLUDED in $size.
        $totalModules = $matrix->size + 8;
        $unit = $page->unit;
        $xPt = $unit->toPoints($x);
        $yPt = $unit->toPoints($y);
        $sizePt = $unit->toPoints($size);
        $modulePt = $sizePt / $totalModules;
        $quietPx = 4 * $modulePt;

        /** @var list<list<bool>> $matrixList */
        $matrixList = array_values(array_map('array_values', $bestModules));
        $body = Renderer::runLengthMatrix(
            $matrixList,
            $xPt + $quietPx,
            $yPt + $quietPx,
            $modulePt,
        );
        $page->contentStream()->append(Renderer::wrap($body, $this->color));
    }

    /** Remainder bits per ISO 18004 Table 1. */
    private static function remainderBits(int $version): int
    {
        if ($version === 1) {
            return 0;
        }
        if ($version <= 6) {
            return 7;
        }
        if ($version <= 13) {
            return 0;
        }
        if ($version <= 20) {
            return 3;
        }
        if ($version <= 27) {
            return 4;
        }
        if ($version <= 34) {
            return 3;
        }
        return 0; // V35-V40
    }
}
