<?php

declare(strict_types=1);

namespace YetiPdf\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YetiPdf\Font\CidCollection;
use YetiPdf\Tests\PdfBuilder;
use YetiPdf\YetiPdf;

final class CidCollectionTest extends TestCase
{
    /**
     * Every pair below was checked against the cid2code.txt of the matching collection in Adobe's cmap-resources.
     *
     * @return iterable<string, array{string, int, string}>
     */
    public static function knownCids(): iterable
    {
        yield 'Japan1 space' => ['Japan1', 1, ' '];
        yield 'Japan1 hiragana a' => ['Japan1', 843, 'あ'];
        yield 'Japan1 kanji a' => ['Japan1', 1125, '亜'];
        yield 'Japan1 above U+FFFF' => ['Japan1', 7641, "\u{28CDD}"];
        yield 'Japan1 vertical long vowel mark' => ['Japan1', 7891, 'ー'];
        yield 'Japan1 vertical double bar' => ['Japan1', 7895, "\u{2016}"];
        yield 'Japan1 several characters' => ['Japan1', 8295, 'XIII'];
        yield 'Japan1 last CID' => ['Japan1', 23059, "\u{32FF}"];
        yield 'GB1 middle' => ['GB1', 4559, '中'];
        yield 'GB1 vertical comma' => ['GB1', 573, '，'];
        yield 'GB1 above U+FFFF' => ['GB1', 22048, "\u{20087}"];
        yield 'CNS1 sweat' => ['CNS1', 1000, '汗'];
        yield 'CNS1 above U+FFFF' => ['CNS1', 14000, "\u{200CC}"];
        yield 'Korea1 ga' => ['Korea1', 1086, '가'];
        yield 'Korea1 only in cid2code.txt' => ['Korea1', 8637, '❖'];
        yield 'KR syllable' => ['KR', 5000, '꺪'];
        yield 'KR above U+FFFF' => ['KR', 11704, "\u{1F100}"];
    }

    #[DataProvider('knownCids')]
    public function testKnownCid(string $ordering, int $cid, string $expected): void
    {
        $this->assertSame($expected, (new CidCollection($ordering))->get($cid));
    }

    public function testCidsWithoutTextAreNull(): void
    {
        $japan1 = new CidCollection('Japan1');
        $this->assertNull($japan1->get(0));
        $this->assertNull($japan1->get(-5));
        $this->assertNull($japan1->get(23060));
        $this->assertNull($japan1->get(0xFFFF));
        $this->assertNull($japan1->get(PHP_INT_MAX));
        // The last CID of Adobe-CNS1 that has text is 19178, and 124 to 126 are glyphs with no character.
        $this->assertNull((new CidCollection('CNS1'))->get(124));
        $this->assertNull((new CidCollection('CNS1'))->get(19179));
    }

    public function testUnknownOrderingKnowsNothing(): void
    {
        foreach (['Identity', 'Japan2', 'japan1', '', '../Japan1', 'Japan1.bin'] as $ordering) {
            $this->assertFalse(CidCollection::supports($ordering), $ordering);
            $this->assertNull((new CidCollection($ordering))->get(843), $ordering);
        }
        $this->assertTrue(CidCollection::supports('Japan1'));
    }

    public function testEveryShippedTableLoads(): void
    {
        foreach (CidCollection::ORDERINGS as $ordering) {
            $this->assertTrue(CidCollection::supports($ordering), $ordering);
            $this->assertNotNull((new CidCollection($ordering))->get(1), $ordering);
        }
    }

    public function testJapanese1FontWithoutToUnicodeIsReadFromTheCollection(): void
    {
        $pdf = $this->pdf('Japan1', '<034B0465>');
        $doc = YetiPdf::parse($pdf);
        $this->assertSame('あ亜', $doc->text());
        $this->assertSame([], $doc->warnings());
    }

    public function testCollectionNameCanBeAnIndirectObjectAndPadded(): void
    {
        $pdf = PdfBuilder::build(
            ['BT /F1 10 Tf 72 720 Td <1BB1> Tj ET'],
            ['F1' => '/Subtype /Type0 /BaseFont /Custom /Encoding /Identity-V /DescendantFonts [101 0 R]'],
            [
                101 => '<< /Type /Font /Subtype /CIDFontType0 /DW 1000 /CIDSystemInfo 102 0 R >>',
                102 => '<< /Registry (Adobe) /Ordering <4B6F726561310000> /Supplement 2 >>',
            ]
        );
        $doc = YetiPdf::parse($pdf);
        // CID 0x1BB1 is 7089, which Adobe-Korea1 lists as U+7AD9.
        $this->assertSame('站', $doc->text());
        $this->assertSame([], $doc->warnings());
    }

    public function testToUnicodeStillWinsOverTheCollection(): void
    {
        $cmap = "1 begincodespacerange <0000> <FFFF> endcodespacerange\n1 beginbfchar <034B> <0058> endbfchar";
        $pdf = PdfBuilder::build(
            ['BT /F1 10 Tf 72 720 Td <034B> Tj ET'],
            ['F1' => '/Subtype /Type0 /BaseFont /Custom /Encoding /Identity-H /DescendantFonts [101 0 R] /ToUnicode 100 0 R'],
            [100 => PdfBuilder::stream($cmap), 101 => $this->cidFont('Japan1')]
        );
        $this->assertSame('X', YetiPdf::parse($pdf)->text());
    }

    /** @return iterable<string, array{string, string}> */
    public static function collectionsThatDoNotApply(): iterable
    {
        yield 'Identity ordering' => ['/Identity-H', '(Adobe) /Ordering (Identity)'];
        yield 'Japan2, which is not shipped' => ['/Identity-H', '(Adobe) /Ordering (Japan2)'];
        yield 'another registry' => ['/Identity-H', '(Acme) /Ordering (Japan1)'];
        yield 'no ordering at all' => ['/Identity-H', '(Adobe)'];
    }

    #[DataProvider('collectionsThatDoNotApply')]
    public function testFontStaysUnmappedWhenTheCollectionDoesNotApply(string $encoding, string $info): void
    {
        $pdf = PdfBuilder::build(
            ['BT /F1 10 Tf 72 720 Td <034B> Tj ET'],
            ['F1' => "/Subtype /Type0 /BaseFont /Custom /Encoding $encoding /DescendantFonts [101 0 R]"],
            [101 => "<< /Type /Font /Subtype /CIDFontType0 /DW 1000 /CIDSystemInfo << /Registry $info >> >>"]
        );
        $doc = YetiPdf::parse($pdf);
        $this->assertSame('', $doc->text());
        $this->assertNotEmpty($doc->warnings());
    }

    public function testCodesAreNotCidsWithoutIdentityEncoding(): void
    {
        // With some other code-to-CID CMap the code is not the CID, so the collection must not be used.
        $pdf = PdfBuilder::build(
            ['BT /F1 10 Tf 72 720 Td <034B> Tj ET'],
            ['F1' => '/Subtype /Type0 /BaseFont /Custom /Encoding /Custom-H /DescendantFonts [101 0 R]'],
            [101 => $this->cidFont('Japan1')]
        );
        $doc = YetiPdf::parse($pdf);
        $this->assertSame('', $doc->text());
        $this->assertNotEmpty($doc->warnings());
    }

    private function cidFont(string $ordering): string
    {
        return "<< /Type /Font /Subtype /CIDFontType0 /DW 1000 /CIDSystemInfo << /Registry (Adobe) /Ordering ($ordering) /Supplement 6 >> >>";
    }

    private function pdf(string $ordering, string $shown): string
    {
        return PdfBuilder::build(
            ["BT /F1 10 Tf 72 720 Td $shown Tj ET"],
            ['F1' => '/Subtype /Type0 /BaseFont /HiraMinProN-W3 /Encoding /Identity-H /DescendantFonts [101 0 R]'],
            [101 => $this->cidFont($ordering)]
        );
    }
}
