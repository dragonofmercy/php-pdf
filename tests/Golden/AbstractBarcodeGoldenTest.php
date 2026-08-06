<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Tests\Support\Qpdf;
use PHPUnit\Framework\TestCase;

/**
 * Common machinery for the per-format barcode golden tests:
 * - byte-by-byte fixture comparison
 * - qpdf --check structural validation (skipped when qpdf is absent)
 *
 * Each subclass implements {@see fixturePath()} and {@see buildPdfBytes()}.
 */
abstract class AbstractBarcodeGoldenTest extends TestCase
{
    abstract protected function fixturePath(): string;

    abstract protected function buildPdfBytes(): string;

    public function testMatchesFixtureBytes(): void
    {
        $expected = file_get_contents($this->fixturePath());
        self::assertIsString($expected);
        self::assertSame(
            $expected,
            $this->buildPdfBytes(),
            'Output diverges from fixture. If the change is intentional, run: php tests/Golden/regenerate.php',
        );
    }

    public function testPassesQpdfCheck(): void
    {
        Qpdf::assertCheck($this->fixturePath());
    }
}
