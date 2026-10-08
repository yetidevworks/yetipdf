<?php

declare(strict_types=1);

namespace YetiPdf\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YetiPdf\Core\File;
use YetiPdf\Core\Ref;
use YetiPdf\Font\Encodings;
use YetiPdf\Font\FontLoader;
use YetiPdf\Tests\PdfBuilder;

final class FontTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function encodings(): iterable
    {
        foreach (['WinAnsiEncoding', 'MacRomanEncoding', 'StandardEncoding', 'Symbol', 'ZapfDingbats', 'PDFDocEncoding'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('encodings')]
    public function testEveryByteRetainsItsEncoding(string $name): void
    {
        $file = new File(PdfBuilder::build([], ['F1' => "/Subtype /Type1 /BaseFont /Custom /Encoding /$name"]));
        $font = (new FontLoader($file))->load(new Ref(10));
        $bytes = '';
        for ($code = 0; $code < 256; $code++) {
            $bytes .= chr($code);
        }

        $table = Encodings::table($name);
        $expected = implode('', $table);
        $this->assertSame($expected, $font->decode($bytes));
        $this->assertSame(256, $font->n);
        $this->assertSame(1, $font->sp);
        $this->assertSame(implode('', array_reverse($table)), $font->decode(strrev($bytes)));
    }

    public function testDeletedExpandedAndRemappedBytesAreNotTranslatedAgain(): void
    {
        $file = new File(PdfBuilder::build([], [
            'F1' => '/Subtype /Type1 /BaseFont /Custom /Encoding << /BaseEncoding /WinAnsiEncoding /Differences [48 /one /two 65 /.notdef /f_f_i /A /D] >>',
        ]));
        $font = (new FontLoader($file))->load(new Ref(10));

        $this->assertSame('12ffiAD', $font->decode("\0A01BCD"));
        $this->assertSame(7, $font->n);
        $this->assertSame(0, $font->sp);
        $width = $font->w;
        $this->assertSame('12ffiAD', $font->decode("\0A01BCD"));
        $this->assertSame($width, $font->w);
    }

    public function testUnknownGlyphNamesKeepTheBaseEncoding(): void
    {
        // "CP" and "BT" are names a generator made up; the base encoding still says what the codes are.
        // A name that spells its own code in hex is Windows-encoded, which matters above 127.
        $file = new File(PdfBuilder::build([], [
            'F1' => '/Subtype /Type1 /BaseFont /Custom /Encoding << /BaseEncoding /StandardEncoding /Differences [65 /BT 97 /CP /.notdef 150 /C96 /endash] >>',
        ]));
        $font = (new FontLoader($file))->load(new Ref(10));

        $this->assertSame("Aac\u{2013}\u{2013}", $font->decode("Aabc\x96\x97"));
    }
}
