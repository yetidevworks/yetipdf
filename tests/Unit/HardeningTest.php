<?php

declare(strict_types=1);

namespace YetiPdf\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YetiPdf\Core\File;
use YetiPdf\Document;
use YetiPdf\Filter\Filters;
use YetiPdf\Font\CMap;
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
}
