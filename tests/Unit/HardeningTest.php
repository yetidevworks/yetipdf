<?php

declare(strict_types=1);

namespace YetiPdf\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YetiPdf\Content\Interpreter;
use YetiPdf\Core\File;
use YetiPdf\Core\Ref;
use YetiPdf\Document;
use YetiPdf\Filter\Filters;
use YetiPdf\Font\CMap;
use YetiPdf\Font\Font;
use YetiPdf\Font\FontLoader;
use YetiPdf\Font\Ranges;
use YetiPdf\Font\Utf;
use YetiPdf\Options;
use YetiPdf\Tests\PdfBuilder;
use YetiPdf\YetiPdf;

/** Small files that ask for far more memory or time than they are worth must cost a bounded amount and lose only their own part. */
final class HardeningTest extends TestCase
{
    /** A page whose content is the given stream object. The same-length swap keeps the cross-reference table valid. */
    private static function pageWithContent(string $streamObject): string
    {
        $pdf = PdfBuilder::build(['placeholder'], [], [99 => $streamObject]);
        return str_replace('/Contents 31 0 R', '/Contents 99 0 R', $pdf);
    }

    /** @return list<string> */
    private static function notes(Document $doc, string $containing): array
    {
        return array_values(array_filter($doc->warnings(), static fn(string $w): bool => str_contains($w, $containing)));
    }

    /** LZW with every byte sent as its own code, which is enough to test the decoder's limit. */
    private static function lzwLiterals(string $data): string
    {
        $bits = 9;
        $next = 258;
        $out = '';
        $buf = 0;
        $have = 0;
        $emit = static function (int $code, int $width) use (&$out, &$buf, &$have): void {
            $buf = ($buf << $width) | $code;
            $have += $width;
            while ($have >= 8) {
                $out .= chr(($buf >> ($have - 8)) & 0xFF);
                $have -= 8;
            }
            $buf &= (1 << $have) - 1;
        };
        $emit(256, $bits);
        foreach (str_split($data) as $i => $byte) {
            $emit(ord($byte), $bits);
            if ($i > 0) {
                $next++;
            }
            if ($next + 1 >= (1 << $bits) && $bits < 12) {
                $bits++;
            }
        }
        $emit(257, $bits);
        return $out . ($have > 0 ? chr(($buf << (8 - $have)) & 0xFF) : '');
    }

    public function testFlateStreamIsCutAtTheLimit(): void
    {
        $bomb = (string)gzcompress(str_repeat(' ', 4000000) . 'tail');
        $cut = false;
        $out = Filters::decode($bomb, ['/FlateDecode'], [null], 100000, $cut);

        $this->assertTrue($cut);
        $this->assertSame(100000, strlen($out));
        $this->assertSame(str_repeat(' ', 100000), $out);
    }

    public function testRawAndDamagedFlateStreamsAreCutToo(): void
    {
        $cut = false;
        $out = Filters::decode((string)gzdeflate(str_repeat('a', 4000000)), ['/FlateDecode'], [null], 5000, $cut);
        $this->assertTrue($cut);
        $this->assertSame(5000, strlen($out));

        $cut = false;
        $damaged = substr((string)gzcompress(str_repeat('b', 4000000)), 0, -2);
        $out = Filters::decode($damaged, ['/FlateDecode'], [null], 5000, $cut);
        $this->assertTrue($cut);
        $this->assertSame(5000, strlen($out));
    }

    public function testAStreamUnderTheLimitIsNotTouched(): void
    {
        $text = str_repeat('The quick brown fox. ', 100);
        $cut = false;
        $this->assertSame($text, Filters::decode((string)gzcompress($text), ['/FlateDecode'], [null], strlen($text), $cut));
        $this->assertFalse($cut);
    }

    public function testEveryDecoderStopsAtTheLimit(): void
    {
        $cut = false;
        $this->assertSame(1000, strlen((string)Filters::decode(str_repeat("\x81a", 1000), ['/RunLengthDecode'], [null], 1000, $cut)));
        $this->assertTrue($cut);

        $cut = false;
        $this->assertSame(1000, strlen((string)Filters::decode(str_repeat('z', 1000) . '~>', ['/ASCII85Decode'], [null], 1000, $cut)));
        $this->assertTrue($cut);

        $literals = str_repeat('abcdefgh', 500);
        $this->assertSame($literals, Filters::lzw(self::lzwLiterals($literals)));
        $cut = false;
        $this->assertSame(substr($literals, 0, 700), Filters::decode(self::lzwLiterals($literals), ['/LZWDecode'], [null], 700, $cut));
        $this->assertTrue($cut);
    }

    public function testChainedFiltersAreLimitedAtEveryStage(): void
    {
        // Run-length output of 128 bytes per two bytes of input, wrapped in flate.
        $inner = str_repeat("\x81a", 50000);
        $cut = false;
        $out = Filters::decode((string)gzcompress($inner), ['/FlateDecode', '/RunLengthDecode'], [null, null], 200000, $cut);

        $this->assertTrue($cut);
        $this->assertSame(200000, strlen($out));
    }

    public function testPredictorRowsTooLongToUnpackAreDropped(): void
    {
        $cut = false;
        $this->assertSame('', Filters::predict("\x02abc", ['Predictor' => 12, 'Columns' => 100000000], $cut));
        $this->assertTrue($cut);

        $cut = false;
        $this->assertSame("\x01\x02\x03\x04", Filters::predict("\x01\x01\x01\x01", ['Predictor' => 2, 'Columns' => 4], $cut));
        $this->assertFalse($cut);
    }

    public function testOversizedPageContentLeavesAWarningAndTheOtherPagesAlone(): void
    {
        $bomb = PdfBuilder::stream(str_repeat(' ', 8000000) . 'BT /F1 12 Tf (never seen) Tj ET', true);
        $doc = new Document(new File(self::pageWithContent($bomb), '', 4 << 20), new Options());

        $this->assertSame('', $doc->text());
        $this->assertCount(1, self::notes($doc, 'too large to unpack'));
        $this->assertStringContainsString('Stream 99', self::notes($doc, 'too large to unpack')[0]);

        $good = new Document(new File(PdfBuilder::build(['BT /F1 12 Tf (fine) Tj ET', 'BT /F1 12 Tf (also fine) Tj ET']), '', 4 << 20), new Options());
        $this->assertSame("fine\n\nalso fine", $good->text());
        $this->assertSame([], $good->warnings());
    }

    public function testThePiecesOfAPageShareOneLimit(): void
    {
        $piece = PdfBuilder::stream(str_repeat(' ', 600000), true);
        $text = PdfBuilder::stream('BT /F1 12 Tf (last) Tj ET');
        $pdf = PdfBuilder::build(['placeholder'], [], [96 => $piece, 97 => $piece, 98 => $piece, 99 => $text]);
        $pdf = str_replace('/Contents 31 0 R', '/Contents [96 0 R 97 0 R 98 0 R 99 0 R]', $pdf);
        $doc = new Document(new File($pdf, '', 4 << 20), new Options());

        $doc->text();
        $this->assertNotEmpty(self::notes($doc, 'rest of it was left out'));
    }

    public function testContentTooDenseForTheMemoryLeftIsSkipped(): void
    {
        $content = 'BT /F1 12 Tf 72 720 Td ' . str_repeat('()Tj ', 230000) . '(late) Tj ET';
        $pdf = PdfBuilder::build([$content]);

        $tight = new Document(new File($pdf, '', 8 << 20), new Options());
        $this->assertSame('', $tight->text());
        $this->assertCount(1, self::notes($tight, 'too dense'));

        $roomy = new Document(new File($pdf, '', 1 << 30), new Options());
        $this->assertSame('late', $roomy->text());
        $this->assertSame([], $roomy->warnings());
    }

    public function testDenseDrawingThatTheTokenizerSkipsIsStillRead(): void
    {
        $content = str_repeat("100.5 200.25 m 300.5 400.75 l 12.5 13.5 14.5 15.5 16.5 17.5 c S\n", 20000) . 'BT /F1 12 Tf 72 720 Td (vector page) Tj ET';
        $this->assertGreaterThan(1 << 20, strlen($content));
        $doc = new Document(new File(PdfBuilder::build([$content]), '', 8 << 20), new Options());

        $this->assertSame('vector page', $doc->text());
        $this->assertSame([], $doc->warnings());
    }

    public function testLongRunsOfNumbersInFrontOfAnOperatorAreHandledByTheirTail(): void
    {
        $plain = YetiPdf::parse(PdfBuilder::build(['BT /F1 12 Tf 72 700 Td (a) Tj 0 -50 Td (b) Tj ET']))->text();
        $long = YetiPdf::parse(PdfBuilder::build(['BT /F1 12 Tf ' . str_repeat('7 ', 60000) . '72 700 Td (a) Tj ' . str_repeat('7 ', 60000) . '0 -50 Td (b) Tj ET']))->text();

        $this->assertSame("a\nb", $plain);
        $this->assertSame($plain, $long);
    }

    public function testRangesFindTheEarliestRangeThatHoldsACode(): void
    {
        mt_srand(7);
        $ranges = [];
        for ($i = 0; $i < 300; $i++) {
            $low = mt_rand(0, 2000);
            $ranges[] = [$low, $low + mt_rand(-5, 400)];
        }
        $index = new Ranges($ranges);

        for ($code = -3; $code < 2500; $code++) {
            $expected = null;
            foreach ($ranges as $id => [$low, $high]) {
                if ($code >= $low && $code <= $high) {
                    $expected = $id;
                    break;
                }
            }
            $this->assertSame($expected, $index->find($code), "code $code");
        }
        $this->assertNull((new Ranges([]))->find(5));
    }

    public function testWideRangesAreLookedUpWithTheSamePrecedenceAsExpandedOnes(): void
    {
        $cmap = CMap::parse("begincodespacerange <0000> <FFFF> endcodespacerange\n"
            . "beginbfchar <0010> <0058> endbfchar\n"
            . "beginbfrange\n<0000> <0FFF> <0100>\n<0800> <17FF> <0200>\n<2000> <2FFF> <0300>\n<0020> <0022> <0061>\nendbfrange");

        $this->assertSame('X', $cmap->get(0x10));
        $this->assertSame('abc', $cmap->get(0x20) . $cmap->get(0x21) . $cmap->get(0x22));
        $this->assertSame(Utf::chr(0x100 + 0x40), $cmap->get(0x40));
        $this->assertSame(Utf::chr(0x100 + 0x900), $cmap->get(0x900));
        $this->assertSame(Utf::chr(0x200 + 0x800), $cmap->get(0x1000));
        $this->assertSame(Utf::chr(0x300 + 0x10), $cmap->get(0x2010));
        $this->assertNull($cmap->get(0x1800));
        $this->assertNull($cmap->get(0x3000));
    }

    public function testThousandsOfFourByteRangesCostAboutAsMuchAsTheirText(): void
    {
        $ranges = '';
        for ($i = 0; $i < 20000; $i++) {
            $ranges .= sprintf("<%08X> <%08X> <0041>\n", $i * 512, $i * 512 + 511);
        }
        $data = "begincodespacerange <00000000> <FFFFFFFF> endcodespacerange\nbeginbfrange\n$ranges endbfrange";
        $cmap = CMap::parse($data);

        // Expanding them all would be ten million entries; what is expanded is about one for each byte of the CMap.
        $this->assertLessThan(strlen($data) * 2, count($cmap->map));
        foreach ([0, 5, 511, 512, 100000, 19999 * 512 + 511] as $code) {
            $this->assertSame(Utf::chr(0x41 + $code % 512), $cmap->get($code));
        }
        $this->assertNull($cmap->get(20000 * 512));
    }

    public function testATargetTooLongToBeRealIsIgnored(): void
    {
        $long = str_repeat('0041', 600);
        $cmap = CMap::parse("beginbfchar <01> <$long> <02> <0042> endbfchar\nbeginbfrange <10> <1F> <$long> endbfrange");

        $this->assertSame('', $cmap->get(1));
        $this->assertSame('B', $cmap->get(2));
        $this->assertNull($cmap->get(0x10));
    }

    public function testASectionTooBigForTheMemoryLeftIsLeftOutAndSaysSo(): void
    {
        $body = '';
        for ($i = 0; $i < 3000; $i++) {
            $body .= sprintf("<%04X> <%04X>\n", $i, 0x4E00 + $i);
        }
        $data = "beginbfchar\n$body endbfchar";

        $full = CMap::parse($data);
        $this->assertFalse($full->partial);
        $this->assertSame(Utf::chr(0x4E00 + 7), $full->get(7));

        $tight = CMap::parse($data, 100000);
        $this->assertTrue($tight->partial);
        $this->assertNull($tight->get(7));
    }

    private static function compositeFont(string $w): Font
    {
        $pdf = PdfBuilder::build([], ['F1' => '/Subtype /Type0 /BaseFont /X /Encoding /Identity-H /DescendantFonts [100 0 R]'],
            [100 => "<< /Type /Font /Subtype /CIDFontType2 /BaseFont /X /DW 1000 /W [$w] >>"]);
        return (new FontLoader(new File($pdf)))->load(new Ref(10));
    }

    private static function widthOf(Font $font, int $cid): float
    {
        $font->decode(pack('n', $cid));
        return $font->w;
    }

    public function testWideWidthRangesGiveTheWidthsTheyWouldWhenWrittenOut(): void
    {
        $font = self::compositeFont('0 1000 300  10 [500 510 520]  500 1500 400  40 45 777');

        $this->assertNotNull($font->widthIndex);
        $this->assertSame([], $font->widths);
        $expected = [0 => 0.3, 9 => 0.3, 10 => 0.5, 11 => 0.51, 12 => 0.52, 13 => 0.3, 39 => 0.3, 40 => 0.777, 45 => 0.777, 46 => 0.3,
            499 => 0.3, 500 => 0.4, 1000 => 0.4, 1500 => 0.4, 1501 => 1.0, 60000 => 1.0];
        foreach ($expected as $cid => $width) {
            $this->assertEqualsWithDelta($width, self::widthOf($font, $cid), 1e-9, "cid $cid");
        }
        // The same code twice, the second time from the cache.
        $this->assertEqualsWithDelta(0.4, self::widthOf($font, 700), 1e-9);
        $this->assertEqualsWithDelta(0.4, self::widthOf($font, 700), 1e-9);
    }

    public function testAnEntryBeforeAWideRangeLosesToItAndAnEntryAfterWinsOverIt(): void
    {
        $font = self::compositeFont('10 [500]  0 1000 300  20 [600]');

        $this->assertEqualsWithDelta(0.3, self::widthOf($font, 10), 1e-9);
        $this->assertEqualsWithDelta(0.6, self::widthOf($font, 20), 1e-9);
    }

    public function testFontsWithOnlyNarrowRangesKeepTheirWidthsInTheArray(): void
    {
        $font = self::compositeFont('0 [500 600] 10 20 450');

        $this->assertNull($font->widthIndex);
        $this->assertSame(500.0, $font->widths[0]);
        $this->assertSame(450.0, $font->widths[15]);
        $this->assertEqualsWithDelta(0.45, self::widthOf($font, 15), 1e-9);
    }

    public function testThousandsOfWideWidthRangesAreNotWrittenOut(): void
    {
        $font = self::compositeFont(str_repeat('0 65535 500 ', 3000));

        $this->assertSame([], $font->widths);
        $this->assertEqualsWithDelta(0.5, self::widthOf($font, 40000), 1e-9);
    }

    /**
     * Forms 0 to $depth - 1, each drawing the next one $fan times; the last one shows an "x".
     *
     * @return array{0: File, 1: array<string, mixed>}
     */
    private static function nestedForms(int $depth, int $fan): array
    {
        $extra = [];
        for ($i = 0; $i < $depth; $i++) {
            $last = $i === $depth - 1;
            $body = $last ? 'BT /F1 12 Tf (x) Tj ET' : str_repeat('/X' . ($i + 1) . ' Do ', $fan);
            $resources = $last ? '/Font << /F1 10 0 R >>' : '/XObject << /X' . ($i + 1) . ' ' . (101 + $i) . ' 0 R >>';
            $extra[100 + $i] = PdfBuilder::stream($body, false, "/Type /XObject /Subtype /Form /BBox [0 0 10 10] /Resources << $resources >>");
        }
        return [new File(PdfBuilder::build(['placeholder'], [], $extra)), ['XObject' => ['X0' => new Ref(100, 0)]]];
    }

    public function testFormsDrawingFormsStopAtThePageBudget(): void
    {
        [$file, $resources] = self::nestedForms(12, 3);
        $interpreter = new Interpreter($file, new FontLoader($file));

        // Three to the eleventh power is 177,147 runs of the last form; the page gets 20,000 runs of any form.
        $drawn = strlen($interpreter->page('/X0 Do', $resources));
        $this->assertGreaterThan(5000, $drawn);
        $this->assertLessThan(20000, $drawn);
        $this->assertSame(['A page draws forms so many times that the rest of its drawing was skipped'], $file->warnings);
    }

    public function testASmallExplicitFormBudgetIsHonoured(): void
    {
        [$file, $resources] = self::nestedForms(4, 3);
        $interpreter = new Interpreter($file, new FontLoader($file));
        $interpreter->maxFormRuns = 10;
        $interpreter->page('/X0 Do', $resources);
        $this->assertCount(1, $file->warnings);

        // The same page under a budget it fits in is read in full, and the budget starts over on the next page.
        [$file, $resources] = self::nestedForms(4, 3);
        $interpreter = new Interpreter($file, new FontLoader($file));
        $interpreter->maxFormRuns = 50;
        $this->assertSame(str_repeat('x', 27), $interpreter->page('/X0 Do', $resources));
        $this->assertSame(str_repeat('x', 27), $interpreter->page('/X0 Do', $resources));
        $this->assertSame([], $file->warnings);
    }

    public function testFormBytesAreBudgetedToo(): void
    {
        [$file, $resources] = self::nestedForms(2, 5);
        $interpreter = new Interpreter($file, new FontLoader($file));
        $interpreter->maxFormBytes = 40;
        $interpreter->page('/X0 Do', $resources);

        $this->assertCount(1, $file->warnings);
    }

    public function testLzwWithoutClearCodesPastTheLastTableEntryStillDecodes(): void
    {
        $literals = str_repeat('abcdefgh', 700);
        $this->assertSame($literals, Filters::lzw(self::lzwLiterals($literals)));
    }

    public function testAnAccentAndAHyphenLongAfterTheStartOfALongPageStillWork(): void
    {
        // Far more text than the part of the page that is kept open for edits, so most of it has been moved aside by then.
        $lines = '';
        $expected = [];
        for ($i = 0; $i < 3000; $i++) {
            $y = 780 - $i * 24;
            $lines .= "1 0 0 1 72 $y Tm (ab-) Tj 1 0 0 1 72 " . ($y - 12) . " Tm (cd) Tj ";
            $expected[] = 'abcd';
        }
        $this->assertSame(implode("\n", $expected), YetiPdf::parse(PdfBuilder::build(["BT /F1 10 Tf $lines ET"]))->text());

        // A letter drawn over an accent takes the accent back out of the text, and runs on from the line before.
        $lines = '';
        for ($i = 0; $i < 6000; $i++) {
            $y = 780 - $i * 12;
            $lines .= "1 0 0 1 72 $y Tm (\264) Tj 1 0 0 1 72 $y Tm (e) Tj ";
        }
        $this->assertSame(str_repeat('é', 6000), YetiPdf::parse(PdfBuilder::build(["BT /F1 10 Tf $lines ET"]))->text());
    }

    /** A font that turns the code <0041> into a hundred letters. */
    private static function wordyPage(int $strings, int $codes): string
    {
        $cmap = "begincodespacerange <0000> <FFFF> endcodespacerange\nbeginbfchar <0041> <" . str_repeat('0041', 100) . "> endbfchar";
        $content = 'BT /F1 12 Tf 72 720 Td ';
        for ($i = 0; $i < $strings; $i++) {
            $content .= '<' . str_repeat('0041', $codes) . '> Tj 0 -14 Td ';
        }
        return PdfBuilder::build(
            [$content . 'ET'],
            ['F1' => '/Subtype /Type0 /BaseFont /X /Encoding /Identity-H /ToUnicode 100 0 R /DescendantFonts [101 0 R]'],
            [100 => PdfBuilder::stream($cmap, true), 101 => '<< /Type /Font /Subtype /CIDFontType2 /DW 1000 >>']
        );
    }

    public function testAStringThatTurnsIntoTooMuchTextIsCutShortWithAWarning(): void
    {
        $doc = new Document(new File(self::wordyPage(1, 20000), '', 4 << 20), new Options());
        $text = $doc->text();

        $this->assertNotSame('', $text);
        $this->assertLessThan(2000000, strlen($text));
        $this->assertCount(1, self::notes($doc, 'turns into more text'));
    }

    public function testAPageWhoseTextOutgrowsTheMemoryKeepsWhatCameFirst(): void
    {
        $doc = new Document(new File(self::wordyPage(8, 20000), '', 4 << 20), new Options());
        $text = $doc->text();

        $this->assertStringStartsWith('AAAA', $text);
        $this->assertLessThan(8 * 2000000, strlen($text));
        $this->assertCount(1, self::notes($doc, 'text of a page is too large'));
    }

    public function testAStringOfFixedWidthCodesLongerThanOnePieceDecodesLikeAShortOne(): void
    {
        $font = self::compositeFont('0 [500]');
        $font->codesAreUnicode = true;
        $long = str_repeat(pack('n', 0x41), 40001);

        $this->assertSame(str_repeat('A', 40001), $font->decode($long));
        $this->assertSame(40001, $font->n);
        $this->assertEqualsWithDelta(40001 * 1.0, $font->w, 1e-6);
        // An odd number of bytes ends in a half code, as it always has.
        $this->assertSame(str_repeat('A', 20000) . "\0", $font->decode(str_repeat(pack('n', 0x41), 20000) . "\x00"));
    }

    public function testACrossReferenceStreamThatUnpacksToFarMoreThanTheFileIsCutShort(): void
    {
        $rows = (string)gzcompress(str_repeat("\x01\x00\x10\x00", 500000), 9);
        $pdf = "%PDF-1.5\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n";
        $at = strlen($pdf);
        $pdf .= "3 0 obj\n<< /Type /XRef /Size 500000 /W [1 2 1] /Root 1 0 R /Filter /FlateDecode /Length " . strlen($rows) . " >>\nstream\n$rows\nendstream\nendobj\nstartxref\n$at\n%%EOF\n";
        $file = new File($pdf);

        $this->assertNotEmpty(array_filter($file->warnings, static fn(string $w): bool => str_contains($w, 'too large to unpack')));
        $this->assertLessThan(100000, count($file->objectNumbers()));
    }

    public function testManyCrossReferenceSubsectionsThatClaimMoreRowsThanTheyHaveAreGivenUpOn(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n";
        $at = strlen($pdf);
        $pdf .= "xref\n" . str_repeat("0 1000000\n0000000000 65535 f \n", 4000) . "trailer\n<< /Size 3 /Root 1 0 R >>\nstartxref\n$at\n%%EOF\n";
        $doc = YetiPdf::parse($pdf);

        $this->assertSame('', $doc->text());
        $this->assertContains('Cross-reference table was missing or damaged; rebuilt by scanning the file', $doc->warnings());
    }

    public function testAnObjectStreamHeaderIsReadOnlyAsFarAsTheMemoryAllows(): void
    {
        $pairs = '';
        $objects = '';
        for ($i = 0; $i < 1000; $i++) {
            $pairs .= (5 + $i) . ' ' . strlen($objects) . ' ';
            $objects .= "<< /K $i >> ";
        }
        $pdf = "%PDF-1.5\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n"
            . '3 0 obj' . "\n<< /Type /ObjStm /N 1000 /First " . strlen($pairs) . ' /Length ' . strlen($pairs . $objects) . " >>\nstream\n$pairs$objects\nendstream\nendobj\n"
            . "trailer << /Root 1 0 R >>\n";

        $roomy = new File($pdf);
        $this->assertSame(['K' => 999], $roomy->get(1004));

        // A megabyte allows 512 entries.
        $tight = new File($pdf, '', 1 << 20);
        $this->assertSame(['K' => 511], $tight->get(516));
        $this->assertNull($tight->get(517));
    }

    public function testStreamsWithNoEndstreamAreStillCutAtTheirObjectEnd(): void
    {
        $page = static fn(int $page, int $stream): string => "$page 0 obj\n<< /Type /Page /Parent 2 0 R /Contents $stream 0 R /Resources << /Font << /F1 9 0 R >> >> >>\nendobj\n";
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            . "2 0 obj\n<< /Type /Pages /Kids [3 0 R 5 0 R 7 0 R] /Count 3 >>\nendobj\n"
            . "9 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n"
            . $page(3, 4) . "4 0 obj\n<< /Length 999 >>\nstream\nBT /F1 12 Tf (one) Tj ET\nendstream\nendobj\n"
            . $page(5, 6) . "6 0 obj\n<< /Length 999 >>\nstream\nBT /F1 12 Tf (two) Tj ET\nendobj\n"
            . $page(7, 8) . "8 0 obj\n<< /Length 999 >>\nstream\nBT /F1 12 Tf (three) Tj ET\nendobj\n"
            . "trailer << /Root 1 0 R >>\n";

        $this->assertSame("one\n\ntwo\n\nthree", YetiPdf::parse($pdf)->text());
    }

    public function testAType1EncodingIsReadFromTheStartOfAProgramThatIsTooBigToUnpack(): void
    {
        $head = "%!PS-AdobeFont-1.0: Custom\n/Encoding 256 array\n0 1 255 {1 index exch /.notdef put} for\ndup 65 /Eacute put\nreadonly def\n";
        $program = PdfBuilder::stream($head . str_repeat(' ', 3000000), true, '/Length1 ' . strlen($head));
        $pdf = PdfBuilder::build([], ['F1' => '/Subtype /Type1 /BaseFont /Custom /FontDescriptor 100 0 R'],
            [100 => '<< /Type /FontDescriptor /FontFile 101 0 R >>', 101 => $program]);
        $file = new File($pdf, '', 4 << 20);

        $font = (new FontLoader($file))->load(new Ref(10));
        $this->assertSame('É', $font->decode('A'));
        $this->assertSame([], $file->warnings);
    }

    public function testRepeatedNotesAreKeptOnceAndTheListStopsGrowing(): void
    {
        $file = new File(PdfBuilder::build(['x']));
        for ($i = 0; $i < 5000; $i++) {
            $file->warn('same note');
            $file->warn("note $i");
        }

        $this->assertLessThanOrEqual(102, count($file->warnings));
        $this->assertSame(1, count(array_keys($file->warnings, 'same note', true)));
        $this->assertSame('More problems were found than are listed here', end($file->warnings));
    }

    public function testAFileOfNothingButObjectMarkersIsRebuiltWithoutBuildingAMatchForEach(): void
    {
        $pdf = "%PDF-1.4\n" . str_repeat('1 0 obj ', 200000) . "\n2 0 obj\n<< /Type /Catalog /Pages 3 0 R >>\nendobj\n3 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer << /Root 2 0 R >>\n";
        $doc = YetiPdf::parse($pdf);

        $this->assertSame('', $doc->text());
        $this->assertContains('Cross-reference table was missing or damaged; rebuilt by scanning the file', $doc->warnings());
    }

    public function testSavingTheGraphicsStateWithoutEverRestoringItDoesNotPileUpCopies(): void
    {
        $content = str_repeat('q 1 0 0 1 1 1 cm ', 6000) . 'BT /F1 12 Tf 72 720 Td (still here) Tj ET' . str_repeat(' Q', 6000);
        $this->assertSame('still here', YetiPdf::parse(PdfBuilder::build([$content]))->text());
    }
}
