<?php

declare(strict_types=1);

namespace DragonOfMercy\PhpPdf\Tests\Golden;

use PHPUnit\Framework\TestCase;

/**
 * Acceptance gate for Tagged PDF Phase 2: the shared UA document built by
 * TaggingGoldenTest::buildUaDocument() must validate as PDF/UA-1 compliant
 * under the veraPDF CLI oracle. Auto-skips when the oracle is absent.
 */
final class VeraPdfUa1Test extends TestCase
{
    private const string JAVA = 'C:\\tmp\\pdfa\\jdk-21.0.11+10-jre\\bin\\java.exe';
    private const string JAR = 'C:\\tmp\\pdfa\\verapdf-cli.jar';

    public function testUaDocumentIsPdfUa1Compliant(): void
    {
        if (!is_file(self::JAVA) || !is_file(self::JAR)) {
            self::markTestSkipped('veraPDF oracle not installed (C:\\tmp\\pdfa).');
        }

        $pdf = TaggingGoldenTest::buildUaDocument()->output();
        $tmp = tempnam(sys_get_temp_dir(), 'ua') . '.pdf';
        file_put_contents($tmp, $pdf);

        $cmd = sprintf('"%s" -jar "%s" --flavour ua1 "%s" 2>&1', self::JAVA, self::JAR, $tmp);
        $xml = (string) shell_exec($cmd);
        @unlink($tmp);

        self::assertMatchesRegularExpression(
            '/isCompliant="true"/',
            $xml,
            'veraPDF ua1 report was not compliant: ' . $xml,
        );
    }

    public function testUaLinksDocumentIsPdfUa1Compliant(): void
    {
        if (!is_file(self::JAVA) || !is_file(self::JAR)) {
            self::markTestSkipped('veraPDF oracle not installed (C:\\tmp\\pdfa).');
        }

        $pdf = TaggingGoldenTest::buildUaLinksDocument()->output();
        $tmp = tempnam(sys_get_temp_dir(), 'ua') . '.pdf';
        file_put_contents($tmp, $pdf);

        $cmd = sprintf('"%s" -jar "%s" --flavour ua1 "%s" 2>&1', self::JAVA, self::JAR, $tmp);
        $xml = (string) shell_exec($cmd);
        @unlink($tmp);

        self::assertMatchesRegularExpression(
            '/isCompliant="true"/',
            $xml,
            'veraPDF ua1 report was not compliant: ' . $xml,
        );
    }

    public function testUaMarkdownLinksDocumentIsPdfUa1Compliant(): void
    {
        if (!is_file(self::JAVA) || !is_file(self::JAR)) {
            self::markTestSkipped('veraPDF oracle not installed (C:\\tmp\\pdfa).');
        }

        $pdf = TaggingGoldenTest::buildUaMarkdownLinks()->output();
        $tmp = tempnam(sys_get_temp_dir(), 'ua') . '.pdf';
        file_put_contents($tmp, $pdf);

        $cmd = sprintf('"%s" -jar "%s" --flavour ua1 "%s" 2>&1', self::JAVA, self::JAR, $tmp);
        $xml = (string) shell_exec($cmd);
        @unlink($tmp);

        self::assertMatchesRegularExpression(
            '/isCompliant="true"/',
            $xml,
            'veraPDF ua1 report was not compliant: ' . $xml,
        );
    }

    public function testUaImageLinksDocumentIsPdfUa1Compliant(): void
    {
        if (!is_file(self::JAVA) || !is_file(self::JAR)) {
            self::markTestSkipped('veraPDF oracle not installed (C:\\tmp\\pdfa).');
        }

        $pdf = TaggingGoldenTest::buildUaImageLinks()->output();
        $tmp = tempnam(sys_get_temp_dir(), 'ua') . '.pdf';
        file_put_contents($tmp, $pdf);

        $cmd = sprintf('"%s" -jar "%s" --flavour ua1 "%s" 2>&1', self::JAVA, self::JAR, $tmp);
        $xml = (string) shell_exec($cmd);
        @unlink($tmp);

        self::assertMatchesRegularExpression(
            '/isCompliant="true"/',
            $xml,
            'veraPDF ua1 report was not compliant: ' . $xml,
        );
    }

    public function testPdfA2aUaDocumentIsPdfUa1Compliant(): void
    {
        if (!is_file(self::JAVA) || !is_file(self::JAR)) {
            self::markTestSkipped('veraPDF oracle not installed (C:\\tmp\\pdfa).');
        }

        $pdf = PdfA2aUaTest::buildDocument()->output();
        $tmp = tempnam(sys_get_temp_dir(), 'ua') . '.pdf';
        file_put_contents($tmp, $pdf);

        $cmd = sprintf('"%s" -jar "%s" --flavour ua1 "%s" 2>&1', self::JAVA, self::JAR, $tmp);
        $xml = (string) shell_exec($cmd);
        @unlink($tmp);

        self::assertMatchesRegularExpression(
            '/isCompliant="true"/',
            $xml,
            'veraPDF ua1 report was not compliant: ' . $xml,
        );
    }
}
