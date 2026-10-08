<?php

declare(strict_types=1);

namespace YetiPdf;

use YetiPdf\Content\Interpreter;
use YetiPdf\Core\File;
use YetiPdf\Core\PdfString;
use YetiPdf\Core\Ref;
use YetiPdf\Core\Stream;
use YetiPdf\Font\Encodings;
use YetiPdf\Font\FontLoader;
use YetiPdf\Font\Utf;

/**
 * An opened PDF.
 *
 * Pages are read one at a time, so memory stays flat however long the document is. A page that
 * cannot be read comes back as an empty string with a note in warnings(); it never stops the rest.
 */
final class Document
{
    private const LIGATURES = [
        "\u{FB00}" => 'ff', "\u{FB01}" => 'fi', "\u{FB02}" => 'fl', "\u{FB03}" => 'ffi', "\u{FB04}" => 'ffl',
        "\u{FB05}" => 'st', "\u{FB06}" => 'st', "\u{00AD}" => '', "\u{00A0}" => ' ', "\u{2009}" => ' ', "\u{200B}" => '',
    ];

    private readonly FontLoader $fonts;
    private readonly Interpreter $interpreter;

    public function __construct(private readonly File $file, private readonly Options $options)
    {
        $this->fonts = new FontLoader($file);
        $this->interpreter = new Interpreter($file, $this->fonts);
        $this->interpreter->wordGap = $options->wordGap;
        $this->interpreter->dehyphenate = $options->dehyphenate;
    }

    /**
     * Text of every page, in order.
     *
     * @return \Generator<int, string> page number (from 1) => text
     */
    public function pages(): \Generator
    {
        $number = 0;
        foreach ($this->walk() as [$page, $resources]) {
            $number++;
            try {
                yield $number => $this->pageText($page, $resources);
            } catch (\Throwable $e) {
                $this->file->warn("Page $number could not be read: " . $e->getMessage());
                yield $number => '';
            }
        }
    }

    /** Text of one page, numbered from 1. Only that page is read; the ones before it are stepped over. */
    public function page(int $number): string
    {
        $n = 0;
        foreach ($this->walk() as [$page, $resources]) {
            if (++$n !== $number) {
                continue;
            }
            try {
                return $this->pageText($page, $resources);
            } catch (\Throwable $e) {
                $this->file->warn("Page $number could not be read: " . $e->getMessage());
                return '';
            }
        }
        return '';
    }

    public function text(string $separator = "\n\n"): string
    {
        $out = [];
        foreach ($this->pages() as $text) {
            $out[] = $text;
        }
        return implode($separator, $out);
    }

    public function pageCount(): int
    {
        $catalog = $this->file->dict($this->file->trailer()['Root'] ?? null) ?? [];
        $pages = $this->file->dict($catalog['Pages'] ?? null) ?? [];
        $count = $this->file->resolve($pages['Count'] ?? null);
        if (is_int($count) && $count > 0) {
            return $count;
        }
        $n = 0;
        foreach ($this->walk() as $_) {
            $n++;
        }
        return $n;
    }

    public function isEncrypted(): bool
    {
        return $this->file->isEncrypted();
    }

    /**
     * Document metadata from the information dictionary: Title, Author, Subject, Keywords, Creator, Producer...
     *
     * @return array<string, string>
     */
    public function info(): array
    {
        $ref = $this->file->trailer()['Info'] ?? null;
        $dict = $this->file->dict($ref);
        if ($dict === null) {
            return [];
        }
        $num = $ref instanceof Ref ? $ref->num : 0;
        $gen = $ref instanceof Ref ? $ref->gen : 0;
        $out = [];
        foreach ($dict as $key => $value) {
            $value = $this->file->resolve($value);
            if (!$value instanceof PdfString) {
                continue;
            }
            $bytes = $num > 0 ? $this->file->decryptString($value, $num, $gen) : $value->value;
            $out[$key] = trim(self::textString($bytes));
        }
        return $out;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->file->warnings;
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $resources
     */
    private function pageText(array $page, array $resources): string
    {
        $contents = $this->file->resolve($page['Contents'] ?? null);
        $parts = [];
        // The pieces of a page are joined into one string, so together they get what one stream would.
        $room = $this->file->limit();
        foreach ($contents instanceof Stream ? [$contents] : (is_array($contents) ? $contents : []) as $item) {
            $stream = $this->file->resolve($item);
            if ($stream instanceof Stream) {
                if ($room <= 0) {
                    $this->file->warn('The content of a page is too large for the memory that is left; the rest of it was left out');
                    break;
                }
                $part = $this->file->streamData($stream, true, $room) ?? '';
                $room -= strlen($part);
                $parts[] = $part;
            }
        }
        $appearances = $this->options->annotations ? $this->appearances($page) : [];
        if ($parts === [] && $appearances === []) {
            return '';
        }
        return $this->clean($this->interpreter->page(implode("\n", $parts), $resources, $appearances));
    }

    /**
     * Appearance streams of the visible annotations, which is where their text is: notes typed onto the page,
     * form field values, stamps, and the labels on media players. Links and pop-up windows draw none.
     *
     * @param array<string, mixed> $page
     * @return list<array{0: Stream, 1: float, 2: float}>
     */
    private function appearances(array $page): array
    {
        $file = $this->file;
        $annots = $file->resolve($page['Annots'] ?? null);
        if (!is_array($annots)) {
            return [];
        }
        $out = [];
        foreach ($annots as $ref) {
            // No more appearances than the page will ever draw: past that, the rest are never reached anyway.
            if (count($out) >= $this->interpreter->maxFormRuns) {
                break;
            }
            $annot = $file->dict($ref);
            $subtype = $annot['Subtype'] ?? null;
            if ($annot === null || $subtype === '/Link' || $subtype === '/Popup') {
                continue;
            }
            // Bit 2 is Hidden, bit 6 is NoView.
            if (((int)$file->resolve($annot['F'] ?? 0)) & 0x22) {
                continue;
            }
            $ap = $file->dict($annot['AP'] ?? null);
            $normal = $file->resolve($ap['N'] ?? null);
            if (is_array($normal)) {
                $state = $file->resolve($annot['AS'] ?? null);
                $normal = is_string($state) ? $file->resolve($normal[substr($state, 1)] ?? null) : null;
            }
            if (!$normal instanceof Stream) {
                continue;
            }
            $rect = $file->resolve($annot['Rect'] ?? null);
            $x = is_array($rect) && count($rect) >= 4 ? min((float)$file->resolve($rect[0]), (float)$file->resolve($rect[2])) : 0.0;
            $y = is_array($rect) && count($rect) >= 4 ? min((float)$file->resolve($rect[1]), (float)$file->resolve($rect[3])) : 0.0;
            $out[] = [$normal, $x, $y];
        }
        return $out;
    }

    private function clean(string $text): string
    {
        if ($text === '') {
            return '';
        }
        if ($this->options->expandLigatures) {
            $text = strtr($text, self::LIGATURES);
        }
        // Control characters out, runs of spaces down to one, spaces at line ends gone.
        $text = preg_replace(['/[\x00-\x09\x0B-\x1F\x7F]+/', '/ {2,}/', '/ *\n */', '/\n{3,}/'], [' ', ' ', "\n", "\n\n"], $text) ?? $text;
        return trim($text);
    }

    /**
     * Walks the page tree depth first.
     *
     * @return \Generator<int, array{0: array<string, mixed>, 1: array<string, mixed>}> page dictionary and its resources
     */
    private function walk(): \Generator
    {
        $file = $this->file;
        $catalog = $file->dict($file->trailer()['Root'] ?? null) ?? [];
        $root = $catalog['Pages'] ?? null;
        $seen = [];
        $found = 0;

        $stack = [[$root, []]];
        while ($stack !== []) {
            [$ref, $inherited] = array_pop($stack);
            if ($ref instanceof Ref) {
                if (isset($seen[$ref->num])) {
                    continue;
                }
                $seen[$ref->num] = true;
            }
            $node = $file->dict($ref);
            if ($node === null) {
                continue;
            }
            $resources = $file->dict($node['Resources'] ?? null) ?? $inherited;
            $kids = $file->resolve($node['Kids'] ?? null);
            if (is_array($kids) && ($node['Type'] ?? null) !== '/Page') {
                foreach (array_reverse($kids) as $kid) {
                    $stack[] = [$kid, $resources];
                }
                continue;
            }
            $found++;
            yield [$node, $resources];
        }

        if ($found > 0) {
            return;
        }
        // No usable page tree: fall back to every object that says it is a page, in file order.
        foreach ($file->objectNumbers() as $num) {
            $node = $file->get($num);
            if (is_array($node) && ($node['Type'] ?? null) === '/Page') {
                yield [$node, $file->dict($node['Resources'] ?? null) ?? []];
            }
        }
    }

    private static function textString(string $bytes): string
    {
        if (str_starts_with($bytes, "\xFE\xFF")) {
            return Utf::utf16(substr($bytes, 2));
        }
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return substr($bytes, 3);
        }
        $table = Encodings::table('PDFDocEncoding');
        $out = '';
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $out .= $table[ord($bytes[$i])];
        }
        return $out;
    }
}
