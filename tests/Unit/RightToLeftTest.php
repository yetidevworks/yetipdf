<?php

declare(strict_types=1);

namespace YetiPdf\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YetiPdf\Content\Bidi;
use YetiPdf\Tests\PdfBuilder;
use YetiPdf\YetiPdf;

final class RightToLeftTest extends TestCase
{
    /**
     * The same letters, last one first: how a right-to-left word sits on the page when read from the left.
     * The lines below are put together from pieces, because an editor reorders mixed text on screen and
     * what is typed stops matching what is stored.
     */
    private static function r(string $word): string
    {
        return implode('', array_reverse(preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }

    /** @return iterable<string, array{string, string}> the line as it is drawn, left to right, and as it is read */
    public static function lines(): iterable
    {
        yield 'Hebrew' => [self::r('עולם') . ' ' . self::r('שלום'), 'שלום' . ' ' . 'עולם'];
        yield 'punctuation stays at the end' => ['?' . self::r('שלום'), 'שלום' . '?'];
        yield 'a number keeps its digits in order' => ['2014 ' . self::r('בשנת'), 'בשנת' . ' 2014'];
        yield 'numbers with separators' => [
            '12:50 ' . self::r('בשעה') . ' 2023-06-15 ' . self::r('ביום'),
            'ביום' . ' 2023-06-15 ' . 'בשעה' . ' 12:50',
        ];
        yield 'a Latin phrase stays whole' => [
            self::r('בקובץ') . ' Hello World ' . self::r('כתוב'),
            'כתוב' . ' Hello World ' . 'בקובץ',
        ];
        yield 'a word and its number' => [self::r('תקן') . ' ISO 9001 ' . self::r('לפי'), 'לפי' . ' ISO 9001 ' . 'תקן'];
        yield 'brackets named by what they mean' => [')4()' . 'ב' . '(15 ' . self::r('סעיף'), 'סעיף' . ' 15(' . 'ב' . ')(4)'];
        yield 'brackets named by the way they face' => ['(4)(' . 'ב' . ')15 ' . self::r('סעיף'), 'סעיף' . ' 15(' . 'ב' . ')(4)'];
        yield 'Hebrew inside an English line' => ['The word ' . self::r('שלום') . ' means peace', 'The word ' . 'שלום' . ' means peace'];
        yield 'a Hebrew phrase inside an English line' => [
            'It says ' . self::r('עולם') . ' ' . self::r('שלום') . ' on the door',
            'It says ' . 'שלום' . ' ' . 'עולם' . ' on the door',
        ];
        // U+FEE1 meem, U+FEFC lam-alef in one glyph, U+FEB3 seen: "salam" as a PDF tends to spell it.
        yield 'Arabic presentation forms become letters' => ["\u{FEE1}\u{FEFC}\u{FEB3}", "\u{0633}\u{0644}\u{0627}\u{0645}"];
        yield 'Arabic-Indic digits read left to right' => [
            "\u{0661}\u{0662}\u{0663} \u{0645}\u{0642}\u{0631}",
            "\u{0631}\u{0642}\u{0645} \u{0661}\u{0662}\u{0663}",
        ];
        yield 'nothing right-to-left' => ['Hello, world 42', 'Hello, world 42'];
    }

    #[DataProvider('lines')]
    public function testVisualLineBecomesLogical(string $drawn, string $read): void
    {
        $this->assertSame($read, Bidi::logical($drawn));
    }

    /**
     * A font whose letters a-f are the first six Hebrew letters, 500 units wide, plus space and digits.
     * "g" is one glyph for the two letters alef and bet, and "h" is the vowel mark qamats.
     */
    private static function hebrewFont(): array
    {
        $cmap = "1 begincodespacerange <00> <FF> endcodespacerange\n"
            . "3 beginbfrange <61> <66> <05D0> <30> <39> <0030> <20> <20> <0020> endbfrange\n"
            . "2 beginbfchar <67> <05D005D1> <68> <05B8> endbfchar";
        $widths = implode(' ', array_fill(0, 96, 500));
        return [
            ['F1' => "/Subtype /TrueType /BaseFont /Hebrew /FirstChar 32 /Widths [$widths] /ToUnicode 100 0 R"],
            [100 => PdfBuilder::stream($cmap)],
        ];
    }

    public function testOneStringDrawnLeftToRight(): void
    {
        [$fonts, $extra] = self::hebrewFont();
        // "cba fed" is how the two words look on the page; the reader starts at the right.
        $pdf = PdfBuilder::build(['BT /F1 10 Tf 72 720 Td (fed cba) Tj ET'], $fonts, $extra);
        $this->assertSame('אבג דהו', YetiPdf::parse($pdf)->text());
    }

    public function testPiecesArePlacedByPositionWhateverOrderTheyAreDrawnIn(): void
    {
        [$fonts, $extra] = self::hebrewFont();
        // Word writes the rightmost run first; a browser writes letter by letter from the left. Both must read the same.
        $rightFirst = 'BT /F1 10 Tf 1 0 0 1 112 720 Tm (cba) Tj 1 0 0 1 92 720 Tm (12) Tj 1 0 0 1 72 720 Tm (fed) Tj ET';
        $leftFirst = 'BT /F1 10 Tf 1 0 0 1 72 720 Tm (f) Tj (e) Tj (d) Tj 1 0 0 1 92 720 Tm (1) Tj (2) Tj 1 0 0 1 112 720 Tm (c) Tj (b) Tj (a) Tj ET';
        foreach ([$rightFirst, $leftFirst] as $content) {
            $this->assertSame('אבג 12 דהו', YetiPdf::parse(PdfBuilder::build([$content], $fonts, $extra))->text());
        }
    }

    public function testStringRunningBackwardsThroughNegativeSpacing(): void
    {
        [$fonts, $extra] = self::hebrewFont();
        // Each letter is 5 wide and the spacing takes 10 off, so the string is in reading order and runs to the left.
        $pdf = PdfBuilder::build(['BT /F1 10 Tf -10 Tc 1 0 0 1 200 720 Tm (abc def) Tj ET'], $fonts, $extra);
        $this->assertSame('אבג דהו', YetiPdf::parse($pdf)->text());
    }

    public function testLineDrawnInTwoGoesIsJoined(): void
    {
        [$fonts, $extra] = self::hebrewFont();
        // A print driver draws the left end of both lines, then comes back for the right end of each.
        $pdf = PdfBuilder::build([
            'BT /F1 10 Tf 1 0 0 1 72 720 Tm (fed ) Tj 1 0 0 1 72 700 Tm (cba ) Tj'
            . ' 1 0 0 1 92 720 Tm (cba) Tj 1 0 0 1 92 700 Tm (fed) Tj ET',
        ], $fonts, $extra);
        $this->assertSame("אבג דהו\nדהו אבג", YetiPdf::parse($pdf)->text());
    }

    public function testLettersOfOneGlyphStayInReadingOrder(): void
    {
        [$fonts, $extra] = self::hebrewFont();
        // The glyph for alef and bet together is drawn to the left of gimel, so it is read after it. A font
        // lists the letters of such a glyph in reading order already, and they must not be turned around with the line.
        $pdf = PdfBuilder::build(['BT /F1 10 Tf 72 720 Td (gc) Tj ET'], $fonts, $extra);
        $this->assertSame('ג' . 'א' . 'ב', YetiPdf::parse($pdf)->text());
    }

    public function testLetterDrawnOnTopOfItsVowelMarkLeavesNoGap(): void
    {
        [$fonts, $extra] = self::hebrewFont();
        // The mark and bet are one string, with spacing that takes back all they would move. Alef comes
        // one letter further along, which is no gap at all.
        $pdf = PdfBuilder::build([
            'BT /F1 10 Tf 1 0 0 1 72 720 Tm (c) Tj -5 Tc 1 0 0 1 77 720 Tm (hb) Tj 0 Tc 1 0 0 1 82 720 Tm (a) Tj ET',
        ], $fonts, $extra);
        $this->assertSame('א' . 'ב' . "\u{05B8}" . 'ג', YetiPdf::parse($pdf)->text());
    }

    public function testVowelMarkDrawnOnItsOwnJoinsTheLetterItSitsOn(): void
    {
        [$fonts, $extra] = self::hebrewFont();
        // The mark is as wide as a letter and starts a hair to one side of bet or the other. Either way it is over bet.
        foreach (['76.8', '77.3'] as $x) {
            $pdf = PdfBuilder::build([
                "BT /F1 10 Tf 1 0 0 1 72 720 Tm (c) Tj 1 0 0 1 $x 720 Tm (h) Tj 1 0 0 1 77 720 Tm (b) Tj 1 0 0 1 82 720 Tm (a) Tj ET",
            ], $fonts, $extra);
            $this->assertSame('א' . 'ב' . "\u{05B8}" . 'ג', YetiPdf::parse($pdf)->text(), "mark at $x");
        }
    }

    public function testLongPageKeepsItsLinesInPlace(): void
    {
        [$fonts, $extra] = self::hebrewFont();
        $fonts['F2'] = '/Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding';
        // Enough text that the start of the page has been put aside by the time a line has to be taken back,
        // and by the time the second half of a line drawn in two goes arrives.
        $content = 'BT /F2 10 Tf 12 TL 1 0 0 1 72 9000 Tm ';
        $lines = [];
        $ordinary = static function (int $from) use (&$content, &$lines): void {
            for ($i = $from; $i < $from + 300; $i++) {
                $content .= "(Ordinary line number $i of this page) Tj T* ";
                $lines[] = "Ordinary line number $i of this page";
            }
        };
        $ordinary(1);
        $content .= '(Total: ) Tj /F1 10 Tf (cba) Tj T* ';
        $lines[] = 'Total: ' . 'אבג';
        $content .= '1 0 0 1 300 100 Tm (fed ) Tj /F2 10 Tf 1 0 0 1 72 5000 Tm ';
        $lines[] = 'אבג' . ' ' . 'דהו';
        $ordinary(301);
        $content .= '/F1 10 Tf 1 0 0 1 320 100 Tm (cba) Tj /F2 10 Tf 1 0 0 1 72 1000 Tm (The end) Tj ET';
        $lines[] = 'The end';

        $this->assertSame(implode("\n", $lines), YetiPdf::parse(PdfBuilder::build([$content], $fonts, $extra))->text());
    }

    public function testLineTooLongToBeALineIsLeftAsItIsDrawn(): void
    {
        $line = str_repeat(self::r('שלום') . ' ', 2000);
        $this->assertSame($line, Bidi::logical($line));
        $this->assertNotSame($line, Bidi::logical(substr($line, 0, 900)));
    }

    public function testOrdinaryTextAroundARightToLeftLineIsUntouched(): void
    {
        [$fonts, $extra] = self::hebrewFont();
        $fonts['F2'] = '/Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding';
        $pdf = PdfBuilder::build([
            'BT /F2 10 Tf 12 TL 72 720 Td (First line) Tj T* (Total: ) Tj /F1 10 Tf (cba) Tj T* /F2 10 Tf (Last line) Tj ET',
        ], $fonts, $extra);
        $this->assertSame("First line\nTotal: אבג\nLast line", YetiPdf::parse($pdf)->text());
    }
}
