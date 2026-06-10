<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use PHPUnit\Framework\TestCase;

/**
 * Mass regression net for the reader: every golden fixture (every PDF this
 * library has ever produced, including signed multi-revision files) must
 * open, expose a catalog, and enumerate its pages. Encrypted fixtures must
 * be rejected with the documented encryption error.
 */
final class ReaderCorpusTest extends TestCase
{
    public function testEveryGoldenFixtureParses(): void
    {
        $fixtures = $this->fixtureFiles();
        self::assertGreaterThan(100, count($fixtures), 'fixture glob looks broken');

        $parsed = 0;
        $encrypted = 0;
        $failures = [];
        foreach ($fixtures as $file) {
            $bytes = file_get_contents($file);
            self::assertIsString($bytes, $file);
            try {
                $reader = PdfReader::fromBytes($bytes);
                $reader->catalog();
                $count = $reader->pageCount();
                self::assertGreaterThanOrEqual(1, $count, $file);
                for ($i = 1; $i <= $count; $i++) {
                    $reader->page($i);
                }
                $parsed++;
            } catch (PdfException $exception) {
                if (str_contains($exception->getMessage(), 'Encrypted PDF input')) {
                    $encrypted++;
                    continue;
                }
                $failures[] = basename(dirname($file)) . '/' . basename($file) . ': ' . $exception->getMessage();
            }
        }
        self::assertSame([], $failures, "Reader failed on:\n" . implode("\n", $failures));
        self::assertGreaterThan(0, $parsed);
        self::assertGreaterThanOrEqual(1, $encrypted, 'expected at least one encrypted fixture to exercise the rejection path');
    }

    /** @return list<string> */
    private function fixtureFiles(): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/fixtures', \FilesystemIterator::SKIP_DOTS),
        );
        $files = [];
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && strtolower($file->getExtension()) === 'pdf') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }
}
