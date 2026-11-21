<?php

declare(strict_types=1);

namespace YetiPdf\Read\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use YetiPdf\Read\Tests\PdfBuilder;

/** Code written against yetidevworks/yetipdf (YetiPdf\YetiPdf and friends) keeps working. */
final class LegacyNamesTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testOldClassNamesStillWork(): void
    {
        $pdf = PdfBuilder::build(['BT /F1 12 Tf 72 720 Td (Hello) Tj ET']);
        $doc = \YetiPdf\YetiPdf::parse($pdf, new \YetiPdf\Options());
        $this->assertInstanceOf(\YetiPdf\Document::class, $doc);
        $this->assertInstanceOf(\YetiPdf\Read\Document::class, $doc);
        $this->assertStringContainsString('Hello', $doc->text());
    }

    #[RunInSeparateProcess]
    public function testOldExceptionNamesAreCaught(): void
    {
        // A catch block doesn't autoload: the old exception names have to exist by the time one runs.
        try {
            \YetiPdf\YetiPdf::parse('not a pdf');
            $this->fail('No exception');
        } catch (\YetiPdf\Exception\PdfException $e) {
            $this->assertInstanceOf(\YetiPdf\Read\Exception\PdfException::class, $e);
        }
    }

    #[RunInSeparateProcess]
    public function testNewNamesDoNotLoadTheOldOnes(): void
    {
        \YetiPdf\Read\YetiPdf::parse(PdfBuilder::build(['BT /F1 12 Tf 72 720 Td (Hi) Tj ET']));
        $this->assertFalse(class_exists('YetiPdf\\YetiPdf', false));
    }
}
