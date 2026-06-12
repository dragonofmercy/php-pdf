<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Document;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * End-to-end PDF/A acceptance: render the A-2b / A-2u golden documents and have
 * veraPDF (the ISO reference validator) confirm conformance. Auto-skips when the
 * veraPDF CLI jar or its bundled JRE are not present.
 *
 * Tagged `conformance` so it is excluded from the default `composer test` /
 * `composer check` (each veraPDF case spawns a JVM, which is slow and only runs
 * where the oracle is installed). Run it via `composer check:full` /
 * `composer test:conformance`. The fast byte-identity goldens stay in the
 * default run and catch any change to PDF/A output.
 */
#[Group('conformance')]
final class PdfA2ConformanceTest extends TestCase
{
    private const string JAVA = 'C:/tmp/pdfa/jdk-21.0.11+10-jre/bin/java.exe';
    private const string JAR = 'C:/tmp/pdfa/verapdf-cli.jar';
    private const string FONTS_DIR = __DIR__ . '/assets/fonts';

    /**
     * @return array<string, array{0: string, 1: callable(): Document}>
     */
    public static function cases(): array
    {
        return [
            '1b' => ['1b', static fn (): Document => PdfA1bTest::buildDocument()],
            '1a' => ['1a', static fn (): Document => PdfA1aTest::buildDocument()],
            '2b' => ['2b', static fn (): Document => PdfA2bTest::buildDocument()],
            '2u' => ['2u', static fn (): Document => PdfA2uTest::buildDocument()],
            '3b' => ['3b', static fn (): Document => PdfA3bTest::buildDocument()],
            '2a' => ['2a', static fn (): Document => PdfA2aTest::buildDocument()],
            '3a' => ['3a', static fn (): Document => PdfA3aTest::buildDocument()],
            '2a-ua' => ['2a', static fn (): Document => PdfA2aUaTest::buildDocument()],
            'pdfa4' => ['4', static fn (): Document => PdfA4Test::buildDocument()],
            'pdfa4f' => ['4f', static fn (): Document => PdfA4fTest::buildDocument()],
        ];
    }

    /**
     * @param callable(): Document $build
     */
    #[DataProvider('cases')]
    public function testVeraPdfReportsCompliant(string $flavour, callable $build): void
    {
        if (!is_file(self::JAVA) || !is_file(self::JAR)) {
            self::markTestSkipped('veraPDF oracle unavailable');
        }
        if (!is_file(self::FONTS_DIR . '/FreeSans.ttf')) {
            self::markTestSkipped('FreeSans fixtures absent');
        }

        $tmp = (string) tempnam(sys_get_temp_dir(), 'pdfa');
        $pdf = $tmp . '.pdf';
        try {
            file_put_contents($pdf, $build()->output());
            $p = new Process([self::JAVA, '-jar', self::JAR, '--flavour', $flavour, $pdf]);
            $p->run();
            $report = $p->getOutput();
            self::assertStringContainsString(
                'isCompliant="true"',
                $report,
                "veraPDF reported non-compliance for flavour {$flavour}:\n" . $report . $p->getErrorOutput(),
            );
        } finally {
            @unlink($pdf);
            @unlink($tmp);
        }
    }
}
