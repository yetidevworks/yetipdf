<?php

declare(strict_types=1);

namespace YetiPdf\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YetiPdf\Core\Lexer;
use YetiPdf\Core\PdfString;
use YetiPdf\Core\Ref;
use YetiPdf\Core\Stream;
use YetiPdf\Crypt\Decryptor;
use YetiPdf\Filter\Filters;
use YetiPdf\Font\GlyphList;

final class CoreTest extends TestCase
{
    public function testLexerValues(): void
    {
        $p = 0;
        $v = Lexer::value('<< /Name /A#20B /Int 12 /Neg -3.5 /Ref 7 0 R /Arr [1 (two) <33>] /T true /N null % c' . "\n" . '/S (a(b)c) >>', $p);

        $this->assertSame('/A B', $v['Name']);
        $this->assertSame(12, $v['Int']);
        $this->assertSame(-3.5, $v['Neg']);
        $this->assertEquals(new Ref(7, 0), $v['Ref']);
        $this->assertSame(1, $v['Arr'][0]);
        $this->assertEquals(new PdfString('two'), $v['Arr'][1]);
        $this->assertEquals(new PdfString('3'), $v['Arr'][2]);
        $this->assertTrue($v['T']);
        $this->assertNull($v['N']);
        $this->assertEquals(new PdfString('a(b)c'), $v['S']);
    }

    public function testLexerSurvivesGarbage(): void
    {
        $p = 0;
        $v = Lexer::value('<< /A 1 ) /B [1 2 > /C 3 >>', $p);
        $this->assertSame(1, $v['A']);
    }

    public function testIndirectStream(): void
    {
        [$num, $gen, $value] = Lexer::indirect("5 0 obj\n<< /Length 3 >>\nstream\nabc\nendstream\nendobj", 0);
        $this->assertSame(5, $num);
        $this->assertSame(0, $gen);
        $this->assertInstanceOf(Stream::class, $value);
        $this->assertSame(31, $value->start);
    }

    public function testFilters(): void
    {
        $text = str_repeat('The quick brown fox. ', 20);
        $this->assertSame($text, Filters::decode((string)gzcompress($text), ['/FlateDecode'], [null]));
        $this->assertSame('test', Filters::decode('FCfN8~>', ['/ASCII85Decode'], [null]));
        $this->assertSame('test', Filters::decode('74 65 73 74>', ['/ASCIIHexDecode'], [null]));
        $this->assertSame('aaaab', Filters::decode("\xFDa\x00b\x80", ['/RunLengthDecode'], [null]));
        $this->assertNull(Filters::decode('x', ['/DCTDecode'], [null]));
    }

    public function testTruncatedFlateStreamKeepsWhatItCan(): void
    {
        // Varied text, so the compressed stream is long enough that losing its tail still leaves most of it.
        $text = '';
        for ($i = 0; $i < 3000; $i++) {
            $text .= 'word' . (($i * 7919) % 100003) . ' ';
        }
        $out = Filters::flate(substr((string)gzcompress($text), 0, -500));

        $this->assertGreaterThan(strlen($text) / 2, strlen($out));
        $this->assertStringStartsWith($out, $text);
    }

    public function testPngUpPredictor(): void
    {
        // Two rows of three bytes, each prefixed with filter type 2 (Up).
        $data = "\x02\x01\x02\x03" . "\x02\x01\x01\x01";
        $this->assertSame("\x01\x02\x03\x02\x03\x04", Filters::predict($data, ['Predictor' => 12, 'Columns' => 3]));
    }

    public function testRc4(): void
    {
        $this->assertSame('bbf316e8d940af0ad3', bin2hex(Decryptor::rc4('Key', 'Plaintext')));
    }

    public function testGlyphNames(): void
    {
        $this->assertSame('É', GlyphList::toUnicode('Eacute'));
        $this->assertSame('ffi', GlyphList::toUnicode('f_f_i'));
        $this->assertSame('€', GlyphList::toUnicode('uni20AC'));
        $this->assertSame('a', GlyphList::toUnicode('a.sc'));
        $this->assertSame('', GlyphList::toUnicode('g123'));
    }
}
