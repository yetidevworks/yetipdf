<?php

declare(strict_types=1);

namespace YetiPdf\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YetiPdf\Exception\InvalidPdfException;
use YetiPdf\Tests\PdfBuilder;
use YetiPdf\YetiPdf;

final class ExtractionTest extends TestCase
{
    public function testSimpleTextAndPageOrder(): void
    {
        $pdf = PdfBuilder::build([
            'BT /F1 12 Tf 72 720 Td (Hello world) Tj ET',
            'BT /F1 12 Tf 72 720 Td (Second page) Tj ET',
        ]);
        $doc = YetiPdf::parse($pdf);

        $this->assertSame(2, $doc->pageCount());
        $this->assertSame([1 => 'Hello world', 2 => 'Second page'], iterator_to_array($doc->pages()));
        $this->assertSame('Second page', $doc->page(2));
    }

    public function testCompressedStreams(): void
    {
        $pdf = PdfBuilder::build(['BT /F1 12 Tf 72 720 Td (Deflated) Tj ET'], compress: true);
        $this->assertSame('Deflated', YetiPdf::parse($pdf)->text());
    }

    public function testKerningDoesNotSplitWordsButGapsDo(): void
    {
        // Small adjustments are kerning; -300 is a third of an em, which is a word space.
        $pdf = PdfBuilder::build(['BT /F1 10 Tf 72 720 Td [(W)80(or)-15(ld)-300(pe)10(ace)] TJ ET']);
        $this->assertSame('World peace', YetiPdf::parse($pdf)->text());
    }

    public function testSeparatelyPositionedStringsOnOneLine(): void
    {
        // "Hello" is 22.78 wide at 10pt, so the second string starts 5pt after it ends: a space.
        // The third starts exactly where "Hello world" would continue: no space.
        $pdf = PdfBuilder::build(['BT /F1 10 Tf 1 0 0 1 72 720 Tm (Hello) Tj 1 0 0 1 99.78 720 Tm (wor) Tj 1 0 0 1 113.67 720 Tm (ld) Tj ET']);
        $this->assertSame('Hello world', YetiPdf::parse($pdf)->text());
    }

    public function testLineBreaksAndDehyphenation(): void
    {
        $pdf = PdfBuilder::build(['BT /F1 10 Tf 12 TL 72 720 Td (An exam-) Tj T* (ple of text) Tj T* (Next line) Tj ET']);
        $this->assertSame("An example of text\nNext line", YetiPdf::parse($pdf)->text());
    }

    public function testEscapesAndHexStrings(): void
    {
        $pdf = PdfBuilder::build(['BT /F1 10 Tf 72 720 Td (a \(nested\) \101\\\\) Tj <20486921> Tj ET']);
        $this->assertSame('a (nested) A\\ Hi!', YetiPdf::parse($pdf)->text());
    }

    public function testToUnicodeWinsAndBadSectionsAreIgnored(): void
    {
        $cmap = "/CIDInit /ProcSet findresource begin begincmap\n"
            . "1 begincodespacerange <00> <FF> endcodespacerange\n"
            . "2 beginbfchar <41> <0416> <42> <00660069> endbfchar\n"
            . "1 beginbfrange <43> <45> <0061> endbfrange\n"
            . "1 beginbfrange <50> <51> [<0031> <0032>] endbfrange\n"
            . "1 beginbfrange <60> garbage endbfrange\nendcmap";
        $pdf = PdfBuilder::build(
            ['BT /F1 10 Tf 72 720 Td (ABCDEPQ) Tj ET'],
            ['F1' => '/Subtype /TrueType /BaseFont /Custom /ToUnicode 100 0 R'],
            [100 => PdfBuilder::stream($cmap)]
        );
        $this->assertSame('Жfiabc12', YetiPdf::parse($pdf)->text());
    }

    public function testDifferencesEncoding(): void
    {
        $pdf = PdfBuilder::build(
            ['BT /F1 10 Tf 72 720 Td (\001\002x) Tj ET'],
            ['F1' => '/Subtype /Type1 /BaseFont /Custom /Encoding << /Type /Encoding /Differences [1 /Eacute /f_f_i] >>']
        );
        $this->assertSame('Éffix', YetiPdf::parse($pdf)->text());
    }

    public function testCompositeFontWithTwoByteCodes(): void
    {
        $cmap = "1 begincodespacerange <0000> <FFFF> endcodespacerange\n2 beginbfchar <0001> <65E5> <0002> <672C> endbfchar";
        $pdf = PdfBuilder::build(
            ['BT /F1 10 Tf 72 720 Td <00010002> Tj ET'],
            ['F1' => '/Subtype /Type0 /BaseFont /Custom /Encoding /Identity-H /DescendantFonts [101 0 R] /ToUnicode 100 0 R'],
            [100 => PdfBuilder::stream($cmap), 101 => '<< /Type /Font /Subtype /CIDFontType2 /DW 1000 >>']
        );
        $this->assertSame('日本', YetiPdf::parse($pdf)->text());
    }

    public function testToUnicodeCompressedWithoutDeclaringTheFilter(): void
    {
        $cmap = "1 begincodespacerange <0000> <FFFF> endcodespacerange\n2 beginbfchar <0001> <65E5> <0002> <672C> endbfchar";
        $pdf = PdfBuilder::build(
            ['BT /F1 10 Tf 72 720 Td <00010002> Tj ET'],
            ['F1' => '/Subtype /Type0 /BaseFont /Custom /Encoding /Identity-H /DescendantFonts [101 0 R] /ToUnicode 100 0 R'],
            [100 => PdfBuilder::stream((string)gzcompress($cmap)), 101 => '<< /Type /Font /Subtype /CIDFontType2 /DW 1000 >>']
        );
        $doc = YetiPdf::parse($pdf);

        $this->assertSame('日本', $doc->text());
        $this->assertSame([], $doc->warnings());
    }

    public function testAccentDrawnOverLetterIsComposed(): void
    {
        // How TeX draws "é": the acute accent, then back up and draw the letter under it.
        $pdf = PdfBuilder::build(['BT /F1 10 Tf 72 720 Td [(caf\264)333(e)] TJ ET']);
        $this->assertSame('café', YetiPdf::parse($pdf)->text());
    }

    public function testAccentedLetterAtTheStartOfALineStaysOnThatLine(): void
    {
        // The accent is the first thing drawn on the new line. Taking it back to put it on its letter must not take the line break with it.
        $pdf = PdfBuilder::build(['BT /F1 10 Tf 72 720 Td (the end) Tj 0 -12 Td [(\264)333(ecole is out)] TJ ET']);
        $this->assertSame("the end\nécole is out", YetiPdf::parse($pdf)->text());
    }

    public function testFormXObjectTextIsIncluded(): void
    {
        $form = PdfBuilder::stream('BT /F1 10 Tf 0 0 Td (inside form) Tj ET', false, '/Type /XObject /Subtype /Form /BBox [0 0 100 100] /Resources << /Font << /F1 10 0 R >> >>');
        $pdf = PdfBuilder::build(['BT /F1 10 Tf 72 720 Td (page) Tj ET q 1 0 0 1 72 600 cm /Fm1 Do Q'], [], [100 => $form]);
        $pdf = str_replace('/Resources << /Font << /F1 10 0 R >> >> /Contents', '/Resources << /Font << /F1 10 0 R >> /XObject << /Fm1 100 0 R >> >> /Contents', $pdf);
        $this->assertSame("page\ninside form", YetiPdf::parse($pdf)->text());
    }

    /** A one-page file ("still read") ending in a cross-reference stream with the given dictionary entries and rows. */
    private static function withXrefStream(string $entries, callable $rows): string
    {
        $content = 'BT /F1 12 Tf 72 720 Td (still read) Tj ET';
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            4 => '<< /Length ' . strlen($content) . " >>\nstream\n$content\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.5\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "$number 0 obj\n$body\nendobj\n";
        }
        $data = $rows($offsets);
        $at = strlen($pdf);
        return $pdf . "6 0 obj\n<< /Type /XRef /Root 1 0 R $entries /Length " . strlen($data) . " >>\nstream\n$data\nendstream\nendobj\nstartxref\n$at\n%%EOF\n";
    }

    /** @return iterable<string, array{string}> */
    public static function brokenXrefStreams(): iterable
    {
        yield 'a width that is a string' => ['/Size 7 /W [<01> 2 1]'];
        yield 'another width that is a string' => ['/Size 7 /W [1 (x) 1]'];
        yield 'a negative width' => ['/Size 7 /W [-1 3 1]'];
        yield 'a width no number has' => ['/Size 7 /W [1 9 1]'];
        yield 'an index that is not numbers' => ['/Size 7 /W [1 2 1] /Index [(a) 3]'];
        yield 'a size that is not a number' => ['/Size (big) /W [1 2 1]'];
    }

    #[DataProvider('brokenXrefStreams')]
    public function testCrossReferenceStreamThatCannotBeReadRaisesNothing(string $entries): void
    {
        $pdf = self::withXrefStream($entries, static fn(array $offsets): string => str_repeat("\x01\x00\x10\x00", 6));
        // Recorded, not thrown: the reader catches what is thrown while it reads the table, and would hide it.
        $raised = [];
        set_error_handler(static function (int $no, string $message) use (&$raised): bool {
            $raised[] = $message;
            return true;
        });
        try {
            $text = YetiPdf::parse($pdf)->text();
        } finally {
            restore_error_handler();
        }
        $this->assertSame([], $raised);
        $this->assertSame('still read', $text);
    }

    public function testTableWithNoEntryForTheCatalogIsRebuilt(): void
    {
        // A well-formed table that lists the content stream and nothing else. It is not empty, so it used to be believed.
        $pdf = self::withXrefStream('/Size 7 /W [1 2 1] /Index [4 1]', static fn(array $offsets): string => "\x01" . pack('n', $offsets[4]) . "\x00");
        $doc = YetiPdf::parse($pdf);

        $this->assertSame('still read', $doc->text());
        $this->assertSame(['Cross-reference table was missing or damaged; rebuilt by scanning the file'], $doc->warnings());
    }

    public function testDamagedCrossReferenceTableIsRebuilt(): void
    {
        $pdf = PdfBuilder::build(['BT /F1 12 Tf 72 720 Td (Still readable) Tj ET']);
        $broken = preg_replace('/startxref\s+\d+/', "startxref\n999999", $pdf);
        $doc = YetiPdf::parse((string)$broken);

        $this->assertSame('Still readable', $doc->text());
        $this->assertNotEmpty($doc->warnings());
    }

    public function testWrongStreamLengthIsTolerated(): void
    {
        $pdf = PdfBuilder::build(['BT /F1 12 Tf 72 720 Td (Length is wrong) Tj ET']);
        $pdf = preg_replace('/\/Length \d+/', '/Length 3', $pdf);
        $this->assertSame('Length is wrong', YetiPdf::parse((string)$pdf)->text());
    }

    public function testDrawingOperatorsAndInlineImagesAreSkipped(): void
    {
        $content = "0.5 w 10 10 m 200 200 l S 1 0 0 rg 5 5 50 50 re f\n"
            . "BI /W 2 /H 2 /BPC 8 /CS /G ID \x00(Tj\xFF EI\n"
            . 'BT /F1 10 Tf 72 720 Td (text) Tj ET';
        $this->assertSame('text', YetiPdf::parse(PdfBuilder::build([$content]))->text());
    }

    public function testLongDrawingNumberRunDoesNotConsumeTextOperands(): void
    {
        $content = 'BT /F1 10 Tf 72 720 Td (before) Tj ET '
            . str_repeat('1 ', 8000) . 'm '
            . "BT /F1 10 Tf 1\t0 0\n1 72 700 Tm [(after)-300(path)] TJ ET";
        $this->assertSame("before\nafter path", YetiPdf::parse(PdfBuilder::build([$content]))->text());
    }

    public function testUnclosedInlineImageHeadersDoNotHideLaterText(): void
    {
        $content = "BI /W 1 /H 1 ID \x00 EI\n"
            . str_repeat('BI /W 1 ', 8000) . 'ID/ '
            . 'BT /F1 10 Tf 72 720 Td (still readable) Tj ET';
        $this->assertSame('still readable', YetiPdf::parse(PdfBuilder::build([$content]))->text());
    }

    public function testNotAPdf(): void
    {
        $this->expectException(InvalidPdfException::class);
        YetiPdf::parse('just some text');
    }

    public function testSinglePageMatchesIterationAndOutOfRangeIsEmpty(): void
    {
        $pdf = PdfBuilder::build([
            'BT /F1 12 Tf 72 720 Td (One) Tj ET',
            'BT /F1 12 Tf 72 720 Td (Two) Tj ET',
            'BT /F1 12 Tf 72 720 Td (Three) Tj ET',
        ]);
        $doc = YetiPdf::parse($pdf);

        foreach (iterator_to_array($doc->pages()) as $number => $text) {
            $this->assertSame($text, $doc->page($number));
        }
        $this->assertSame('', $doc->page(0));
        $this->assertSame('', $doc->page(4));
    }

    public function testGlyphWithAWidthButNoCharacterSeparatesWords(): void
    {
        // Code 2 is the font's space glyph under a made-up name, which nothing maps to a character.
        $widths = implode(' ', array_fill(0, 126, 500));
        $font = "/Subtype /Type1 /BaseFont /Custom /FirstChar 0 /Widths [0 0 250 $widths] /Encoding << /BaseEncoding /WinAnsiEncoding /Differences [2 /g3] >>";
        $pdf = PdfBuilder::build(["BT /F1 10 Tf 72 720 Td (Perceived\002Ease\002of\002Use) Tj ET"], ['F1' => $font]);
        $this->assertSame('Perceived Ease of Use', YetiPdf::parse($pdf)->text());

        // On its own it adds nothing: the positions of the strings around it already tell the words apart.
        $pdf = PdfBuilder::build(["BT /F1 10 Tf 72 720 Td (One) Tj (\002) Tj (Two) Tj 0 -12 Td (\002) Tj 0 -12 Td (Three) Tj ET"], ['F1' => $font]);
        $this->assertSame("One Two\nThree", YetiPdf::parse($pdf)->text());

        // A code with no width is not a gap.
        $pdf = PdfBuilder::build(["BT /F1 10 Tf 72 720 Td (No\001gap) Tj ET"], ['F1' => $font]);
        $this->assertSame('Nogap', YetiPdf::parse($pdf)->text());
    }

    public function testStampTextIsReadAndLinksAreNot(): void
    {
        $form = '/Type /XObject /Subtype /Form /BBox [0 0 100 20] /Resources << /Font << /F1 10 0 R >> >>';
        $pdf = PdfBuilder::build(['BT /F1 12 Tf 72 720 Td (Body) Tj ET'], [], [
            100 => '<< /Type /Annot /Subtype /Stamp /Rect [72 600 172 620] /AP << /N 101 0 R >> >>',
            101 => PdfBuilder::stream('BT /F1 10 Tf 2 5 Td (APPROVED) Tj ET', false, $form),
            102 => '<< /Type /Annot /Subtype /Link /Rect [72 500 172 520] /AP << /N 103 0 R >> >>',
            103 => PdfBuilder::stream('BT /F1 10 Tf 2 5 Td (hidden) Tj ET', false, $form),
        ]);
        $pdf = str_replace('/Contents 31 0 R', '/Contents 31 0 R /Annots [100 0 R 102 0 R]', $pdf);

        $this->assertSame("Body\nAPPROVED", YetiPdf::parse($pdf)->text());
    }
}
