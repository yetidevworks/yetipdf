<?php

declare(strict_types=1);

namespace YetiPdf\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YetiPdf\Font\TrueTypeMap;
use YetiPdf\Tests\PdfBuilder;
use YetiPdf\YetiPdf;

final class TrueTypeMapTest extends TestCase
{
    /** An sfnt with only a cmap table; the subtables are [platform, encoding, bytes]. */
    private static function font(array $subtables): string
    {
        $cmap = pack('nn', 0, count($subtables));
        $body = '';
        $offset = 4 + 8 * count($subtables);
        foreach ($subtables as [$platform, $encoding, $bytes]) {
            $cmap .= pack('nnN', $platform, $encoding, $offset + strlen($body));
            $body .= $bytes;
        }
        $cmap .= $body;
        return self::sfnt($cmap);
    }

    private static function sfnt(string $cmap): string
    {
        return "\0\1\0\0" . pack('nnnn', 1, 16, 0, 0) . 'cmap' . pack('NNN', 0, 28, strlen($cmap)) . $cmap;
    }

    /** @param list<array{0: int, 1: int, 2: int}> $segments [first, last, delta] with no range offset */
    private static function format4(array $segments): string
    {
        $segments[] = [0xFFFF, 0xFFFF, 1];
        $n = count($segments);
        $table = pack('nnnnnnn', 4, 16 + 8 * $n, 0, $n * 2, 0, 0, 0);
        foreach ([1, 0, 2] as $column) {
            if ($column === 1) {
                foreach ($segments as $s) {
                    $table .= pack('n', $s[1]);
                }
                $table .= pack('n', 0);
            } else {
                foreach ($segments as $s) {
                    $table .= pack('n', $s[$column]);
                }
            }
        }
        return $table . str_repeat("\0\0", $n);
    }

    /** @param list<array{0: int, 1: int, 2: int}> $groups [first, last, glyph id of first] */
    private static function format12(array $groups): string
    {
        $table = pack('nnNNN', 12, 0, 16 + 12 * count($groups), 0, count($groups));
        foreach ($groups as $g) {
            $table .= pack('NNN', ...$g);
        }
        return $table;
    }

    /** @param list<int> $glyphs */
    private static function format6(int $first, array $glyphs): string
    {
        return pack('nnnnn', 6, 10 + 2 * count($glyphs), 0, $first, count($glyphs)) . pack('n*', ...$glyphs);
    }

    public function testFormat4WithDeltasAndAGlyphIdArray(): void
    {
        // Segment one: A-C are glyphs 3-5 by delta. Segment two: D, E and F come from the glyph id array, and E is glyph 0 (absent).
        // The range offset of segment two is 4: its own entry, the last segment's entry, then the array.
        $table = pack('nnnnnnn', 4, 0, 0, 6, 0, 0, 0)
            . pack('nnn', 0x43, 0x46, 0xFFFF) . pack('n', 0)
            . pack('nnn', 0x41, 0x44, 0xFFFF)
            . pack('nnn', 65536 - 0x41 + 3, 0, 1)
            . pack('nnn', 0, 4, 0)
            . pack('nnn', 10, 0, 12);
        $map = new TrueTypeMap(self::font([[3, 1, $table]]));

        $this->assertTrue($map->isUsable());
        $this->assertSame('A', $map->get(3));
        $this->assertSame('B', $map->get(4));
        $this->assertSame('C', $map->get(5));
        $this->assertSame('D', $map->get(10));
        $this->assertNull($map->get(11));
        $this->assertSame('F', $map->get(12));
        $this->assertNull($map->get(0));
        $this->assertNull($map->get(70000));
    }

    public function testFormat4DeltaOnlySegments(): void
    {
        $map = new TrueTypeMap(self::font([[3, 1, self::format4([[0x41, 0x5A, 65536 - 0x41 + 1], [0xE9, 0xE9, 65536 - 0xE9 + 40]])]]));

        $this->assertSame('A', $map->get(1));
        $this->assertSame('Z', $map->get(26));
        $this->assertSame('é', $map->get(40));
        $this->assertNull($map->get(27));
    }

    public function testFormat12ReachesPastTheBasicPlane(): void
    {
        $map = new TrueTypeMap(self::font([[3, 10, self::format12([[0x41, 0x43, 1], [0x1F600, 0x1F601, 10]])]]));

        $this->assertSame('B', $map->get(2));
        $this->assertSame("\u{1F600}", $map->get(10));
        $this->assertSame("\u{1F601}", $map->get(11));
        $this->assertNull($map->get(12));
    }

    public function testFormat6AndFormat0ReadThroughMacRoman(): void
    {
        $six = new TrueTypeMap(self::font([[1, 0, self::format6(0x41, [5, 6, 0, 8])]]));
        $this->assertSame('A', $six->get(5));
        $this->assertSame('B', $six->get(6));
        $this->assertNull($six->get(7));
        $this->assertSame('D', $six->get(8));

        // Mac Roman 0x8A is ä and 0xD0 is an en dash.
        $glyphs = array_fill(0, 256, 0);
        $glyphs[0x61] = 1;
        $glyphs[0x8A] = 2;
        $glyphs[0xD0] = 3;
        $zero = new TrueTypeMap(self::font([[1, 0, pack('nnn', 0, 262, 0) . pack('C*', ...$glyphs)]]));
        $this->assertSame('a', $zero->get(1));
        $this->assertSame('ä', $zero->get(2));
        $this->assertSame('–', $zero->get(3));
    }

    public function testSymbolTableFoldsTheF000Range(): void
    {
        // Symbolic fonts keep A at U+F041. A glyph with only a Private Use Area code point is not text.
        $map = new TrueTypeMap(self::font([[3, 0, self::format4([[0xF041, 0xF043, 65536 - 0xF041 + 1], [0xE000, 0xE000, 65536 - 0xE000 + 9]])]]));

        $this->assertSame('A', $map->get(1));
        $this->assertSame('C', $map->get(3));
        $this->assertNull($map->get(9));
    }

    public function testSubtablePreference(): void
    {
        $unicode = self::format12([[0x58, 0x58, 1]]);
        $bmp = self::format4([[0x59, 0x59, 65536 - 0x59 + 1]]);
        $mac = self::format6(0x5A, [1]);

        $this->assertSame('X', (new TrueTypeMap(self::font([[3, 1, $bmp], [1, 0, $mac], [3, 10, $unicode]])))->get(1));
        $this->assertSame('X', (new TrueTypeMap(self::font([[3, 1, $bmp], [0, 4, $unicode]])))->get(1));
        $this->assertSame('Y', (new TrueTypeMap(self::font([[1, 0, $mac], [3, 1, $bmp]])))->get(1));
        $this->assertSame('Z', (new TrueTypeMap(self::font([[1, 0, $mac]])))->get(1));
    }

    public function testLowestOrdinaryCodePointWinsWhenGlyphsAreShared(): void
    {
        $map = new TrueTypeMap(self::font([[3, 10, self::format12([
            [0xE001, 0xE001, 1],     // Private Use Area only, with an ordinary code point below
            [0x62, 0x62, 1],
            [0x61, 0x61, 1],
            [0x00, 0x00, 2],         // U+0000 is a placeholder
            [0xFFFD, 0xFFFD, 3],     // so is U+FFFD
            [0xA0, 0xA0, 4],         // no-break space and space share a glyph; space is lower
            [0x20, 0x20, 4],
        ])]]));

        $this->assertSame('a', $map->get(1));
        $this->assertNull($map->get(2));
        $this->assertNull($map->get(3));
        $this->assertSame(' ', $map->get(4));
    }

    public function testAFontMostlyInThePrivateUseAreaIsNotUsable(): void
    {
        $custom = self::format12([[0xE000, 0xE009, 1], [0x41, 0x41, 20]]);
        $this->assertFalse((new TrueTypeMap(self::font([[3, 10, $custom]])))->isUsable());

        $mostlyText = self::format12([[0xE000, 0xE000, 1], [0x41, 0x43, 2]]);
        $map = new TrueTypeMap(self::font([[3, 10, $mostlyText]]));
        $this->assertTrue($map->isUsable());
        $this->assertNull($map->get(1));
        $this->assertSame('A', $map->get(2));
    }

    public function testCidToGidMapStream(): void
    {
        $map = new TrueTypeMap(
            self::font([[3, 10, self::format12([[0x61, 0x63, 1]])]]),
            pack('n*', 0, 3, 1, 0, 2)
        );

        $this->assertNull($map->get(0));
        $this->assertSame('c', $map->get(1));
        $this->assertSame('a', $map->get(2));
        $this->assertNull($map->get(3));
        $this->assertSame('b', $map->get(4));
        $this->assertNull($map->get(5), 'a CID past the end of the stream selects glyph 0');
        $this->assertNull($map->get(-1));
    }

    public function testNothingIsReadUntilTheFirstLookup(): void
    {
        $map = new TrueTypeMap('not a font at all');
        $this->assertNull($map->get(1));
        $this->assertFalse($map->isUsable());
    }

    public function testFontWithoutACmapTableIsNotUsable(): void
    {
        $head = "\0\1\0\0" . pack('nnnn', 1, 16, 0, 0) . 'head' . pack('NNN', 0, 28, 4) . "\0\0\0\0";
        $this->assertFalse((new TrueTypeMap($head))->isUsable());
        $this->assertFalse((new TrueTypeMap(self::font([[3, 1, self::format6(0, [1])]])))->isUsable(), 'a format 6 table is only read for Mac Roman');
    }

    public function testEveryTruncationOfAFontIsHarmless(): void
    {
        $font = self::font([
            [0, 3, self::format4([[0x41, 0x5A, 65536 - 0x41 + 1]])],
            [3, 10, self::format12([[0x41, 0x5A, 1], [0x1F600, 0x1F60F, 40]])],
            [1, 0, self::format6(0x41, [1, 2, 3])],
        ]);
        for ($cut = 0; $cut <= strlen($font); $cut++) {
            $map = new TrueTypeMap(substr($font, 0, $cut));
            $map->isUsable();
            foreach ([0, 1, 2, 26, 40, 41, 65535] as $code) {
                $map->get($code);
            }
        }
        $this->assertSame('A', (new TrueTypeMap($font))->get(1));
    }

    public function testOffsetsPastTheEndMeanNoMap(): void
    {
        $good = self::format12([[0x41, 0x41, 1]]);
        // The directory points the cmap table beyond the end of the data.
        $far = substr_replace(self::font([[3, 10, $good]]), pack('N', 0x7FFFFFFF), 20, 4);
        $this->assertFalse((new TrueTypeMap($far))->isUsable());
        $this->assertNull((new TrueTypeMap($far))->get(1));

        // The record points the subtable beyond the end.
        $cmap = pack('nn', 0, 1) . pack('nnN', 3, 10, 0xFFFFFF00) . $good;
        $this->assertFalse((new TrueTypeMap(self::sfnt($cmap)))->isUsable());

        // A table count that claims far more tables than there are bytes.
        $many = substr_replace(self::font([[3, 10, $good]]), pack('n', 65535), 4, 2);
        $this->assertTrue((new TrueTypeMap($many))->isUsable());
        $cmapMany = substr_replace(self::font([[3, 10, $good]]), pack('n', 65535), 30, 2);
        $this->assertTrue((new TrueTypeMap($cmapMany))->isUsable());
    }

    public function testFormat4WithBadSegmentsIsReadSafely(): void
    {
        // Claims 32767 segments in a table that holds two.
        $table = substr_replace(self::format4([[0x41, 0x41, 65536 - 0x41 + 1]]), pack('n', 65534), 6, 2);
        $map = new TrueTypeMap(self::font([[3, 1, $table]]));
        $map->get(1);

        // Segments that end before they start, and a range offset that points far past the end of the font.
        $broken = pack('nnnnnnn', 4, 0, 0, 6, 0, 0, 0)
            . pack('nnn', 0x40, 0x50, 0xFFFF) . pack('n', 0)
            . pack('nnn', 0x50, 0x41, 0xFFFF)
            . pack('nnn', 0, 0, 1)
            . pack('nnn', 0, 0xFFF0, 0);
        $map = new TrueTypeMap(self::font([[3, 1, $broken]]));
        $this->assertFalse($map->isUsable());
        $this->assertNull($map->get(1));

        $empty = new TrueTypeMap(self::font([[3, 1, pack('nnnnnnn', 4, 14, 0, 0, 0, 0, 0)]]));
        $this->assertFalse($empty->isUsable());
    }

    public function testHugeFormat12GroupsCostNoMoreThanTheGlyphIds(): void
    {
        $started = microtime(true);
        $groups = [[0, 0xFFFFFFFF, 1]];
        // Thousands of overlapping groups, each claiming every code point, and one that claims a glyph id beyond the font.
        for ($i = 0; $i < 4000; $i++) {
            $groups[] = [0x100, 0x10FFFF, 1 + $i % 7];
        }
        $groups[] = [0x41, 0x7FFFFFFF, 0xFFFFFFF0];
        $map = new TrueTypeMap(self::font([[3, 10, self::format12($groups)]]));

        $this->assertTrue($map->isUsable());
        $this->assertNotNull($map->get(1));
        $this->assertNull($map->get(65536));
        $this->assertLessThan(2.0, microtime(true) - $started);

        // A group count far larger than the table.
        $liar = substr_replace(self::format12([[0x41, 0x42, 1]]), pack('N', 0xFFFFFFFF), 12, 4);
        $this->assertSame('B', (new TrueTypeMap(self::font([[3, 10, $liar]])))->get(2));
    }

    public function testCompositeFontWithoutToUnicodeReadsTheEmbeddedProgram(): void
    {
        // A subset: glyph 1 is space, 2 is H, 3 is i, and the program maps those from Unicode.
        $program = self::font([[3, 1, self::format4([[0x20, 0x20, 65536 - 0x20 + 1], [0x48, 0x48, 65536 - 0x48 + 2], [0x69, 0x69, 65536 - 0x69 + 3]])]]);
        $pdf = PdfBuilder::build(
            ['BT /F1 12 Tf 72 720 Td <000200030001000200030003> Tj ET'],
            ['F1' => '/Subtype /Type0 /BaseFont /AAAAAA+Subset /Encoding /Identity-H /DescendantFonts [101 0 R]'],
            [
                100 => PdfBuilder::stream($program, true, '/Length1 ' . strlen($program)),
                101 => '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /AAAAAA+Subset /DW 500 /CIDToGIDMap /Identity /FontDescriptor 102 0 R >>',
                102 => '<< /Type /FontDescriptor /FontName /AAAAAA+Subset /Flags 4 /FontFile2 100 0 R >>',
            ]
        );
        $doc = YetiPdf::parse($pdf);

        $this->assertSame('Hi Hii', $doc->text());
        $this->assertSame([], $doc->warnings());
    }

    public function testCompositeFontWithAnUnreadableProgramStaysUnmapped(): void
    {
        $pdf = PdfBuilder::build(
            ['BT /F1 12 Tf 72 720 Td <00020003> Tj ET'],
            ['F1' => '/Subtype /Type0 /BaseFont /Broken /Encoding /Identity-H /DescendantFonts [101 0 R]'],
            [
                100 => PdfBuilder::stream('this is not a font'),
                101 => '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /Broken /FontDescriptor 102 0 R >>',
                102 => '<< /Type /FontDescriptor /FontName /Broken /Flags 4 /FontFile2 100 0 R >>',
            ]
        );
        $doc = YetiPdf::parse($pdf);

        $this->assertSame('', $doc->text());
        $this->assertSame(['Font Broken has no character map; text set in it may be missing or wrong'], $doc->warnings());
    }
}
