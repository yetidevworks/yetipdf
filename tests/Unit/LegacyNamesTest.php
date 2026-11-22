<?php

declare(strict_types=1);

namespace YetiPdf\Read\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use YetiPdf\Read\Tests\PdfBuilder;

/**
 * Code written against yetidevworks/yetipdf 1.2 (classes directly under YetiPdf\), or against 1.3.0's
 * YetiPdf\Read\YetiPdf entry point, keeps working,
 * whichever name a class is first loaded under. Each test runs in a fresh process, since what's
 * loaded already decides what an alias has to cover.
 */
final class LegacyNamesTest extends TestCase
{
    private static function pdf(string $text = 'Hello'): string
    {
        return PdfBuilder::build(["BT /F1 12 Tf 72 720 Td ($text) Tj ET"]);
    }

    #[RunInSeparateProcess]
    public function testOldClassNamesStillWork(): void
    {
        $doc = \YetiPdf\YetiPdf::parse(self::pdf(), new \YetiPdf\Options());
        $this->assertInstanceOf(\YetiPdf\Document::class, $doc);
        $this->assertInstanceOf(\YetiPdf\Read\Document::class, $doc);
        $this->assertStringContainsString('Hello', $doc->text());
    }

    #[RunInSeparateProcess]
    public function testOldConstructorsAndInnerTypes(): void
    {
        $doc = new \YetiPdf\Document(new \YetiPdf\Core\File(self::pdf()), new \YetiPdf\Options());
        $this->assertStringContainsString('Hello', $doc->text());
    }

    #[RunInSeparateProcess]
    public function testOldExceptionNamesAreCaught(): void
    {
        try {
            \YetiPdf\YetiPdf::parse('not a pdf');
            $this->fail('No exception');
        } catch (\YetiPdf\Exception\PdfException $e) {
            $this->assertInstanceOf(\YetiPdf\Read\Exception\PdfException::class, $e);
        }
    }

    #[RunInSeparateProcess]
    public function testOldNamesMatchObjectsMadeUnderNewNames(): void
    {
        // Nothing here asks for an old name, so nothing autoloads one: the aliases have to be there already.
        $doc = \YetiPdf\Read\YetiPdf::parse(self::pdf());
        $this->assertTrue($doc instanceof \YetiPdf\Document);
        $consume = fn (\YetiPdf\Document $d): \YetiPdf\Document => $d;
        $this->assertSame($doc, $consume($doc));
        try {
            \YetiPdf\Read\YetiPdf::parse('not a pdf');
            $this->fail('No exception');
        } catch (\YetiPdf\Exception\InvalidPdfException|\YetiPdf\Exception\EncryptedException $e) {
            $this->assertInstanceOf(\YetiPdf\Read\Exception\InvalidPdfException::class, $e);
        }
    }

    #[RunInSeparateProcess]
    public function testEveryTypeKeepsItsOldName(): void
    {
        $src = dirname(__DIR__, 2) . '/src';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        $count = 0;
        foreach ($it as $file) {
            $rel = substr($file->getPathname(), strlen($src) + 1, -4);
            if ($rel === 'legacy') {
                continue;
            }
            // The entry point was renamed in 1.3.1 as well as moved, from YetiPdf to YetiPdfRead.
            $old = 'YetiPdf\\' . str_replace('/', '\\', $rel === 'YetiPdfRead' ? 'YetiPdf' : $rel);
            $this->assertTrue(class_exists($old) || interface_exists($old), $old);
            $this->assertSame('YetiPdf\\Read\\' . str_replace('/', '\\', $rel), (new \ReflectionClass($old))->getName());
            $count++;
        }
        $this->assertSame(27, $count);
    }

    #[RunInSeparateProcess]
    public function testTheOneThreeZeroEntryPointNameStillWorks(): void
    {
        // 1.3.0 called the entry point YetiPdf\Read\YetiPdf. Asked for first, it has to load YetiPdfRead.
        $doc = \YetiPdf\Read\YetiPdf::parse(self::pdf('Renamed'));
        $this->assertStringContainsString('Renamed', $doc->text());
        $this->assertSame(\YetiPdf\Read\YetiPdfRead::class, (new \ReflectionClass(\YetiPdf\Read\YetiPdf::class))->getName());
        $this->assertSame(\YetiPdf\Read\YetiPdfRead::class, (new \ReflectionClass(\YetiPdf\YetiPdf::class))->getName());
    }

    #[RunInSeparateProcess]
    public function testOldEntryPointNamesIgnoreCase(): void
    {
        // PHP class names ignore case, so code that spells an old name in another case still has to find it.
        $this->assertTrue(class_exists('yetipdf\\read\\yetipdf'));
        $this->assertSame(\YetiPdf\Read\YetiPdfRead::class, (new \ReflectionClass('YETIPDF\\YETIPDF'))->getName());
    }

    #[RunInSeparateProcess]
    public function testEntryPointNamesAreOneClass(): void
    {
        // Loaded under its new name first, both old names are already there without autoloading.
        \YetiPdf\Read\YetiPdfRead::parse(self::pdf());
        $this->assertTrue(class_exists('YetiPdf\\YetiPdf', false));
        $this->assertTrue(class_exists('YetiPdf\\Read\\YetiPdf', false));
        $this->assertTrue(is_a(\YetiPdf\Read\YetiPdf::class, \YetiPdf\Read\YetiPdfRead::class, true));
        $this->assertTrue(is_a(\YetiPdf\YetiPdf::class, \YetiPdf\Read\YetiPdfRead::class, true));
    }

    #[RunInSeparateProcess]
    public function testOldInterfaceCanBeImplemented(): void
    {
        $map = new class implements \YetiPdf\Font\CodeMap {
            public function get(int $code): ?string
            {
                return null;
            }
        };
        $this->assertInstanceOf(\YetiPdf\Read\Font\CodeMap::class, $map);
    }
}
