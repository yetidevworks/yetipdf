<?php

declare(strict_types=1);

namespace YetiPdf\Content;

use YetiPdf\Core\File;
use YetiPdf\Core\Lexer;
use YetiPdf\Core\Stream;
use YetiPdf\Font\Font;
use YetiPdf\Font\FontLoader;

/**
 * Runs a page's content stream and collects the text it draws.
 *
 * Only the operators that affect text are acted on: text state, text positioning, text showing,
 * the graphics state stack, the transformation matrix and form XObjects. Everything else is
 * tokenized and dropped.
 *
 * Words are rebuilt from geometry. Each shown string has a start and an end on the page; the gap
 * between one string's end and the next one's start decides whether they belong to the same word,
 * need a space between them, or sit on different lines.
 */
final class Interpreter
{
    private const MAX_FORM_DEPTH = 12;

    /** Gap, as a fraction of the font size, that separates two words. */
    public float $wordGap = 0.11;
    /** Distance off the baseline, as a fraction of the font size, that starts a new line. */
    public float $lineGap = 0.5;
    public bool $dehyphenate = true;

    /** Spacing accents and the combining mark each one becomes when it is drawn over a letter. */
    private const ACCENTS = [
        "\u{00B4}" => "\u{0301}", '`' => "\u{0300}", "\u{02C6}" => "\u{0302}", '^' => "\u{0302}", "\u{02DC}" => "\u{0303}",
        '~' => "\u{0303}", "\u{00A8}" => "\u{0308}", "\u{00AF}" => "\u{0304}", "\u{02D8}" => "\u{0306}", "\u{02D9}" => "\u{0307}",
        "\u{02DA}" => "\u{030A}", "\u{00B8}" => "\u{0327}", "\u{02DD}" => "\u{030B}", "\u{02DB}" => "\u{0328}", "\u{02C7}" => "\u{030C}",
    ];

    /** Letter + combining mark => single character, for when the intl extension is not there to do it. */
    private const COMPOSED = [
        "\u{0301}" => ['a' => 'á', 'e' => 'é', 'i' => 'í', 'o' => 'ó', 'u' => 'ú', 'y' => 'ý', 'A' => 'Á', 'E' => 'É', 'I' => 'Í', 'O' => 'Ó', 'U' => 'Ú', 'Y' => 'Ý', 'c' => 'ć', 'n' => 'ń', 's' => 'ś', 'z' => 'ź', 'C' => 'Ć', 'N' => 'Ń', 'S' => 'Ś', 'Z' => 'Ź', "\u{0131}" => 'í'],
        "\u{0300}" => ['a' => 'à', 'e' => 'è', 'i' => 'ì', 'o' => 'ò', 'u' => 'ù', 'A' => 'À', 'E' => 'È', 'I' => 'Ì', 'O' => 'Ò', 'U' => 'Ù', "\u{0131}" => 'ì'],
        "\u{0302}" => ['a' => 'â', 'e' => 'ê', 'i' => 'î', 'o' => 'ô', 'u' => 'û', 'A' => 'Â', 'E' => 'Ê', 'I' => 'Î', 'O' => 'Ô', 'U' => 'Û', "\u{0131}" => 'î'],
        "\u{0303}" => ['a' => 'ã', 'n' => 'ñ', 'o' => 'õ', 'A' => 'Ã', 'N' => 'Ñ', 'O' => 'Õ'],
        "\u{0308}" => ['a' => 'ä', 'e' => 'ë', 'i' => 'ï', 'o' => 'ö', 'u' => 'ü', 'y' => 'ÿ', 'A' => 'Ä', 'E' => 'Ë', 'I' => 'Ï', 'O' => 'Ö', 'U' => 'Ü', "\u{0131}" => 'ï'],
        "\u{030A}" => ['a' => 'å', 'A' => 'Å', 'u' => 'ů', 'U' => 'Ů'],
        "\u{0327}" => ['c' => 'ç', 'C' => 'Ç', 's' => 'ş', 'S' => 'Ş', 't' => 'ţ', 'T' => 'Ţ'],
        "\u{030C}" => ['c' => 'č', 'e' => 'ě', 'n' => 'ň', 'r' => 'ř', 's' => 'š', 'z' => 'ž', 'C' => 'Č', 'E' => 'Ě', 'N' => 'Ň', 'R' => 'Ř', 'S' => 'Š', 'Z' => 'Ž'],
        "\u{0306}" => ['a' => 'ă', 'A' => 'Ă', 'g' => 'ğ', 'G' => 'Ğ'],
        "\u{030B}" => ['o' => 'ő', 'u' => 'ű', 'O' => 'Ő', 'U' => 'Ű'],
        "\u{0328}" => ['a' => 'ą', 'e' => 'ę', 'A' => 'Ą', 'E' => 'Ę'],
        "\u{0307}" => ['z' => 'ż', 'Z' => 'Ż', 'I' => 'İ'],
    ];

    /** First bytes of numeric tokens, checked without numeric string comparisons. */
    private const NUMBER_START = [
        '0' => true, '1' => true, '2' => true, '3' => true, '4' => true,
        '5' => true, '6' => true, '7' => true, '8' => true, '9' => true,
        '-' => true, '.' => true, '+' => true,
    ];

    private static ?string $tokenPattern = null;

    private string $out = '';
    private bool $hasPrev = false;
    private float $px = 0.0;
    private float $py = 0.0;
    private float $pux = 1.0;
    private float $puy = 0.0;
    private float $psize = 0.0;
    private float $psx = 0.0;
    private float $psy = 0.0;
    private string $ptext = '';
    /** Where in the output a trailing accent starts (for a lone accent, before the glue in front of it), or -1. */
    private int $accentAt = -1;
    private string $accentMark = '';
    private bool $accentLone = false;
    private bool $accentHadPrev = false;
    private float $ax = 0.0;
    private float $ay = 0.0;

    // Current transformation matrix.
    private float $a = 1.0;
    private float $b = 0.0;
    private float $c = 0.0;
    private float $d = 1.0;
    private float $e = 0.0;
    private float $f = 0.0;

    // Text matrix and line matrix.
    private float $ta = 1.0;
    private float $tb = 0.0;
    private float $tc = 0.0;
    private float $td = 1.0;
    private float $te = 0.0;
    private float $tf = 0.0;
    private float $la = 1.0;
    private float $lb = 0.0;
    private float $lc = 0.0;
    private float $ld = 1.0;
    private float $le = 0.0;
    private float $lf = 0.0;

    private ?Font $font = null;
    private float $size = 0.0;
    private float $charSpace = 0.0;
    private float $wordSpace = 0.0;
    private float $hScale = 1.0;
    private float $leading = 0.0;

    public function __construct(private readonly File $file, private readonly FontLoader $fonts)
    {
    }

    /**
     * @param array<string, mixed> $resources
     * @param list<array{0: Stream, 1: float, 2: float}> $appearances annotation appearance streams with the
     *        page position of each, drawn after the page content
     */
    public function page(string $content, array $resources, array $appearances = []): string
    {
        $this->out = '';
        $this->hasPrev = false;
        $this->accentAt = -1;
        $this->a = $this->d = 1.0;
        $this->b = $this->c = $this->e = $this->f = 0.0;
        $this->font = null;
        $this->size = $this->charSpace = $this->wordSpace = $this->leading = 0.0;
        $this->hScale = 1.0;

        $this->run($content, $resources, 0, []);

        foreach ($appearances as [$stream, $x, $y]) {
            $data = $this->file->streamData($stream);
            if ($data === null || $data === '') {
                continue;
            }
            $this->a = $this->d = 1.0;
            $this->b = $this->c = 0.0;
            $this->e = $x;
            $this->f = $y;
            $this->font = null;
            $this->charSpace = $this->wordSpace = 0.0;
            $this->hScale = 1.0;
            // Push the previous end point far away so the annotation starts on its own line.
            $this->px = $this->py = -1.0e9;
            $matrix = $this->file->resolve($stream->dict['Matrix'] ?? null);
            if (is_array($matrix) && count($matrix) >= 6) {
                $this->concat(...array_map(fn($v) => (float)$this->file->resolve($v), array_slice($matrix, 0, 6)));
            }
            $this->run($data, $this->file->dict($stream->dict['Resources'] ?? null) ?? $resources, 1, [$stream->num => true]);
        }
        return $this->out;
    }

    /**
     * @param array<string, mixed> $resources
     * @param array<int, true> $active form XObjects currently being drawn, to stop a form drawing itself
     */
    private function run(string $content, array $resources, int $depth, array $active): void
    {
        // Inline images carry raw binary between ID and EI, which must not reach the tokenizer.
        if (str_contains($content, 'ID') && preg_match('/(?<![A-Za-z0-9])BI[\s\/]/', $content)) {
            $content = preg_replace('/(?<![A-Za-z0-9])BI[\s\/].*?\sID\s.*?(?:\sEI(?![A-Za-z0-9])|$)/s', ' ', $content) ?? $content;
        }

        self::$tokenPattern ??= self::buildTokenPattern();
        if (!preg_match_all(self::$tokenPattern, $content, $m)) {
            return;
        }
        unset($content);

        $fontDict = null;
        $fontCache = [];
        $stack = [];
        $array = null;
        $saved = [];

        foreach ($m[0] as $tok) {
            $ch = $tok[0];

            if (isset(self::NUMBER_START[$ch])) {
                // One token holds every number up to the next operator, so the thousands of path
                // coordinates on a page cost one loop step per operator rather than one per number.
                if ($array === null) {
                    $stack[] = $tok;
                } elseif (strpbrk($tok, " \n\r\t") === false) {
                    $array[] = (float)$tok;
                } else {
                    foreach (self::numbers($tok) as $number) {
                        $array[] = $number;
                    }
                }
                continue;
            }
            if ($ch === '(' || $ch === '<') {
                if ($tok === '<<') {
                    continue;
                }
                if ($array !== null) {
                    $array[] = $tok;
                } else {
                    $stack[] = $tok;
                }
                continue;
            }
            if ($ch === '/') {
                $stack[] = $tok;
                continue;
            }
            if ($ch === '[') {
                $array = [];
                continue;
            }
            if ($ch === ']') {
                $stack[] = $array ?? [];
                $array = null;
                continue;
            }
            if ($ch === '%' || $ch === '>') {
                continue;
            }

            // An operator.
            switch ($tok) {
                case 'Tj':
                    $s = end($stack);
                    if (is_string($s)) {
                        $this->show(self::bytes($s));
                    }
                    break;

                case 'TJ':
                    $items = end($stack);
                    if (is_array($items)) {
                        // Kerned text arrives as many short strings with small adjustments between them.
                        // Adjustments too small to be a word gap are folded into one run, so the costly
                        // part (decoding and positioning) happens once per word instead of once per fragment.
                        $run = '';
                        $kern = 0.0;
                        $gap = -900.0 * $this->wordGap;
                        foreach ($items as $item) {
                            if (is_string($item)) {
                                $run .= ($item[0] === '(' && !str_contains($item, '\\')) ? substr($item, 1, -1) : self::bytes($item);
                            } elseif ($item > $gap && $item <= 200.0 && $run !== '') {
                                $kern += $item;
                            } else {
                                if ($run !== '') {
                                    $this->show($run);
                                    $run = '';
                                }
                                $tx = -($item + $kern) * 0.001 * $this->size * $this->hScale;
                                $this->te += $tx * $this->ta;
                                $this->tf += $tx * $this->tb;
                                $kern = 0.0;
                            }
                        }
                        if ($run !== '') {
                            $this->show($run);
                        }
                        if ($kern !== 0.0) {
                            $tx = -$kern * 0.001 * $this->size * $this->hScale;
                            $this->te += $tx * $this->ta;
                            $this->tf += $tx * $this->tb;
                        }
                    }
                    break;

                case 'Td':
                case 'TD':
                    $v = self::numbers((string)end($stack));
                    $n = count($v);
                    if ($n >= 2) {
                        $tx = $v[$n - 2];
                        $ty = $v[$n - 1];
                        if ($tok === 'TD') {
                            $this->leading = -$ty;
                        }
                        $this->le += $tx * $this->la + $ty * $this->lc;
                        $this->lf += $tx * $this->lb + $ty * $this->ld;
                        $this->ta = $this->la;
                        $this->tb = $this->lb;
                        $this->tc = $this->lc;
                        $this->td = $this->ld;
                        $this->te = $this->le;
                        $this->tf = $this->lf;
                    }
                    break;

                case 'Tm':
                    $v = self::numbers((string)end($stack));
                    $n = count($v);
                    if ($n >= 6) {
                        $this->ta = $this->la = $v[$n - 6];
                        $this->tb = $this->lb = $v[$n - 5];
                        $this->tc = $this->lc = $v[$n - 4];
                        $this->td = $this->ld = $v[$n - 3];
                        $this->te = $this->le = $v[$n - 2];
                        $this->tf = $this->lf = $v[$n - 1];
                    }
                    break;

                case 'T*':
                    $this->nextLine();
                    break;

                case "'":
                    $this->nextLine();
                    $s = end($stack);
                    if (is_string($s)) {
                        $this->show(self::bytes($s));
                    }
                    break;

                case '"':
                    $n = count($stack);
                    $v = $n >= 2 ? self::numbers((string)$stack[$n - 2]) : [];
                    if (count($v) >= 2) {
                        $this->wordSpace = $v[count($v) - 2];
                        $this->charSpace = $v[count($v) - 1];
                    }
                    $this->nextLine();
                    $s = end($stack);
                    if (is_string($s)) {
                        $this->show(self::bytes($s));
                    }
                    break;

                case 'Tf':
                    $n = count($stack);
                    if ($n >= 2 && is_string($stack[$n - 2])) {
                        $name = substr($stack[$n - 2], 1);
                        $this->size = self::last($stack[$n - 1]);
                        if (!array_key_exists($name, $fontCache)) {
                            $fontDict ??= $this->file->dict($resources['Font'] ?? null) ?? [];
                            $fontCache[$name] = isset($fontDict[$name]) ? $this->fonts->load($fontDict[$name]) : null;
                        }
                        $this->font = $fontCache[$name];
                    }
                    break;

                case 'BT':
                    $this->ta = $this->td = $this->la = $this->ld = 1.0;
                    $this->tb = $this->tc = $this->te = $this->tf = 0.0;
                    $this->lb = $this->lc = $this->le = $this->lf = 0.0;
                    break;

                case 'Tc':
                    $this->charSpace = self::last(end($stack));
                    break;
                case 'Tw':
                    $this->wordSpace = self::last(end($stack));
                    break;
                case 'Tz':
                    $this->hScale = $stack === [] ? 1.0 : self::last(end($stack)) / 100;
                    break;
                case 'TL':
                    $this->leading = self::last(end($stack));
                    break;

                case 'cm':
                    $v = self::numbers((string)end($stack));
                    $n = count($v);
                    if ($n >= 6) {
                        $this->concat($v[$n - 6], $v[$n - 5], $v[$n - 4], $v[$n - 3], $v[$n - 2], $v[$n - 1]);
                    }
                    break;

                case 'q':
                    $saved[] = $this->snapshot();
                    break;
                case 'Q':
                    if ($saved !== []) {
                        $this->restore(array_pop($saved));
                    }
                    break;

                case 'Do':
                    $name = end($stack);
                    if (is_string($name) && $depth < self::MAX_FORM_DEPTH) {
                        $this->form(substr($name, 1), $resources, $depth, $active);
                    }
                    break;
            }
            $stack = [];
        }
    }

    private function nextLine(): void
    {
        $ty = -$this->leading;
        $this->le += $ty * $this->lc;
        $this->lf += $ty * $this->ld;
        $this->ta = $this->la;
        $this->tb = $this->lb;
        $this->tc = $this->lc;
        $this->td = $this->ld;
        $this->te = $this->le;
        $this->tf = $this->lf;
    }

    private function show(string $s): void
    {
        $font = $this->font;
        if ($font === null || $s === '') {
            return;
        }
        $text = $font->decode($s);
        $advance = ($font->w * $this->size + $font->n * $this->charSpace + $font->sp * $this->wordSpace) * $this->hScale;

        if ($text !== '') {
            // Text space to page space.
            $ma = $this->ta * $this->a + $this->tb * $this->c;
            $mb = $this->ta * $this->b + $this->tb * $this->d;
            $mc = $this->tc * $this->a + $this->td * $this->c;
            $md = $this->tc * $this->b + $this->td * $this->d;
            $x = $this->te * $this->a + $this->tf * $this->c + $this->e;
            $y = $this->te * $this->b + $this->tf * $this->d + $this->f;

            $hLen = sqrt($ma * $ma + $mb * $mb);
            if ($hLen < 1e-9) {
                $hLen = 1.0;
                $ux = 1.0;
                $uy = 0.0;
            } else {
                $ux = $ma / $hLen;
                $uy = $mb / $hLen;
            }
            $size = abs($this->size) * sqrt($mc * $mc + $md * $md);
            if ($size < 1e-6) {
                $size = abs($this->size) * $hLen;
            }

            $glue = '';
            if ($this->hasPrev) {
                $dx = $x - $this->px;
                $dy = $y - $this->py;
                $along = $dx * $this->pux + $dy * $this->puy;
                $off = abs($dy * $this->pux - $dx * $this->puy);
                $ref = $size > $this->psize ? $size : $this->psize;

                if ($this->accentAt >= 0 && $off <= $this->lineGap * $ref && $along < 0.05 * $ref && $along > -1.5 * $ref) {
                    // The text so far ends in an accent and this letter is drawn back on top of it (how TeX
                    // builds accented letters). Take the accent back out and attach it to the letter.
                    $text = self::compose($text, $this->accentMark);
                    $this->out = substr($this->out, 0, $this->accentAt);
                    if ($this->accentLone && $this->accentHadPrev
                        && ($x - $this->ax) * $this->pux + ($y - $this->ay) * $this->puy > $this->wordGap * $ref) {
                        $glue = ' ';
                    }
                } elseif ($off > $this->lineGap * $ref || ($ux * $this->pux + $uy * $this->puy) < 0.9) {
                    $glue = "\n";
                } elseif ($along > $this->wordGap * $ref) {
                    $glue = ' ';
                } elseif ($along < -0.5 * $ref) {
                    // Jumped backwards on the same line. The same text drawn again at the same spot is fake bold.
                    if ($text === $this->ptext && abs($x - $this->psx) + abs($y - $this->psy) < 0.2 * $ref) {
                        $this->advance($advance);
                        return;
                    }
                    $glue = ' ';
                }
            }

            $before = strlen($this->out);

            if ($glue === "\n") {
                $out = $this->out;
                $len = strlen($out);
                // "exam-" at a line end followed by "ple": put the word back together.
                if ($this->dehyphenate && $len > 1 && $out[$len - 1] === '-' && ctype_alpha($out[$len - 2])
                    && ($text[0] >= 'a' && $text[0] <= 'z')) {
                    $this->out = substr($out, 0, -1) . $text;
                } else {
                    $this->out .= "\n" . $text;
                }
            } elseif ($glue === ' ' && $text[0] !== ' ' && !str_ends_with($this->out, ' ')) {
                $this->out .= ' ' . $text;
            } else {
                $this->out .= $text;
            }

            $last = $text[strlen($text) - 1];
            $tail = $last < "\x80" ? $last : substr($text, -2);
            if (isset(self::ACCENTS[$tail])) {
                $this->accentMark = self::ACCENTS[$tail];
                $this->accentLone = $tail === $text;
                if ($this->accentLone) {
                    $this->accentAt = $before;
                    $this->accentHadPrev = $this->hasPrev;
                    $this->ax = $this->px;
                    $this->ay = $this->py;
                } else {
                    $this->accentAt = strlen($this->out) - strlen($tail);
                }
            } else {
                $this->accentAt = -1;
            }

            $this->hasPrev = true;
            $this->psx = $x;
            $this->psy = $y;
            $this->ptext = $text;
            $this->px = $x + $ux * $advance * $hLen;
            $this->py = $y + $uy * $advance * $hLen;
            $this->pux = $ux;
            $this->puy = $uy;
            $this->psize = $size;
        }

        $this->te += $advance * $this->ta;
        $this->tf += $advance * $this->tb;
    }

    private static function compose(string $text, string $mark): string
    {
        $first = $text[0];
        $len = $first < "\x80" ? 1 : ($first < "\xE0" ? 2 : ($first < "\xF0" ? 3 : 4));
        $base = substr($text, 0, $len);
        if (class_exists(\Normalizer::class, false)) {
            $composed = \Normalizer::normalize($base . $mark, \Normalizer::FORM_C);
            $composed = is_string($composed) ? $composed : $base;
        } else {
            $composed = self::COMPOSED[$mark][$base] ?? $base;
        }
        return $composed . substr($text, $len);
    }

    private function advance(float $advance): void
    {
        $this->te += $advance * $this->ta;
        $this->tf += $advance * $this->tb;
    }

    /** CTM = M x CTM */
    private function concat(float $a, float $b, float $c, float $d, float $e, float $f): void
    {
        $na = $a * $this->a + $b * $this->c;
        $nb = $a * $this->b + $b * $this->d;
        $nc = $c * $this->a + $d * $this->c;
        $nd = $c * $this->b + $d * $this->d;
        $ne = $e * $this->a + $f * $this->c + $this->e;
        $nf = $e * $this->b + $f * $this->d + $this->f;
        [$this->a, $this->b, $this->c, $this->d, $this->e, $this->f] = [$na, $nb, $nc, $nd, $ne, $nf];
    }

    /** @return array<int, mixed> */
    private function snapshot(): array
    {
        return [
            $this->a, $this->b, $this->c, $this->d, $this->e, $this->f,
            $this->font, $this->size, $this->charSpace, $this->wordSpace, $this->hScale, $this->leading,
        ];
    }

    /** @param array<int, mixed> $s */
    private function restore(array $s): void
    {
        [
            $this->a, $this->b, $this->c, $this->d, $this->e, $this->f,
            $this->font, $this->size, $this->charSpace, $this->wordSpace, $this->hScale, $this->leading,
        ] = $s;
    }

    /**
     * @param array<string, mixed> $resources
     * @param array<int, true> $active
     */
    private function form(string $name, array $resources, int $depth, array $active): void
    {
        $xobjects = $this->file->dict($resources['XObject'] ?? null);
        $ref = $xobjects[$name] ?? null;
        $stream = $this->file->resolve($ref);
        if (!$stream instanceof Stream || ($stream->dict['Subtype'] ?? null) !== '/Form') {
            return;
        }
        if (isset($active[$stream->num])) {
            return;
        }
        $active[$stream->num] = true;

        $content = $this->file->streamData($stream);
        if ($content === null || $content === '') {
            return;
        }

        $state = $this->snapshot();
        $textState = [$this->ta, $this->tb, $this->tc, $this->td, $this->te, $this->tf, $this->la, $this->lb, $this->lc, $this->ld, $this->le, $this->lf];

        $matrix = $this->file->resolve($stream->dict['Matrix'] ?? null);
        if (is_array($matrix) && count($matrix) >= 6) {
            $this->concat(...array_map(fn($v) => (float)$this->file->resolve($v), array_slice($matrix, 0, 6)));
        }
        $this->run($content, $this->file->dict($stream->dict['Resources'] ?? null) ?? $resources, $depth + 1, $active);

        $this->restore($state);
        [$this->ta, $this->tb, $this->tc, $this->td, $this->te, $this->tf, $this->la, $this->lb, $this->lc, $this->ld, $this->le, $this->lf] = $textState;
    }

    /**
     * Splits a token holding one or more numbers.
     *
     * @return list<float>
     */
    private static function numbers(string $run): array
    {
        $parts = explode(' ', $run);
        if (strpbrk($run, "\n\r\t") !== false || str_contains($run, '  ')) {
            $parts = preg_split('/\s+/', $run, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $out = [];
        foreach ($parts as $part) {
            $out[] = (float)$part;
        }
        return $out;
    }

    /** The last number in a token. */
    private static function last(mixed $run): float
    {
        if (!is_string($run) || $run === '') {
            return 0.0;
        }
        if (strpbrk($run, " \n\r\t") === false) {
            return (float)$run;
        }
        $numbers = self::numbers($run);
        return $numbers === [] ? 0.0 : $numbers[count($numbers) - 1];
    }

    /** Turns a "(literal)" or "<hex>" token into the bytes it stands for. */
    private static function bytes(string $tok): string
    {
        if ($tok[0] === '(') {
            $body = substr($tok, 1, -1);
            return str_contains($body, '\\') ? Lexer::unescape($body) : $body;
        }
        return Lexer::hex(substr($tok, 1, -1));
    }

    private static function buildTokenPattern(): string
    {
        // Literal strings may nest parentheses. Three levels are written out by hand, which keeps the
        // pattern free of capture groups (those would double the size of the match array).
        $string = '\((?:[^()\\\\]++|\\\\[\s\S])*+\)';
        for ($i = 0; $i < 3; $i++) {
            $string = '\((?:[^()\\\\]++|\\\\[\s\S]|' . $string . ')*+\)';
        }
        // Only what the interpreter acts on is matched. Path, colour and marked-content operators and
        // their operands match nothing, so the regex engine steps over them without PHP ever seeing them.
        // On a page full of vector drawing that is most of the stream.
        $number = '[+-]?(?:\d++\.?\d*+|\.\d++)';
        return '~' . $string
            . '|<<|>>|<[0-9A-Fa-f\s]*+>'
            . '|/[^\s/\[\]()<>{}%]*+'
            // A run of numbers, but only when a text operator, a string or an array end comes next.
            . '|(?<![A-Za-z0-9.])' . $number . '(?:\s++' . $number . ')*+(?=\s*+(?:T[mdDfcwzL](?![A-Za-z0-9*])|cm(?![A-Za-z0-9*])|["\](<]))'
            . '|(?<![A-Za-z0-9*\'"./#+-])(?:T[jJmdDfcwzL*]|BT|cm|Do|[qQ\'"])(?![A-Za-z0-9*\'"])'
            . '|[\[\]]'
            . '|%[^\r\n]*+~';
    }
}
