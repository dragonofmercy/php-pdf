<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use DragonOfMercy\PhpPdf\Exception\PdfException;
use DragonOfMercy\PhpPdf\Reader\PdfReader;
use PHPUnit\Framework\TestCase;

/**
 * Mass regression net for the reader: every golden fixture (every PDF this
 * library has ever produced, including signed multi-revision files) must
 * open, expose a catalog, and enumerate its pages. Encrypted fixtures are now
 * opened transparently with their known password and exercise the decryption
 * path rather than being rejected.
 */
final class ReaderCorpusTest extends TestCase
{
    /**
     * Known passwords for encrypted golden fixtures, keyed by basename. The
     * corpus opens these transparently to exercise the decryption path.
     *
     * @var array<string, string>
     */
    private const array ENCRYPTED_FIXTURE_PASSWORDS = [
        'encrypted-document.pdf' => 'user',
    ];

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
            $password = self::ENCRYPTED_FIXTURE_PASSWORDS[basename($file)] ?? null;
            try {
                $reader = PdfReader::fromBytes($bytes, $password);
                $reader->catalog();
                $count = $reader->pageCount();
                self::assertGreaterThanOrEqual(1, $count, $file);
                for ($i = 1; $i <= $count; $i++) {
                    $reader->page($i);
                }
                $parsed++;
                if ($reader->isEncrypted()) {
                    $encrypted++;
                }
            } catch (PdfException $exception) {
                $failures[] = basename(dirname($file)) . '/' . basename($file) . ': ' . $exception->getMessage();
            }
        }
        self::assertSame([], $failures, "Reader failed on:\n" . implode("\n", $failures));
        self::assertGreaterThan(0, $parsed);
        self::assertGreaterThanOrEqual(1, $encrypted, 'expected at least one encrypted fixture to exercise the decryption path');
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
