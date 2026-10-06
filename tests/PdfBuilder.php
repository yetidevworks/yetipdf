<?php

declare(strict_types=1);

namespace YetiPdf\Tests;

/** Writes small, valid PDFs for tests: one page per content stream, with whatever font objects the test supplies. */
final class PdfBuilder
{
    /** @var array<int, string> */
    private array $objects = [];

    /**
     * @param list<string> $pages content stream of each page
     * @param array<string, string> $fonts resource name => font dictionary body (without the << >>)
     * @param array<int, string> $extra extra objects, numbered from 100, as full object bodies
     */
    public static function build(array $pages, array $fonts = [], array $extra = [], bool $compress = false): string
    {
        $b = new self();
        $fontRefs = '';
        $num = 10;
        foreach ($fonts as $name => $body) {
            $b->objects[$num] = "<< /Type /Font $body >>";
            $fontRefs .= "/$name $num 0 R ";
            $num++;
        }
        if ($fonts === []) {
            $b->objects[$num] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
            $fontRefs = "/F1 $num 0 R";
        }
        foreach ($extra as $n => $body) {
            $b->objects[$n] = $body;
        }

        $kids = '';
        $num = 30;
        foreach ($pages as $content) {
            $pageNum = $num++;
            $contentNum = $num++;
            $kids .= "$pageNum 0 R ";
            $b->objects[$pageNum] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << $fontRefs >> >> /Contents $contentNum 0 R >>";
            $b->objects[$contentNum] = self::stream($content, $compress);
        }
        $b->objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $b->objects[2] = '<< /Type /Pages /Kids [' . trim($kids) . '] /Count ' . count($pages) . ' >>';
        return $b->write();
    }

    public static function stream(string $data, bool $compress = false, string $dict = ''): string
    {
        if ($compress) {
            $data = (string)gzcompress($data);
            $dict .= ' /Filter /FlateDecode';
        }
        return '<< /Length ' . strlen($data) . " $dict >>\nstream\n$data\nendstream";
    }

    private function write(): string
    {
        ksort($this->objects);
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($this->objects as $num => $body) {
            $offsets[$num] = strlen($out);
            $out .= "$num 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($out);
        $max = max(array_keys($this->objects));
        $out .= "xref\n0 " . ($max + 1) . "\n";
        for ($i = 0; $i <= $max; $i++) {
            $out .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
        }
        return $out . 'trailer << /Size ' . ($max + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}
