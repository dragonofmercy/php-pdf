<?php

declare(strict_types=1);

namespace PhpPdf\Tests\Unit\Document;

use DateTimeImmutable;
use PhpPdf\Document\Metadata;
use PHPUnit\Framework\TestCase;

final class MetadataTest extends TestCase
{
    public function testAllSettersReturnSameInstance(): void
    {
        $m = new Metadata();
        $date = new DateTimeImmutable('2026-01-01T12:00:00Z');

        self::assertSame($m, $m->title('T'));
        self::assertSame($m, $m->author('A'));
        self::assertSame($m, $m->subject('S'));
        self::assertSame($m, $m->keywords('K'));
        self::assertSame($m, $m->creator('C'));
        self::assertSame($m, $m->producer('P'));
        self::assertSame($m, $m->creationDate($date));
        self::assertSame($m, $m->modDate($date));
        self::assertSame($m, $m->trapped(true));
        self::assertSame($m, $m->documentId('abcdef0123456789abcdef0123456789'));
    }

    public function testChainingStoresAllValues(): void
    {
        $date = new DateTimeImmutable('2026-01-01T12:00:00Z');
        $m = (new Metadata())
            ->title('My Title')
            ->author('Jane')
            ->subject('Testing')
            ->keywords('pdf, test')
            ->creator('Suite')
            ->producer('custom-producer')
            ->creationDate($date)
            ->modDate($date)
            ->trapped(false)
            ->documentId('ABCDEF0123456789ABCDEF0123456789');

        self::assertSame('My Title', $m->title);
        self::assertSame('Jane', $m->author);
        self::assertSame('Testing', $m->subject);
        self::assertSame('pdf, test', $m->keywords);
        self::assertSame('Suite', $m->creator);
        self::assertSame('custom-producer', $m->producer);
        self::assertSame($date, $m->creationDate);
        self::assertSame($date, $m->modDate);
        self::assertFalse($m->trapped);
        self::assertSame('ABCDEF0123456789ABCDEF0123456789', $m->documentId);
    }

    public function testDefaultsAreAllNull(): void
    {
        $m = new Metadata();
        self::assertNull($m->title);
        self::assertNull($m->author);
        self::assertNull($m->subject);
        self::assertNull($m->keywords);
        self::assertNull($m->creator);
        self::assertNull($m->producer);
        self::assertNull($m->creationDate);
        self::assertNull($m->modDate);
        self::assertNull($m->trapped);
        self::assertNull($m->documentId);
    }
}
