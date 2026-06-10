<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Reader\PdfReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Exercises the reader against format variants the library itself never
 * writes: cross-reference streams + object streams and linearized files,
 * produced by qpdf from our own fixtures. Skips when qpdf is not on PATH.
 */
final class ReaderQpdfVariantsTest extends TestCase
{
    private const array SOURCE_FIXTURES = [
        __DIR__ . '/fixtures/doc/empty-page.pdf',
        __DIR__ . '/fixtures/page/graphics.pdf',
        __DIR__ . '/fixtures/page/text.pdf',
        __DIR__ . '/fixtures/page/cells.pdf',
    ];

    /** @return iterable<string, array{string, list<string>}> */
    public static function variants(): iterable
    {
        foreach (self::SOURCE_FIXTURES as $fixture) {
            $name = basename($fixture, '.pdf');
            yield "{$name} object-streams" => [$fixture, ['--object-streams=generate']];
            yield "{$name} classic" => [$fixture, ['--object-streams=disable']];
            yield "{$name} linearized" => [$fixture, ['--linearize']];
        }
    }

    /** @param list<string> $options */
    #[DataProvider('variants')]
    public function testVariantParsesWithSamePageCount(string $fixture, array $options): void
    {
        $qpdf = (new ExecutableFinder())->find('qpdf');
        if ($qpdf === null) {
            self::markTestSkipped('qpdf is not installed; skipping variant generation.');
        }

        $original = PdfReader::fromFile($fixture);
        $variant = tempnam(sys_get_temp_dir(), 'phppdf_variant_');
        self::assertIsString($variant);
        try {
            $process = new Process([$qpdf, ...$options, $fixture, $variant]);
            $process->run();
            self::assertSame(0, $process->getExitCode(), 'qpdf failed: ' . $process->getErrorOutput());

            $reader = PdfReader::fromFile($variant);
            self::assertSame($original->pageCount(), $reader->pageCount());
            $reader->catalog();
            $reader->page(1);
        } finally {
            @unlink($variant);
        }
    }
}
