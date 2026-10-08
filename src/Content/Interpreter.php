<?php

declare(strict_types=1);

namespace YetiPdf\Content;

use YetiPdf\Core\File;
use YetiPdf\Core\Lexer;
use YetiPdf\Core\Stream;
use YetiPdf\Font\Font;
use YetiPdf\Font\FontLoader;
use YetiPdf\Font\Utf;

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
    /** How many held lines back a new piece is compared with, to find the line it carries on from. */
    private const MAX_LINES_BACK = 400;
    /** Pieces of right-to-left lines kept per page. A real page has a few thousand; past this, text goes out in the order it is drawn. */
    private const MAX_HELD = 50000;
    /** Pieces in one line past which the letter-level tidying in place() is skipped, to keep its cost in step with the line's size. */
    private const MAX_TIDIED = 2000;
    /** How much of the text before a piece is looked at for letters the piece repeats. */
    private const OVERLAP = 256;

    /**
     * What one page may spend on form XObjects (and annotation appearances): how many it draws, and how many bytes
     * of content they add up to. Forms can draw forms, so a few lines can ask for billions of runs. The busiest page
     * in 1,009 real documents draws 1,016 forms and 3.4MB of them.
     */
    public int $maxFormRuns = 20000;
    public int $maxFormBytes = 64 << 20;

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

    /** Most graphics states kept by q at once. */
    private const MAX_SAVED = 4096;
    /** Text is put aside once $out is longer than this, so that taking some back never copies the whole page. */
    private const TAIL = 8192;
    /** Content larger than this is checked against the memory that is left before it is tokenized. */
    private const GUARDED_SIZE = 1 << 20;
    /** Bytes for each token the tokenizer keeps: the string itself and its slot in the result array. */
    private const TOKEN_COST = 64;
    /** Bytes that begin a token the tokenizer keeps: ( < / [ ] T q Q ' " */
    private const TOKEN_STARTS = [40, 60, 47, 91, 93, 84, 113, 81, 39, 34];

    private static ?string $tokenPattern = null;

    /** The end of the text so far, which is all that is ever edited: the current line, or its last two bytes when it is a long one. */
    private string $out = '';
    /** @var list<string> text that is finished with, oldest first; $out carries on from it */
    private array $done = [];
    /** Bytes of text put aside or held back so far on this page, and the most there may be. */
    private int $kept = 0;
    private int $maxOut = 0;
    private int $formRuns = 0;
    private int $formBytes = 0;
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
    /** True when the lone accent began a new line, so the letter it lands on begins that line too. */
    private bool $accentBreak = false;
    private float $ax = 0.0;
    private float $ay = 0.0;
    /** Where the current line starts in the output, and where on the page it began. */
    private int $lineAt = 0;
    private float $lx = 0.0;
    private float $ly = 0.0;
    /**
     * The pieces of the current line, held back because it has right-to-left text in it: [start, end, text, size],
     * with start and end measured along the line. Null on every other line.
     *
     * @var list<array{0: float, 1: float, 2: string, 3: float}>|null
     */
    private ?array $held = null;
    /** @var array{0: float, 1: float, 2: float, 3: float} the held line's position across the page, its direction, and its size */
    private array $heldAt = [0.0, 1.0, 0.0, 0.0];
    /**
     * Held lines that have ended, waiting for the page to finish: [position across, direction x, direction y, start, end].
     *
     * @var list<array{0: float, 1: float, 2: float, 3: float, 4: float}>
     */
    private array $lines = [];
    /** @var list<list<array{0: float, 1: float, 2: string, 3: float}>> the pieces of each of those lines */
    private array $pieces = [];
    private int $heldCount = 0;

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
        $this->maxOut = $this->file->limit();
        try {
            $this->draw($content, $resources, $appearances);
        } catch (\OverflowException) {
            $this->file->warn('The text of a page is too large for the memory that is left; the rest of it was left out');
        }
        if ($this->held !== null) {
            $this->release();
        }
        if ($this->done !== []) {
            $this->out = implode('', $this->done) . $this->out;
            $this->done = [];
        }
        if ($this->lines !== []) {
            $this->place();
        }
        return $this->out;
    }

    /**
     * @param array<string, mixed> $resources
     * @param list<array{0: Stream, 1: float, 2: float}> $appearances
     */
    private function draw(string $content, array $resources, array $appearances): void
    {
        $this->out = '';
        $this->done = [];
        $this->kept = 0;
        $this->hasPrev = false;
        $this->accentAt = -1;
        $this->lineAt = 0;
        $this->held = null;
        $this->lines = [];
        $this->pieces = [];
        $this->heldCount = 0;
        $this->a = $this->d = 1.0;
        $this->b = $this->c = $this->e = $this->f = 0.0;
        $this->font = null;
        $this->size = $this->charSpace = $this->wordSpace = $this->leading = 0.0;
        $this->hScale = 1.0;
        $this->formRuns = $this->formBytes = 0;

        $this->run($content, $resources, 0, []);

        foreach ($appearances as [$stream, $x, $y]) {
            if (!$this->formAllowed()) {
                break;
            }
            $data = $this->file->streamData($stream);
            if ($data === null || $data === '') {
                continue;
            }
            $this->formBytes += strlen($data);
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
    }

    /**
     * @param array<string, mixed> $resources
     * @param array<int, true> $active form XObjects currently being drawn, to stop a form drawing itself
     */
    private function run(string $content, array $resources, int $depth, array $active): void
    {
        // Inline images carry raw binary between ID and EI, which must not reach the tokenizer.
        // Without an ID delimiter, skip the unmatched tail instead of retrying each later BI.
        if (str_contains($content, 'ID') && preg_match('/(?<![A-Za-z0-9])BI[\s\/]/', $content)) {
            $content = preg_replace('/(?<![A-Za-z0-9])BI[\s\/](?:.*?\sID\s.*?(?:\sEI(?![A-Za-z0-9])|$)|.*+(*SKIP)(*F))/s', ' ', $content) ?? $content;
        }

        if (strlen($content) > self::GUARDED_SIZE && !$this->fits($content)) {
            $this->file->warn('Page content is too dense to read in the memory that is left; it was skipped');
            return;
        }

        self::$tokenPattern ??= self::buildTokenPattern();
        $found = preg_match_all(self::$tokenPattern, $content, $m);
        if ($found === false) {
            $this->file->warn('Page content could not be read (' . preg_last_error_msg() . '); it was skipped');
        }
        if (!$found) {
            return;
        }
        unset($content);

        $fontDict = null;
        $fontCache = [];
        $flushes = $this->fonts->flushes;
        $stack = [];
        $array = null;
        $saved = [];

        foreach ($m[0] as $tok) {
            $ch = $tok[0];

            if (isset(self::NUMBER_START[$ch])) {
                // One token holds every number up to the next operator, so the thousands of path
                // coordinates on a page cost one loop step per operator rather than one per number.
                if ($array === null) {
                    // Operators read at most six numbers, and a run of them can be megabytes long.
                    $stack[] = isset($tok[1024]) ? self::tail($tok) : $tok;
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
                    $v = self::numbers(end($stack));
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
                    $v = self::numbers(end($stack));
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
                    $v = $n >= 2 ? self::numbers($stack[$n - 2]) : [];
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
                        if ($flushes !== $this->fonts->flushes || isset($fontCache[255])) {
                            // The loader has let its fonts go to free memory, or this content names a great many.
                            $fontCache = [];
                            $flushes = $this->fonts->flushes;
                        }
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
                    $v = self::numbers(end($stack));
                    $n = count($v);
                    if ($n >= 6) {
                        $this->concat($v[$n - 6], $v[$n - 5], $v[$n - 4], $v[$n - 3], $v[$n - 2], $v[$n - 1]);
                    }
                    break;

                case 'q':
                    // Nothing real nests this deep; a file that only saves the state would fill memory with copies of it.
                    if (!isset($saved[self::MAX_SAVED])) {
                        $saved[] = $this->snapshot();
                    }
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

    /**
     * Whether the tokens of this content would fit in the memory that is left.
     *
     * The tokenizer makes a PHP string for every token it keeps, about 50 bytes each. The tokens it keeps start
     * with a few characters, so counting those gives an upper bound without building anything. Path and colour
     * operators are not among them, which is why a page of dense drawing is not turned away.
     */
    private function fits(string $content): bool
    {
        $counts = count_chars($content, 1);
        $tokens = substr_count($content, 'cm') + substr_count($content, 'Do') + substr_count($content, 'BT');
        foreach (self::TOKEN_STARTS as $byte) {
            $tokens += $counts[$byte] ?? 0;
        }
        // Most T operators have numbers in front of them, and those are kept as a token of their own.
        $tokens += $counts[84] ?? 0;
        return $tokens * self::TOKEN_COST <= $this->file->room();
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

            if (($font->rtl || $this->held !== null) && $this->hold($font, $s, $text, $x, $y, $ux, $uy, $size, $hLen, $advance)) {
                $this->advance($advance);
                return;
            }

            $glue = '';
            if (!$this->hasPrev) {
                $this->lx = $x;
                $this->ly = $y;
            } else {
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
                    if ($this->accentLone && $this->accentHadPrev) {
                        // What stood between the accent and the text before it now stands before the letter.
                        if ($this->accentBreak) {
                            $glue = "\n";
                        } elseif (($x - $this->ax) * $this->pux + ($y - $this->ay) * $this->puy > $this->wordGap * $ref) {
                            $glue = ' ';
                        }
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

            if (isset($this->out[self::TAIL])) {
                $this->settle();
            }
            $before = strlen($this->out);

            if ($glue === "\n") {
                $out = $this->out;
                $len = strlen($out);
                // "exam-" at a line end followed by "ple": put the word back together.
                if ($this->dehyphenate && $len > 1 && $out[$len - 1] === '-' && ctype_alpha($out[$len - 2])
                    && ($text[0] >= 'a' && $text[0] <= 'z')) {
                    $this->out = substr($out, 0, -1) . $text;
                    $this->lineAt = $len - 1;
                } else {
                    $this->out .= "\n" . $text;
                    $this->lineAt = $len + 1;
                }
                $this->lx = $x;
                $this->ly = $y;
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
                    $this->accentBreak = $glue === "\n";
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

    /**
     * Takes a piece of text out of the normal flow when its line has right-to-left text in it.
     *
     * Such a line cannot be written out piece by piece: Hebrew and Arabic are drawn left to right like
     * everything else, so the pieces arrive in an order that depends on the program that made the PDF.
     * They are kept until the page ends, then sorted by where they sit and put into reading order.
     * Returns false for ordinary text on an ordinary line, which costs nothing extra.
     *
     * $s is the string as the page has it and $text what it decodes to. $advance is how far it moves the
     * position in text space, and $hLen how long one unit of that is on the page.
     */
    private function hold(Font $font, string $s, string $text, float $x, float $y, float $ux, float $uy, float $size, float $hLen, float $advance): bool
    {
        $same = false;
        if ($this->hasPrev) {
            $dx = $x - $this->px;
            $dy = $y - $this->py;
            $ref = $size > $this->psize ? $size : $this->psize;
            $same = abs($dy * $this->pux - $dx * $this->puy) <= $this->lineGap * $ref && ($ux * $this->pux + $uy * $this->puy) >= 0.9;
        }
        if ($this->held !== null && (!$same || $this->heldCount >= self::MAX_HELD)) {
            $this->release();
        }
        if ($this->held === null) {
            if ($this->heldCount >= self::MAX_HELD || !preg_match(Utf::RIGHT_TO_LEFT, $text)) {
                return false;
            }
            $this->held = [];
            $this->heldAt = [$y * $ux - $x * $uy, $ux, $uy, $size];
            if ($same) {
                // The line began with ordinary text. Take it back so it can be placed with the rest, unless
                // it has run on so long that its start has been put aside; then what follows just goes after it.
                $head = $this->lineAt >= 0 ? substr($this->out, $this->lineAt) : '';
                if ($head !== '') {
                    $this->out = substr($this->out, 0, $this->lineAt);
                    $this->held[] = [$this->lx * $this->pux + $this->ly * $this->puy, $this->px * $this->pux + $this->py * $this->puy, $head, $this->psize];
                }
            } else {
                if (isset($this->out[self::TAIL])) {
                    $this->settle();
                }
                if ($this->hasPrev) {
                    $this->out .= "\n";
                }
                $this->lineAt = strlen($this->out);
                $this->lx = $x;
                $this->ly = $y;
            }
            $this->accentAt = -1;
        }

        $this->heldCount++;
        $this->kept += strlen($text);
        if ($this->kept > $this->maxOut) {
            throw new \OverflowException('Page text is larger than the memory that is left allows');
        }
        $start = $x * $ux + $y * $uy;
        $width = $advance * $hLen;
        $letters = $font->n ?: 1;
        // How wide one letter is, going by the font alone.
        $letter = abs($font->w * $this->size * $this->hScale) * $hLen / $letters;
        if ($width < 0.0) {
            // Negative letter spacing makes a string run backwards across the page (one way of writing right to left).
            // Its first letter is then its rightmost, and each letter still extends to the right of where it is placed.
            $this->held[] = [$start + $width - $width / $letters, $start + $letter, Utf::reverse($text), $size];
        } elseif ($text[0] >= "\xD6" && $text[0] <= "\xDB" && !isset($text[12]) && preg_match('/^\p{Mn}+$/u', $text)) {
            // Vowel marks drawn by themselves. They sit on a letter, and their middle says which one better than where they start.
            $middle = $start + $width / 2;
            $this->held[] = [$middle, $middle, $text, $size];
        } else {
            // Letters set on top of each other (a letter and its vowel mark, say) move the position by less than one of them is wide.
            $this->held[] = [$start, $start + ($width > $letter ? $width : $letter), $font->rtl ? $font->drawn($s) : $text, $size];
        }

        $this->hasPrev = true;
        $this->psx = $x;
        $this->psy = $y;
        $this->ptext = $text;
        $this->px = $x + $ux * $width;
        $this->py = $y + $uy * $width;
        $this->pux = $ux;
        $this->puy = $uy;
        $this->psize = $size;
        return true;
    }

    /**
     * Ends the line that hold() has been collecting. Its place in the output is marked, and the
     * text goes in when the page is finished.
     */
    private function release(): void
    {
        $held = $this->held ?? [];
        $this->held = null;
        [$across, $ux, $uy, $size] = $this->heldAt;
        $min = INF;
        $max = -INF;
        foreach ($held as $piece) {
            $min = $piece[0] < $min ? $piece[0] : $min;
            $max = $piece[1] > $max ? $piece[1] : $max;
        }

        // A line is not always drawn in one go: print drivers cut wide lines into strips and draw the
        // page strip by strip. Pieces that carry on from a line already seen on this page join it.
        for ($i = count($this->lines) - 1, $oldest = max(0, $i - self::MAX_LINES_BACK); $i >= $oldest; $i--) {
            [$at, $lux, $luy, $from, $to] = $this->lines[$i];
            if (abs($at - $across) <= 0.1 * $size && $lux * $ux + $luy * $uy >= 0.99 && $min <= $to + $size && $max >= $from - $size) {
                $this->lines[$i][3] = $min < $from ? $min : $from;
                $this->lines[$i][4] = $max > $to ? $max : $to;
                // One at a time: a line that arrives in a thousand strips is then never copied.
                foreach ($held as $piece) {
                    $this->pieces[$i][] = $piece;
                }
                // Nothing of this line stays here, so neither does the line break that led up to it.
                if (strlen($this->out) === $this->lineAt && str_ends_with($this->out, "\n")) {
                    $this->out = substr($this->out, 0, -1);
                }
                return;
            }
        }
        $this->out .= "\0\x1E" . count($this->lines) . "\x1E\0";
        $this->lines[] = [$across, $ux, $uy, $min, $max];
        $this->pieces[] = $held;
    }

    /**
     * Puts aside the text that will not change again: everything before the current line, or when the line
     * itself is long, all but its last two bytes. An accent that lands on a letter and a hyphen at a line end both
     * cut the end off the text, and a line that turns out to read right to left is taken back whole. Copying the
     * page for each of those would make a long page cost the square of its length.
     */
    private function settle(): void
    {
        $len = strlen($this->out);
        $at = $this->lineAt < $len ? $this->lineAt : $len;
        if ($at < 0 || $len - $at > self::TAIL >> 1) {
            $at = $len - 2;
            $this->lineAt = -1;
        } else {
            $this->lineAt = 0;
        }
        $this->done[] = substr($this->out, 0, $at);
        $this->out = substr($this->out, $at);
        $this->kept += $at;
        if ($this->kept > $this->maxOut) {
            throw new \OverflowException('Page text is larger than the memory that is left allows');
        }
    }

    /** Puts the text of the held lines where release() marked them. */
    private function place(): void
    {
        $text = [];
        foreach ($this->pieces as $i => $pieces) {
            // Left to right along the line. A vowel mark has no width and sits on its letter, so it sorts after it.
            usort($pieces, static fn(array $a, array $b): int => [$a[0], $b[1] - $b[0]] <=> [$b[0], $a[1] - $a[0]]);

            $tidy = count($pieces) <= self::MAX_TIDIED;
            // The line so far is $visual and then $last, its final character. A vowel mark drawn on its own goes
            // between the two, so that it follows its letter once the line is turned around.
            $visual = '';
            $last = '';
            $end = 0.0;
            $before = null;
            $spaced = false;
            foreach ($pieces as $piece) {
                [$start, $stop, $part, $size] = $piece;
                // A space drawn by itself is only a space if the letters either side of it are apart at all:
                // some are drawn on top of the letter before them, in the middle of a word.
                if (trim($part, ' ') === '') {
                    $spaced = true;
                    continue;
                }
                if ($before !== null) {
                    // "The same spot" is a fifth of the font size, or half the piece's own width for a narrow letter:
                    // two of those in a row are closer together than that fifth.
                    $near = 0.2 * $size;
                    if ($stop > $start && ($stop - $start) * 0.5 < $near) {
                        $near = ($stop - $start) * 0.5;
                    }
                    // The same text drawn again at the same spot is fake bold.
                    if ($part === $before[2] && abs($start - $before[0]) < $near) {
                        continue;
                    }
                    if ($start - $end > ($spaced ? 0.03 : $this->wordGap) * $size && $part[0] !== ' ' && $last !== ' ') {
                        $visual .= $last;
                        $last = ' ';
                    } elseif ($tidy && $end - $start > $near && $stop > $start) {
                        $part = self::withoutOverlap(substr($visual, -self::OVERLAP) . $last, $part, ($end - $start) / ($stop - $start));
                    }
                }
                if ($tidy && $stop - $start < 0.01 * $size && $last !== '' && preg_match('/^\p{Mn}+$/u', $part)) {
                    $visual .= $part;
                } elseif ($part !== '') {
                    $at = strlen($part) - 1;
                    while ($at > 0 && (ord($part[$at]) & 0xC0) === 0x80) {
                        $at--;
                    }
                    $visual .= $last . substr($part, 0, $at);
                    $last = substr($part, $at);
                }
                $end = $before === null || $stop > $end ? $stop : $end;
                $before = $piece;
                $spaced = false;
            }
            $text["\0\x1E$i\x1E\0"] = Bidi::logical($visual . $last);
        }
        $this->out = strtr($this->out, $text);
        $this->lines = [];
        $this->pieces = [];
    }

    /**
     * Two strips of a line overlap a little where they meet, and the letters in the overlap are drawn in both.
     * When the start of a piece repeats the end of the text before it, over about the share of its width
     * that overlaps, the repeat is dropped.
     */
    private static function withoutOverlap(string $before, string $part, float $share): string
    {
        $chars = preg_split('//u', $part, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $guess = (int)round($share * count($chars));
        foreach ([$guess, $guess + 1, $guess - 1] as $n) {
            if ($n >= 1 && $n <= count($chars) && str_ends_with($before, $head = implode('', array_slice($chars, 0, $n)))) {
                return substr($part, strlen($head));
            }
        }
        return $part;
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

    /** Counts one more form run against the page's budget, or says the budget is spent. */
    private function formAllowed(): bool
    {
        if ($this->formRuns >= $this->maxFormRuns || $this->formBytes > $this->maxFormBytes) {
            $this->file->warn('A page draws forms so many times that the rest of its drawing was skipped');
            return false;
        }
        $this->formRuns++;
        return true;
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
        if (isset($active[$stream->num]) || !$this->formAllowed()) {
            return;
        }
        $active[$stream->num] = true;

        $content = $this->file->streamData($stream);
        if ($content === null || $content === '') {
            return;
        }
        $this->formBytes += strlen($content);

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
     * Splits a token holding one or more numbers. Anything else gives none: a broken stream can put
     * a string or an array where an operator's numbers should be.
     *
     * @return list<float>
     */
    private static function numbers(mixed $run): array
    {
        if (!is_string($run)) {
            return [];
        }
        if (isset($run[4096])) {
            // A very long run: walk it rather than build a second array holding every number as a string.
            $out = [];
            for ($p = 0, $len = strlen($run); $p < $len; $p += $n + 1) {
                $n = strcspn($run, " \n\r\t", $p);
                if ($n > 0) {
                    $out[] = (float)substr($run, $p, $n);
                }
            }
            return $out;
        }
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

    /** The end of a run of numbers. Operators read at most six, and a run can be megabytes long. */
    private static function tail(string $run): string
    {
        $run = substr($run, -1024);
        return substr($run, strcspn($run, " \n\r\t") + 1);
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
            // Keep numbers only before a text operator, string or array end. Skip other runs once,
            // rather than retrying the same failed lookahead at every number in a long drawing path.
            . '|(?<![A-Za-z0-9.])' . $number . '(?:\s++' . $number . ')*+(?:(?=\s*+(?:T[mdDfcwzL](?![A-Za-z0-9*])|cm(?![A-Za-z0-9*])|["\](<]))|(*SKIP)(*F))'
            . '|(?<![A-Za-z0-9*\'"./#+-])(?:T[jJmdDfcwzL*]|BT|cm|Do|[qQ\'"])(?![A-Za-z0-9*\'"])'
            . '|[\[\]]'
            . '|%[^\r\n]*+~';
    }
}
