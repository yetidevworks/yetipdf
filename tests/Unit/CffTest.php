<?php

declare(strict_types=1);

namespace YetiPdf\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YetiPdf\Font\Cff;
use YetiPdf\Tests\PdfBuilder;
use YetiPdf\YetiPdf;

final class CffTest extends TestCase
{
    /** An INDEX: count, offset size, offsets (counting from the byte before the data), data. */
    private static function index(array $items, int $offSize = 1): string
    {
        if ($items === []) {
            return "\0\0";
        }
        $out = pack('n', count($items)) . chr($offSize);
        $at = 1;
        $out .= self::uint($at, $offSize);
        foreach ($items as $item) {
            $at += strlen($item);
            $out .= self::uint($at, $offSize);
        }
        return $out . implode('', $items);
    }

    private static function uint(int $value, int $size): string
    {
        return substr(pack('N', $value), 4 - $size);
    }

    /** A DICT operand written as a 5-byte integer, so the layout of the font does not depend on the offsets. */
    private static function operand(int $value): string
    {
        return "\x1D" . pack('N', $value);
    }

    /**
     * A CFF font program with no charstrings: just what the reader needs.
     *
     * @param string $encoding the Encoding structure
     * @param string $charset the charset structure
     * @param list<string> $strings the String INDEX
     */
    private static function font(string $encoding, string $charset, array $strings = [], string $topExtra = '', ?int $encodingAt = null, ?int $charsetAt = null, int $offSize = 1): string
    {
        $head = "\x01\x00\x04\x01";
        $names = self::index(['Test'], $offSize);
        $stringIndex = self::index($strings, $offSize);
        $topLength = strlen($topExtra) + 12;
        $topIndex = strlen(self::index([str_repeat('x', $topLength)], $offSize));
        $start = strlen($head) + strlen($names) + $topIndex + strlen($stringIndex);
        $top = $topExtra . self::operand($charsetAt ?? $start) . "\x0F" . self::operand($encodingAt ?? $start + strlen($charset)) . "\x10";
        return $head . $names . self::index([$top], $offSize) . $stringIndex . $charset . $encoding;
    }

    /** Encoding format 0: one code per glyph, glyph 1 first. */
    private static function codes(int ...$codes): string
    {
        return "\x00" . chr(count($codes)) . implode('', array_map('chr', $codes));
    }

    /** Charset format 0: one SID per glyph, glyph 1 first. */
    private static function sids(int ...$sids): string
    {
        return "\x00" . implode('', array_map(fn(int $sid) => pack('n', $sid), $sids));
    }

    public function testEncodingFormat0(): void
    {
        $font = self::font(self::codes(65, 97, 12), self::sids(34, 66, 109));

        $this->assertSame([65 => 'A', 97 => 'a', 12 => 'fi'], Cff::encoding($font));
    }

    public function testEncodingFormat1Ranges(): void
    {
        // Codes 48 to 50 are glyphs 1 to 3, code 65 is glyph 4.
        $encoding = "\x01\x02\x30\x02\x41\x00";
        $font = self::font($encoding, self::sids(17, 18, 19, 34));

        $this->assertSame([48 => 'zero', 49 => 'one', 50 => 'two', 65 => 'A'], Cff::encoding($font));
    }

    public function testSupplementAddsExtraCodes(): void
    {
        // Format 0 with the supplement bit: code 65 is glyph 1, and code 200 is also the fi ligature (SID 109).
        $encoding = "\x80\x01\x41" . "\x01\xC8\x00\x6D";
        $font = self::font($encoding, self::sids(34));

        $this->assertSame([65 => 'A', 200 => 'fi'], Cff::encoding($font));
    }

    public function testSupplementOnFormat1(): void
    {
        $encoding = "\x81\x01\x41\x01" . "\x01\xC8\x00\x6E";
        $font = self::font($encoding, self::sids(34, 35));

        $this->assertSame([65 => 'A', 66 => 'B', 200 => 'fl'], Cff::encoding($font));
    }

    public function testCharsetFormat1AndFormat2(): void
    {
        // Glyphs 1 to 3 are the three SIDs from 34: A, B, C.
        $format1 = self::font(self::codes(65, 66, 67), "\x01" . pack('n', 34) . "\x02");
        $format2 = self::font(self::codes(65, 66, 67), "\x02" . pack('n', 34) . pack('n', 2));
        // Two ranges: 66 and 67 (a, b), then 109 (fi).
        $twoRanges = self::font(self::codes(97, 98, 12), "\x01" . pack('n', 66) . "\x01" . pack('n', 109) . "\x00");

        $this->assertSame([65 => 'A', 66 => 'B', 67 => 'C'], Cff::encoding($format1));
        $this->assertSame([65 => 'A', 66 => 'B', 67 => 'C'], Cff::encoding($format2));
        $this->assertSame([97 => 'a', 98 => 'b', 12 => 'fi'], Cff::encoding($twoRanges));
    }

    public function testNamesFromTheStringIndex(): void
    {
        // SIDs 391 and 392 are the first two strings of the font, 393 is one the font does not have.
        $font = self::font(self::codes(11, 14, 15, 65), self::sids(391, 392, 393, 34), ['ff', 'ffi']);

        $this->assertSame([11 => 'ff', 14 => 'ffi', 65 => 'A'], Cff::encoding($font));
    }

    public function testIsoAdobeCharsetUsesTheGlyphNumberAsTheSid(): void
    {
        // Codes 1 to 229 are glyphs 1 to 229; ISOAdobe stops at glyph 228 (zcaron).
        $encoding = self::codes(...range(1, 229));
        $names = Cff::encoding(self::font($encoding, '', [], '', null, 0));

        $this->assertNotNull($names);
        $this->assertSame('space', $names[1]);
        $this->assertSame('A', $names[34]);
        $this->assertSame('zcaron', $names[228]);
        $this->assertArrayNotHasKey(229, $names);
    }

    public function testStandardAndExpertEncodingsAreLeftToTheCaller(): void
    {
        $charset = self::sids(34);

        $this->assertNull(Cff::encoding(self::font('', $charset, [], '', 0)));
        $this->assertNull(Cff::encoding(self::font('', $charset, [], '', 1)));
        $this->assertNull(Cff::encoding(self::font(self::codes(65), '', [], '', null, 1)));
        $this->assertNull(Cff::encoding(self::font(self::codes(65), '', [], '', null, 2)));
    }

    public function testCidKeyedFontHasNoEncoding(): void
    {
        // ROS (12 30) with three operands: registry SID, ordering SID, supplement.
        $ros = "\x1C\x01\x87\x1C\x01\x88\x8B\x0C\x1E";
        $font = self::font(self::codes(65), self::sids(34), ['Adobe', 'Identity'], $ros);

        $this->assertNull(Cff::encoding($font));
        $this->assertSame([65 => 'A'], Cff::encoding(self::font(self::codes(65), self::sids(34), ['Adobe', 'Identity'])));
    }

    public function testRealNumbersAndSmallIntegersInTheTopDict(): void
    {
        // version 1.5 (a real, operator 0), then the one-byte and two-byte integer forms before FontBBox (5).
        $extra = "\x1E\x1F\x0F\x00" . "\x8B\xF7\x00\xFB\x00\x1C\xFF\x38\x05";
        $font = self::font(self::codes(65), self::sids(34), [], $extra);

        $this->assertSame([65 => 'A'], Cff::encoding($font));
    }

    public function testOffsetSizeTwoAndFour(): void
    {
        foreach ([2, 3, 4] as $size) {
            $font = self::font(self::codes(65), self::sids(391), ['Gamma'], '', null, null, $size);
            $this->assertSame([65 => 'Gamma'], Cff::encoding($font), "offSize $size");
        }
    }

    public function testStandardStringsAreExact(): void
    {
        $pins = [
            0 => '.notdef', 1 => 'space', 2 => 'exclam', 17 => 'zero', 34 => 'A', 59 => 'Z', 66 => 'a', 91 => 'z',
            109 => 'fi', 110 => 'fl', 111 => 'endash', 149 => 'germandbls', 165 => 'registered', 228 => 'zcaron',
            229 => 'exclamsmall', 378 => 'Ydieresissmall', 379 => '001.000', 382 => '001.003', 383 => 'Black',
            384 => 'Bold', 387 => 'Medium', 388 => 'Regular', 389 => 'Roman', 390 => 'Semibold',
        ];
        foreach ($pins as $sid => $name) {
            $this->assertSame($name, Cff::standardString($sid), "SID $sid");
        }
        $this->assertNull(Cff::standardString(391));
        $this->assertNull(Cff::standardString(-1));
    }

    public function testOpenTypeWrapper(): void
    {
        $cff = self::font(self::codes(65, 12), self::sids(34, 109));
        $table = 'CFF ' . pack('N', 0) . pack('N', 28) . pack('N', strlen($cff));
        $otf = 'OTTO' . pack('n', 1) . str_repeat("\0", 6) . $table . $cff;

        $this->assertSame([65 => 'A', 12 => 'fi'], Cff::encoding($otf));
        $this->assertNull(Cff::encoding(substr($otf, 0, 40)));
        $this->assertNull(Cff::encoding(str_replace('CFF ', 'cmap', $otf)));
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        $good = self::font(self::codes(65, 97, 12), self::sids(34, 66, 391), ['ff']);

        yield 'empty' => [''];
        yield 'not a font' => ['%PDF-1.4 this is not a font program at all'];
        yield 'version 2' => ["\x02" . substr($good, 1)];
        yield 'header size 0' => [substr_replace($good, "\x00", 2, 1)];
        yield 'header size past the end' => [substr_replace($good, "\xFF", 2, 1)];
        yield 'name index offSize 0' => [substr_replace($good, "\x00", 6, 1)];
        yield 'name index offSize 5' => [substr_replace($good, "\x05", 6, 1)];
        yield 'name index count too big' => [substr_replace($good, "\xFF\xFF", 4, 2)];
        yield 'name index last offset past the end' => [substr_replace($good, "\xFF", 8, 1)];
        yield 'name index last offset 0' => [substr_replace($good, "\x00", 8, 1)];
        yield 'top dict index offSize 0' => [substr_replace($good, "\x00", 15, 1)];
        yield 'top dict count 0' => [substr_replace($good, "\x00\x00", 13, 2)];
        yield 'encoding offset past the end' => [self::font(self::codes(65), self::sids(34), [], '', 100000)];
        yield 'encoding offset negative' => [self::font(self::codes(65), self::sids(34), [], '', -1)];
        yield 'charset offset past the end' => [self::font(self::codes(65), self::sids(34), [], '', null, 100000)];
        yield 'encoding count larger than the data' => [self::font("\x00\xFF\x41", self::sids(34))];
        yield 'encoding ranges larger than the data' => [self::font("\x01\xFF\x41\x00", self::sids(34))];
        yield 'encoding naming too many glyphs' => [self::font("\x01\x03\x00\xFF\x00\xFF\x00\xFF", self::sids(34))];
        yield 'encoding format 7' => [self::font("\x07\x01\x41", self::sids(34))];
        yield 'supplement larger than the data' => [self::font("\x80\x01\x41\xFF\x01", self::sids(34))];
        yield 'charset format 9' => [self::font(self::codes(65), "\x09\x00\x22")];
        // The charset comes last, so it ends where the font does.
        $tail = strlen(self::font(self::codes(65, 66, 67), ''));
        yield 'charset shorter than the glyphs' => [self::font(self::codes(65, 66, 67), '', [], '', null, $tail) . self::sids(34)];
        yield 'charset range runs off the data' => [self::font(self::codes(65, 66, 67), '', [], '', null, $tail) . "\x01\x00\x22"];
        yield 'dict ends inside a number' => [self::font(self::codes(65), self::sids(34), [], "\x1D\x00")];
        yield 'dict reserved byte' => [self::font(self::codes(65), self::sids(34), [], "\x1F\x00")];
        yield 'dict ends after 12' => [self::font(self::codes(65), self::sids(34), [], "\x0C")];
        yield 'string index offSize 0' => [substr_replace(self::font(self::codes(65), self::sids(391), ['x']), "\x00", 32, 1)];
        yield 'open type with no tables' => ['OTTO' . pack('n', 0)];
        yield 'open type table past the end' => ['OTTO' . pack('n', 1) . str_repeat("\0", 6) . 'CFF ' . pack('N', 0) . pack('N', 28) . pack('N', 9999)];
        yield 'open type table count past the data' => ['OTTO' . pack('n', 65535) . str_repeat("\0", 6)];
    }

    #[DataProvider('malformed')]
    public function testMalformedProgramsHaveNoEncoding(string $data): void
    {
        $this->assertNull($this->withoutPhpErrors(fn() => Cff::encoding($data)));
    }

    public function testEveryTruncationIsHandled(): void
    {
        $good = self::font(self::codes(65, 97, 12), self::sids(34, 66, 391), ['ff']);

        for ($length = 0; $length < strlen($good); $length++) {
            $result = $this->withoutPhpErrors(fn() => Cff::encoding(substr($good, 0, $length)));
            $this->assertTrue($result === null || $result !== [], "length $length");
        }
        $this->assertNotNull(Cff::encoding($good));
    }

    public function testDamagedBytesNeverRaiseAnything(): void
    {
        $good = self::font(self::codes(65, 97, 12, 200), self::sids(34, 66, 391, 392), ['ff', 'ffi']);
        $supplement = self::font("\x81\x01\x41\x01\x01\xC8\x00\x6E", self::sids(34, 35));
        mt_srand(7);

        foreach ([$good, $supplement] as $base) {
            for ($i = 0; $i < 3000; $i++) {
                $data = $base;
                for ($flips = mt_rand(1, 3); $flips > 0; $flips--) {
                    $data[mt_rand(0, strlen($data) - 1)] = chr(mt_rand(0, 255));
                }
                $result = $this->withoutPhpErrors(fn() => Cff::encoding($data));
                $this->assertTrue($result === null || is_array($result));
            }
        }
    }

    public function testLigatureCodesDecodeThroughTheFontsOwnEncoding(): void
    {
        // A TeX font: /Differences names a few codes and leaves out the base encoding, so codes 12 and 14
        // are whatever the CFF program says they are. StandardEncoding has nothing there.
        $cff = self::font(self::codes(11, 12, 14, 15, 99), self::sids(391, 109, 392, 393, 394), ['ff', 'ffi', 'ffl', 'g99']);
        $pdf = PdfBuilder::build(['BT /F1 12 Tf 72 720 Td (E\016cient sub\014eld o\016ce o\013set \000\011 ca\143) Tj ET'], [
            'F1' => '/Subtype /Type1 /BaseFont /ABCDEF+CMR12 /FontDescriptor 100 0 R /Encoding << /Differences [0 /Gamma 9 /Psi] >>',
        ], [
            100 => '<< /Type /FontDescriptor /FontName /ABCDEF+CMR12 /Flags 4 /FontFile3 101 0 R >>',
            101 => PdfBuilder::stream($cff, true, '/Subtype /Type1C'),
        ]);

        // Ligatures are expanded to letters on the way out, so these come back as ordinary words.
        $this->assertSame("Efficient subfield office offset \u{0393}\u{03A8} cac", YetiPdf::parse($pdf)->text());
    }

    /**
     * @template T
     * @param callable(): T $call
     * @return T
     */
    private function withoutPhpErrors(callable $call): mixed
    {
        set_error_handler(static function (int $no, string $message): never {
            throw new \ErrorException($message, 0, $no);
        });
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
