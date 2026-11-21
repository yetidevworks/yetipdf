<?php

declare(strict_types=1);

namespace YetiPdf\Read\Content;

use YetiPdf\Read\Core\File;
use YetiPdf\Read\Core\Lexer;
use YetiPdf\Read\Core\Stream;
use YetiPdf\Read\Font\Font;
use YetiPdf\Read\Font\FontLoader;
use YetiPdf\Read\Font\Utf;

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
    /** Most bytes one token of content costs in the list the tokenizer builds; the operand stack is bounded on its own. */
    private const TOKEN_COST = 80;
    /**
     * Most operands to keep on the stack. It is emptied after every operator, so it only grows when a page piles
     * up operands an operator never reads. An operator reads the top few, so the rest can go without losing anything.
     */
    private const MAX_STACK = 8192;

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

    // Cached page-space text basis derived from CTM (a..d), text matrix (ta..td), and font size.
    private bool $geomDirty = true;
    private float $ghLen = 1.0;
    private float $gux = 1.0;
    private float $guy = 0.0;
    private float $gsize = 0.0;

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
        // Half of what a stream may be: the caller tidies the text afterwards, and that takes two more copies of it.
        $this->maxOut = intdiv($this->file->limit(), 2);
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
        $this->geomDirty = true;
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
            $this->geomDirty = true;
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
        $tfCache = [];
        $flushes = $this->fonts->flushes;
        $stack = [];
        $saved = [];
        $tokens = $m[0];
        unset($m);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            $ch = $tok[0];

            if ($ch === '[') {
                $this->runTJ($tok, $tokens, $i, $count);
                $stack = [];
                continue;
            }
            if (isset(self::NUMBER_START[$ch])) {
                $tlen = strlen($tok);
                $last = $tok[$tlen - 1];
                if ($last === 'm') {
                    // Combined "<numbers> Tm" or "<numbers> cm".
                    $op = $tok[$tlen - 2];
                    if ($op === 'T') {
                        if (str_starts_with($tok, '1 0 0 -1 ')) {
                            $sp = strpos($tok, ' ', 9);
                            if ($sp !== false && $sp < $tlen - 4) {
                                $sp2 = strpos($tok, ' ', $sp + 1);
                                if ($sp2 === $tlen - 3 && strpbrk($tok, "\n\r\t\f\0\x0B") === false) {
                                    if ($this->ta !== 1.0 || $this->tb !== 0.0 || $this->tc !== 0.0 || $this->td !== -1.0) {
                                        $this->ta = $this->la = 1.0;
                                        $this->tb = $this->lb = 0.0;
                                        $this->tc = $this->lc = 0.0;
                                        $this->td = $this->ld = -1.0;
                                        $this->geomDirty = true;
                                    }
                                    $this->te = $this->le = (float)substr($tok, 9, $sp - 9);
                                    $this->tf = $this->lf = (float)substr($tok, $sp + 1, $sp2 - $sp - 1);
                                    $stack = [];
                                    continue;
                                }
                            }
                        } elseif (str_starts_with($tok, '1 0 0 1 ')) {
                            $sp = strpos($tok, ' ', 8);
                            if ($sp !== false && $sp < $tlen - 4) {
                                $sp2 = strpos($tok, ' ', $sp + 1);
                                if ($sp2 === $tlen - 3 && strpbrk($tok, "\n\r\t\f\0\x0B") === false) {
                                    if ($this->ta !== 1.0 || $this->tb !== 0.0 || $this->tc !== 0.0 || $this->td !== 1.0) {
                                        $this->ta = $this->la = 1.0;
                                        $this->tb = $this->lb = 0.0;
                                        $this->tc = $this->lc = 0.0;
                                        $this->td = $this->ld = 1.0;
                                        $this->geomDirty = true;
                                    }
                                    $this->te = $this->le = (float)substr($tok, 8, $sp - 8);
                                    $this->tf = $this->lf = (float)substr($tok, $sp + 1, $sp2 - $sp - 1);
                                    $stack = [];
                                    continue;
                                }
                            }
                        }
                        $s = rtrim($tlen > 1026 ? self::tail(substr($tok, 0, $tlen - 2)) : substr($tok, 0, $tlen - 2), " \t\r\n\f\0\x0B");
                        $this->applyTm($s);
                    } else {
                        if (str_starts_with($tok, '1 0 0 1 ')) {
                            $sp = strpos($tok, ' ', 8);
                            if ($sp !== false && $sp < $tlen - 4) {
                                $sp2 = strpos($tok, ' ', $sp + 1);
                                if ($sp2 === $tlen - 3 && strpbrk($tok, "\n\r\t\f\0\x0B") === false) {
                                    $tx = (float)substr($tok, 8, $sp - 8);
                                    $ty = (float)substr($tok, $sp + 1, $sp2 - $sp - 1);
                                    $this->e += $tx * $this->a + $ty * $this->c;
                                    $this->f += $tx * $this->b + $ty * $this->d;
                                    $stack = [];
                                    continue;
                                }
                            }
                        }
                        $s = rtrim($tlen > 1026 ? self::tail(substr($tok, 0, $tlen - 2)) : substr($tok, 0, $tlen - 2), " \t\r\n\f\0\x0B");
                        $this->applyCm($s);
                    }
                    $stack = [];
                    continue;
                }
                if ($last === 'd' || $last === 'D') {
                    $s = rtrim($tlen > 1026 ? self::tail(substr($tok, 0, $tlen - 2)) : substr($tok, 0, $tlen - 2), " \t\r\n\f\0\x0B");
                    $this->applyTd($s, $last === 'D');
                    $stack = [];
                    continue;
                }
                if ($last === 'c' || $last === 'w' || $last === 'z' || $last === 'L') {
                    $s = rtrim($tlen > 1026 ? self::tail(substr($tok, 0, $tlen - 2)) : substr($tok, 0, $tlen - 2), " \t\r\n\f\0\x0B");
                    $val = self::last($s);
                    if ($last === 'c') {
                        $this->charSpace = $val;
                    } elseif ($last === 'w') {
                        $this->wordSpace = $val;
                    } elseif ($last === 'z') {
                        $this->hScale = $val / 100;
                    } else {
                        $this->leading = $val;
                    }
                    $stack = [];
                    continue;
                }
                if (isset($stack[self::MAX_STACK])) {
                    $stack = array_slice($stack, -16);
                }
                // Operators read at most six numbers, and a run of them can be megabytes long.
                $stack[] = $tlen > 1024 ? self::tail($tok) : $tok;
                continue;
            }
            if ($ch === '(') {
                if ($tok[strlen($tok) - 1] === 'j') {
                    $body = substr($tok, 1, strrpos($tok, ')') - 1);
                    $this->show(str_contains($body, '\\') ? Lexer::unescape($body) : $body);
                    $stack = [];
                } else {
                    if (isset($stack[self::MAX_STACK])) {
                        $stack = array_slice($stack, -16);
                    }
                    $stack[] = $tok;
                }
                continue;
            }
            if ($ch === '/') {
                if (isset($tfCache[$tok]) && $flushes === $this->fonts->flushes) {
                    [$this->font, $newSize] = $tfCache[$tok];
                    if ($this->size !== $newSize) {
                        $this->size = $newSize;
                        $this->geomDirty = true;
                    }
                    $stack = [];
                    continue;
                }
                $tlen = strlen($tok);
                $sp = strcspn($tok, " \t\r\n\f\0\x0B", 1);
                if ($sp + 1 < $tlen) {
                    $last = $tok[$tlen - 1];
                    if ($last === 'f') {
                        $name = substr($tok, 1, $sp);
                        $numRaw = substr($tok, $sp + 1, $tlen - $sp - 3);
                        $numPart = trim(isset($numRaw[1024]) ? self::tail($numRaw) : $numRaw, " \t\r\n\f\0\x0B");
                        $newSize = self::last($numPart);
                        if ($this->size !== $newSize) {
                            $this->size = $newSize;
                            $this->geomDirty = true;
                        }
                        if ($flushes !== $this->fonts->flushes || count($fontCache) >= 256) {
                            $fontCache = [];
                            $tfCache = [];
                            $flushes = $this->fonts->flushes;
                        }
                        if (!array_key_exists($name, $fontCache)) {
                            $fontDict ??= $this->file->dict($resources['Font'] ?? null) ?? [];
                            $fontCache[$name] = isset($fontDict[$name]) ? $this->fonts->load($fontDict[$name]) : null;
                        }
                        $this->font = $fontCache[$name];
                        if (count($tfCache) < 256) {
                            $tfCache[$tok] = [$this->font, $newSize];
                        }
                        $stack = [];
                        continue;
                    }
                    if ($last === 'o') {
                        if ($depth < self::MAX_FORM_DEPTH) {
                            $this->form(substr($tok, 1, $sp), $resources, $depth, $active);
                        }
                        $stack = [];
                        continue;
                    }
                }
                if (isset($stack[self::MAX_STACK])) {
                    $stack = array_slice($stack, -16);
                }
                $stack[] = $tok;
                continue;
            }
            if ($ch === '<') {
                if ($tok[strlen($tok) - 1] === 'j') {
                    $hex = substr($tok, 1, strrpos($tok, '>') - 1);
                    if ($hex !== '') {
                        $this->show(((strlen($hex) & 1) === 0 && ctype_xdigit($hex)) ? (string)hex2bin($hex) : Lexer::hex($hex));
                    }
                    $stack = [];
                } else {
                    if (isset($stack[self::MAX_STACK])) {
                        $stack = array_slice($stack, -16);
                    }
                    $stack[] = $tok;
                }
                continue;
            }

            // An operator.
            switch ($tok) {
                case 'q':
                    // A graphics state save followed only by cm calls and its matching Q (all drawing was skipped) is a no-op.
                    $j = $i + 1;
                    while ($j < $count) {
                        $nt = $tokens[$j];
                        if ($nt === 'Q') {
                            $i = $j;
                            $stack = [];
                            continue 3;
                        }
                        $nc = $nt[0];
                        if (isset(self::NUMBER_START[$nc]) && $nt[strlen($nt) - 1] === 'm' && $nt[strlen($nt) - 2] === 'c') {
                            $j++;
                            continue;
                        }
                        break;
                    }
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

                case 'BT':
                    if ($this->ta !== 1.0 || $this->tb !== 0.0 || $this->tc !== 0.0 || $this->td !== 1.0) {
                        $this->geomDirty = true;
                    }
                    $this->ta = $this->td = $this->la = $this->ld = 1.0;
                    $this->tb = $this->tc = $this->te = $this->tf = 0.0;
                    $this->lb = $this->lc = $this->le = $this->lf = 0.0;
                    break;

                case 'Tm':
                    $s = end($stack);
                    if (is_string($s)) {
                        $this->applyTm($s);
                    }
                    break;

                case 'Td':
                case 'TD':
                    $s = end($stack);
                    if (is_string($s)) {
                        $this->applyTd($s, $tok === 'TD');
                    }
                    break;

                case 'cm':
                    $s = end($stack);
                    if (is_string($s)) {
                        $this->applyCm($s);
                    }
                    break;

                case 'Tf':
                    $n = count($stack);
                    if ($n >= 2 && is_string($stack[$n - 2])) {
                        $name = substr($stack[$n - 2], 1);
                        $newSize = self::last($stack[$n - 1]);
                        if ($this->size !== $newSize) {
                            $this->size = $newSize;
                            $this->geomDirty = true;
                        }
                        if ($flushes !== $this->fonts->flushes || count($fontCache) >= 256) {
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

                case 'Tj':
                    $s = end($stack);
                    if (is_string($s)) {
                        $this->show(self::bytes($s));
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

    private function applyTm(string $s): void
    {
        if (str_starts_with($s, '1 0 0 -1 ')) {
            $sp = strpos($s, ' ', 9);
            if ($sp !== false && strpos($s, ' ', $sp + 1) === false && strpbrk($s, "\n\r\t") === false) {
                if ($this->ta !== 1.0 || $this->tb !== 0.0 || $this->tc !== 0.0 || $this->td !== -1.0) {
                    $this->geomDirty = true;
                }
                $this->ta = $this->la = 1.0;
                $this->tb = $this->lb = 0.0;
                $this->tc = $this->lc = 0.0;
                $this->td = $this->ld = -1.0;
                $this->te = $this->le = (float)substr($s, 9, $sp - 9);
                $this->tf = $this->lf = (float)substr($s, $sp + 1);
                return;
            }
        } elseif (str_starts_with($s, '1 0 0 1 ')) {
            $sp = strpos($s, ' ', 8);
            if ($sp !== false && strpos($s, ' ', $sp + 1) === false && strpbrk($s, "\n\r\t") === false) {
                if ($this->ta !== 1.0 || $this->tb !== 0.0 || $this->tc !== 0.0 || $this->td !== 1.0) {
                    $this->geomDirty = true;
                }
                $this->ta = $this->la = 1.0;
                $this->tb = $this->lb = 0.0;
                $this->tc = $this->lc = 0.0;
                $this->td = $this->ld = 1.0;
                $this->te = $this->le = (float)substr($s, 8, $sp - 8);
                $this->tf = $this->lf = (float)substr($s, $sp + 1);
                return;
            }
        }
        if (sscanf($s, '%f %f %f %f %f %f%n', $v0, $v1, $v2, $v3, $v4, $v5, $consumed) === 7 && $consumed === strlen($s)) {
            if ($this->ta !== $v0 || $this->tb !== $v1 || $this->tc !== $v2 || $this->td !== $v3) {
                $this->geomDirty = true;
            }
            $this->ta = $this->la = $v0;
            $this->tb = $this->lb = $v1;
            $this->tc = $this->lc = $v2;
            $this->td = $this->ld = $v3;
            $this->te = $this->le = $v4;
            $this->tf = $this->lf = $v5;
            return;
        }
        $v = self::numbers($s);
        $n = count($v);
        if ($n >= 6) {
            $v0 = $v[$n - 6];
            $v1 = $v[$n - 5];
            $v2 = $v[$n - 4];
            $v3 = $v[$n - 3];
            if ($this->ta !== $v0 || $this->tb !== $v1 || $this->tc !== $v2 || $this->td !== $v3) {
                $this->geomDirty = true;
            }
            $this->ta = $this->la = $v0;
            $this->tb = $this->lb = $v1;
            $this->tc = $this->lc = $v2;
            $this->td = $this->ld = $v3;
            $this->te = $this->le = $v[$n - 2];
            $this->tf = $this->lf = $v[$n - 1];
        }
    }

    private function applyTd(string $s, bool $setLeading): void
    {
        $sp = strpos($s, ' ');
        if ($sp !== false && strpos($s, ' ', $sp + 1) === false && strpbrk($s, "\n\r\t") === false) {
            $tx = (float)substr($s, 0, $sp);
            $ty = (float)substr($s, $sp + 1);
        } elseif (sscanf($s, '%f %f%n', $tx, $ty, $consumed) === 3 && $consumed === strlen($s)) {
            // parsed via sscanf
        } else {
            $v = self::numbers($s);
            $n = count($v);
            if ($n < 2) {
                return;
            }
            $tx = $v[$n - 2];
            $ty = $v[$n - 1];
        }
        if ($setLeading) {
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

    private function applyCm(string $s): void
    {
        if (str_starts_with($s, '1 0 0 1 ')) {
            $sp = strpos($s, ' ', 8);
            if ($sp !== false && strpos($s, ' ', $sp + 1) === false && strpbrk($s, "\n\r\t") === false) {
                $tx = (float)substr($s, 8, $sp - 8);
                $ty = (float)substr($s, $sp + 1);
                $this->e += $tx * $this->a + $ty * $this->c;
                $this->f += $tx * $this->b + $ty * $this->d;
                return;
            }
        }
        if (sscanf($s, '%f %f %f %f %f %f%n', $v0, $v1, $v2, $v3, $v4, $v5, $consumed) === 7 && $consumed === strlen($s)) {
            $this->concat($v0, $v1, $v2, $v3, $v4, $v5);
            return;
        }
        $v = self::numbers($s);
        $n = count($v);
        if ($n >= 6) {
            $this->concat($v[$n - 6], $v[$n - 5], $v[$n - 4], $v[$n - 3], $v[$n - 2], $v[$n - 1]);
        }
    }

    /**
     * Executes one matched "[...] TJ" token directly without allocating an intermediate operand array.
     *
     * @param list<string> $tokens
     */
    private function runTJ(string $tok, array $tokens, int $idx, int $count): void
    {
        $end = strrpos($tok, ']');
        if ($end === false || $end <= 1) {
            return;
        }
        $p = 1;
        if ($tok[1] <= ' ') {
            $p += strspn($tok, " \t\r\n\f\0\x0B", 1);
            if ($p >= $end) {
                return;
            }
        }
        // Fast path for a single plain literal string, e.g. "[(The SQL Language)] TJ".
        if ($tok[$p] === '(') {
            $span = strcspn($tok, '()\\', $p + 1);
            $q = $p + 1 + $span;
            if ($q < $end && $tok[$q] === ')') {
                $after = $q + 1;
                if ($after === $end || $after + strspn($tok, " \t\r\n\f\0\x0B", $after) === $end) {
                    if ($span > 0) {
                        $this->show(substr($tok, $p + 1, $span));
                    }
                    return;
                }
            }
        }

        // Kerned text arrives as many short strings with small adjustments between them.
        // Adjustments too small to be a word gap are folded into one run, so decoding and
        // positioning happen once per word instead of once per fragment.
        $gap = -900.0 * $this->wordGap;

        // Collapse small-kerned plain or escaped literal-string and even-length hex-string arrays in C.
        if ($gap <= -99.0 && $end <= 4096) {
            $inner = rtrim(substr($tok, $p, $end - $p), " \t\r\n\f\0\x0B");
            if ($inner !== '' && $inner[0] === '(' && !str_contains($inner, '<')) {
                $leadShift = 0.0;
                $work = $inner;
                // Handle leading empty string + shift, e.g. "()-12(n)-30(e)..." common in RFC/Cairo PDFs.
                if (str_starts_with($work, '()') && preg_match('/^\(\)[\0\s]*+([+-]?(?:\d++\.?\d*+|\.\d++))[\0\s]*+(?=\()/', $work, $lm) === 1) {
                    $leadShift = (float)$lm[1];
                    $work = substr($work, strlen($lm[0]));
                }
                if (!str_contains($work, '()')) {
                    $hasBackslash = str_contains($work, '\\');
                    $flat = false;
                    $body = '';
                    if (!$hasBackslash) {
                        $collapsed = preg_replace('/\)[\0\s]*+(?:-(?:[0-8]?\d|9[0-8])(?:\.\d++)?|[01]?\d?\d(?:\.\d++)?)[\0\s]*+\(/', '', $work);
                        if ($collapsed !== null) {
                            $clen = strlen($collapsed);
                            if ($clen >= 2 && $collapsed[0] === '(' && $collapsed[$clen - 1] === ')' && strcspn($collapsed, '()', 1) === $clen - 2) {
                                $flat = true;
                                $body = substr($collapsed, 1, $clen - 2);
                            }
                        }
                    } elseif (preg_match('/^\((?:[^()\\\\]++|\\\\[\s\S])*+\)(?:[\0\s]*+(?:-(?:[0-8]?\d|9[0-8])(?:\.\d++)?|[01]?\d?\d(?:\.\d++)?)[\0\s]*+\((?:[^()\\\\]++|\\\\[\s\S])*+\))*+$/', $work) === 1) {
                        $flat = true;
                        preg_match_all('/\(((?:[^()\\\\]++|\\\\[\s\S])*+)\)/', $work, $sm);
                        foreach ($sm[1] as $part) {
                            $body .= str_contains($part, '\\') ? Lexer::unescape($part) : $part;
                        }
                    }
                    if ($flat) {
                        if ($leadShift !== 0.0) {
                            $tx = -$leadShift * 0.001 * $this->size * $this->hScale;
                            $this->te += $tx * $this->ta;
                            $this->tf += $tx * $this->tb;
                        }
                        $this->show($body);
                        if ($this->tjNeedsKern($tokens, $idx, $count)) {
                            $kernSum = 0.0;
                            if ($hasBackslash) {
                                // Sum the numbers between matched literal strings.
                                $parts = preg_split('/\((?:[^()\\\\]++|\\\\[\s\S])*+\)/', $work, -1, PREG_SPLIT_NO_EMPTY);
                                if ($parts) {
                                    foreach ($parts as $numStr) {
                                        $kernSum += (float)$numStr;
                                    }
                                }
                            } elseif (preg_match_all('/\)[\0\s]*+(-(?:[0-8]?\d|9[0-8])(?:\.\d++)?|[01]?\d?\d(?:\.\d++)?)[\0\s]*+\(/', $work, $km)) {
                                $kernSum = (float)array_sum($km[1]);
                            }
                            if ($kernSum !== 0.0) {
                                $tx = -$kernSum * 0.001 * $this->size * $this->hScale;
                                $this->te += $tx * $this->ta;
                                $this->tf += $tx * $this->tb;
                            }
                        }
                        return;
                    }
                }
            } elseif ($inner !== '' && $inner[0] === '<' && !str_contains($inner, '(')
                && preg_match('/^<(?:[0-9A-Fa-f]{2})++>(?:[\0\s]*+(?:-(?:[0-8]?\d|9[0-8])(?:\.\d++)?|[01]?\d?\d(?:\.\d++)?)[\0\s]*+<(?:[0-9A-Fa-f]{2})++>)*+$/', $inner) === 1
                && !$this->tjNeedsKern($tokens, $idx, $count)) {
                $collapsed = preg_replace('/>[^<]++</', '', $inner);
                if ($collapsed !== null) {
                    $this->show((string)hex2bin(substr($collapsed, 1, -1)));
                    return;
                }
            }
        }

        $run = '';
        $kern = 0.0;
        $items = 0;

        while ($p < $end) {
            $c = $tok[$p];
            if ($c === '(') {
                if (++$items > self::MAX_STACK) {
                    break;
                }
                $span = strcspn($tok, '()\\', $p + 1);
                $q = $p + 1 + $span;
                if ($q < $end && $tok[$q] === ')') {
                    $run .= substr($tok, $p + 1, $span);
                    $p = $q + 1;
                    continue;
                }
                $start = $p + 1;
                $depth = 1;
                $escaped = false;
                $i = $q;
                while ($i < $end) {
                    $ch = $tok[$i];
                    if ($ch === '\\') {
                        $escaped = true;
                        $i += 2;
                        if ($i < $end) {
                            $i += strcspn($tok, '()\\', $i);
                        }
                        continue;
                    }
                    if ($ch === '(') {
                        $depth++;
                    } elseif (--$depth === 0) {
                        break;
                    }
                    $i++;
                    if ($i < $end) {
                        $i += strcspn($tok, '()\\', $i);
                    }
                }
                $body = substr($tok, $start, max(0, $i - $start));
                $run .= $escaped ? Lexer::unescape($body) : $body;
                $p = min($end, $i + 1);
                continue;
            }
            if ($c === '<') {
                if (++$items > self::MAX_STACK) {
                    break;
                }
                $q = strpos($tok, '>', $p + 1);
                if ($q === false || $q > $end) {
                    break;
                }
                $hex = substr($tok, $p + 1, $q - $p - 1);
                $p = $q + 1;
                if ($hex !== '') {
                    $run .= ((strlen($hex) & 1) === 0 && ctype_xdigit($hex)) ? (string)hex2bin($hex) : Lexer::hex($hex);
                }
                continue;
            }
            if (isset(self::NUMBER_START[$c])) {
                if (++$items > self::MAX_STACK) {
                    break;
                }
                $n = ($c === '-' || $c === '+')
                    ? 1 + strspn($tok, '0123456789.', $p + 1)
                    : strspn($tok, '0123456789.', $p);
                $item = (float)substr($tok, $p, $n);
                $p += $n;
                if ($item > $gap && $item <= 200.0 && $run !== '') {
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
                continue;
            }
            if ($c <= ' ') {
                $ws = strspn($tok, " \t\r\n\f\0\x0B", $p);
                $p += $ws > 0 ? $ws : 1;
                continue;
            }
            if ($c === '%') {
                $p += strcspn($tok, "\r\n", $p);
                continue;
            }
            if ($c === '/') {
                $p += 1 + strcspn($tok, "\0\t\n\f\r ()<>[]{}/%", $p + 1);
                continue;
            }
            $p++;
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

    /**
     * Whether $this->te/$this->tf after the TJ at $idx might be read before being overwritten.
     *
     * @param list<string> $tokens
     */
    private function tjNeedsKern(array $tokens, int $idx, int $count): bool
    {
        for ($j = $idx + 1; $j < $count; $j++) {
            $nt = $tokens[$j];
            $nc = $nt[0];
            if ($nc === '[' || $nc === '(' || $nc === '<' || $nt === 'Tj' || $nt === 'Do') {
                return true;
            }
            if ($nc === '/') {
                if ($nt[strlen($nt) - 1] === 'o') {
                    return true;
                }
                continue;
            }
            if (isset(self::NUMBER_START[$nc])) {
                $nl = $nt[strlen($nt) - 1];
                if ($nl === 'm') {
                    if ($nt[strlen($nt) - 2] === 'T') {
                        // Only a complete 6-operand Tm resets te/tf; an incomplete one (e.g. "1 Tm") is a no-op.
                        $parts = preg_split('/[\0\s]+/', trim(substr($nt, 0, -2), " \t\r\n\f\0\x0B"), -1, PREG_SPLIT_NO_EMPTY);
                        if ($parts && count($parts) >= 6) {
                            return false;
                        }
                    }
                    continue;
                }
                if ($nl === 'd' || $nl === 'D') {
                    $parts = preg_split('/[\0\s]+/', trim(substr($nt, 0, -2), " \t\r\n\f\0\x0B"), -1, PREG_SPLIT_NO_EMPTY);
                    if ($parts && count($parts) >= 2) {
                        return false;
                    }
                    continue;
                }
                continue;
            }
            if ($nt === 'T*' || $nt === 'BT' || $nt === "'" || $nt === '"') {
                return false;
            }
            if ($nt === 'Tm' || $nt === 'Td' || $nt === 'TD') {
                // Standalone operator (operands separated by comment): stay conservative in case operands are incomplete.
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the tokens of this content would fit in the memory that is left.
     *
     * The tokens are counted first, without being kept: replacing each with nothing costs one string no longer
     * than the content. Each token then costs a slot and a short string in the list the tokenizer returns. The
     * operand stack, which a hostile page can grow instead, is bounded separately, so this need only cover that list.
     */
    private function fits(string $content): bool
    {
        self::$tokenPattern ??= self::buildTokenPattern();
        if (preg_replace(self::$tokenPattern, '', $content, -1, $tokens) === null) {
            // The pattern gave up. It will again in a moment, and that is reported there.
            return true;
        }
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

    private function updateGeom(): void
    {
        $ma = $this->ta * $this->a + $this->tb * $this->c;
        $mb = $this->ta * $this->b + $this->tb * $this->d;
        $mc = $this->tc * $this->a + $this->td * $this->c;
        $md = $this->tc * $this->b + $this->td * $this->d;

        if ($mb === 0.0 && $mc === 0.0) {
            $hLen = abs($ma);
            if ($hLen < 1e-9) {
                $hLen = 1.0;
                $ux = 1.0;
            } else {
                $ux = $ma >= 0.0 ? 1.0 : -1.0;
            }
            $uy = 0.0;
            $size = abs($this->size * $md);
        } else {
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
        }
        if ($size < 1e-6) {
            $size = abs($this->size) * $hLen;
        }
        $this->ghLen = $hLen;
        $this->gux = $ux;
        $this->guy = $uy;
        $this->gsize = $size;
        $this->geomDirty = false;
    }

    private function show(string $s): void
    {
        $font = $this->font;
        if ($font === null || $s === '') {
            return;
        }
        $hit = $font->memo[$s] ?? null;
        if ($hit !== null) {
            $text = $hit[0];
            $advance = ($hit[1] * $this->size + $hit[2] * $this->charSpace + $hit[3] * $this->wordSpace) * $this->hScale;
        } else {
            $text = $font->decode($s);
            $advance = ($font->w * $this->size + $font->n * $this->charSpace + $font->sp * $this->wordSpace) * $this->hScale;
        }

        if ($text !== '') {
            // Text space to page space.
            if ($this->geomDirty) {
                $this->updateGeom();
            }
            $hLen = $this->ghLen;
            $ux = $this->gux;
            $uy = $this->guy;
            $size = $this->gsize;
            $x = $this->te * $this->a + $this->tf * $this->c + $this->e;
            $y = $this->te * $this->b + $this->tf * $this->d + $this->f;

            if ($font->rtl || $this->held !== null) {
                if ($hit !== null) {
                    $font->w = $hit[1];
                    $font->n = $hit[2];
                    $font->sp = $hit[3];
                }
                if ($this->hold($font, $s, $text, $x, $y, $ux, $uy, $size, $hLen, $advance)) {
                    $this->advance($advance);
                    return;
                }
            }

            $glue = '';
            if (!$this->hasPrev) {
                $this->lx = $x;
                $this->ly = $y;
            } else {
                $dx = $x - $this->px;
                $dy = $y - $this->py;
                if ($this->puy === 0.0 && $this->pux === 1.0) {
                    $along = $dx;
                    $off = $dy < 0.0 ? -$dy : $dy;
                } else {
                    $along = $dx * $this->pux + $dy * $this->puy;
                    $off = abs($dy * $this->pux - $dx * $this->puy);
                }
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
                } elseif ($off > $this->lineGap * $ref || (($ux !== $this->pux || $uy !== $this->puy) && ($ux * $this->pux + $uy * $this->puy) < 0.9)) {
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
                // "exam-" at a line end followed by "ple": put the word back together.
                if ($this->dehyphenate && $before > 1 && $this->out[$before - 1] === '-' && ctype_alpha($this->out[$before - 2])
                    && ($text[0] >= 'a' && $text[0] <= 'z')) {
                    $this->out = substr($this->out, 0, -1) . $text;
                    $this->lineAt = $before - 1;
                } else {
                    $this->out .= "\n";
                    $this->out .= $text;
                    $this->lineAt = $before + 1;
                }
                $this->lx = $x;
                $this->ly = $y;
            } elseif ($glue === ' ' && $text[0] !== ' ' && ($before === 0 || $this->out[$before - 1] !== ' ')) {
                $this->out .= ' ';
                $this->out .= $text;
            } else {
                $this->out .= $text;
            }

            $last = $text[strlen($text) - 1];
            if ($last < "\x80") {
                if (($last === '`' || $last === '^' || $last === '~') && isset(self::ACCENTS[$last])) {
                    $this->accentMark = self::ACCENTS[$last];
                    $this->accentLone = $last === $text;
                    if ($this->accentLone) {
                        $this->accentAt = $before;
                        $this->accentHadPrev = $this->hasPrev;
                        $this->accentBreak = $glue === "\n";
                        $this->ax = $this->px;
                        $this->ay = $this->py;
                    } else {
                        $this->accentAt = strlen($this->out) - 1;
                    }
                } elseif ($this->accentAt >= 0) {
                    $this->accentAt = -1;
                }
            } else {
                $tail = substr($text, -2);
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
                        $this->accentAt = strlen($this->out) - 2;
                    }
                } elseif ($this->accentAt >= 0) {
                    $this->accentAt = -1;
                }
            }

            $this->hasPrev = true;
            $this->psx = $x;
            $this->psy = $y;
            $this->ptext = $text;
            if ($uy === 0.0) {
                $this->px = $x + $ux * $advance * $hLen;
                $this->py = $y;
            } else {
                $this->px = $x + $ux * $advance * $hLen;
                $this->py = $y + $uy * $advance * $hLen;
            }
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
        // Held text is sorted, joined, turned around and then written into the page, and for a while there
        // is a copy of it at each of those stages. It counts four times over.
        $this->kept += 4 * strlen($text);
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
        foreach (array_keys($this->pieces) as $i) {
            $pieces = $this->pieces[$i];
            // Let go of as the line is built, so the pieces and the line are not both held for the whole page.
            unset($this->pieces[$i]);
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
            unset($pieces, $piece, $before);
            $visual .= $last;
            $text["\0\x1E$i\x1E\0"] = Bidi::logical($visual);
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
        if ($a === 1.0 && $b === 0.0 && $c === 0.0 && $d === 1.0) {
            $this->e += $e * $this->a + $f * $this->c;
            $this->f += $e * $this->b + $f * $this->d;
            return;
        }
        $na = $a * $this->a + $b * $this->c;
        $nb = $a * $this->b + $b * $this->d;
        $nc = $c * $this->a + $d * $this->c;
        $nd = $c * $this->b + $d * $this->d;
        $ne = $e * $this->a + $f * $this->c + $this->e;
        $nf = $e * $this->b + $f * $this->d + $this->f;
        [$this->a, $this->b, $this->c, $this->d, $this->e, $this->f] = [$na, $nb, $nc, $nd, $ne, $nf];
        $this->geomDirty = true;
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
        if ($this->a !== $s[0] || $this->b !== $s[1] || $this->c !== $s[2] || $this->d !== $s[3] || $this->size !== $s[7]) {
            $this->geomDirty = true;
        }
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
        $this->geomDirty = true;
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
        if (strpbrk($run, "\n\r\t\f\0\x0B") !== false || str_contains($run, '  ')) {
            $parts = preg_split('/[\0\s]+/', $run, -1, PREG_SPLIT_NO_EMPTY) ?: [];
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
        return substr($run, strcspn($run, " \n\r\t\f\0\x0B") + 1);
    }

    /** The last number in a token. */
    private static function last(mixed $run): float
    {
        if (!is_string($run) || $run === '') {
            return 0.0;
        }
        if (strpbrk($run, " \n\r\t\f\0\x0B") === false) {
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
        // Only what the interpreter acts on is matched. Path, colour, dictionary, comment and marked-content
        // tokens are skipped inside PCRE so PHP never sees them.
        $number = '[+-]?(?:\d++\.?\d*+|\.\d++)';
        $name = '/[^\0\s/\[\]()<>{}%]*+';
        $ws = '[\0\s]';
        $gap = '(?:[\0\s]++|%[^\r\n]*+)';
        $tjItem = $ws . '++|' . $string . '|<[0-9A-Fa-f\0\s]*+>|' . $number . '|' . $name . '|%[^\r\n]*+';
        return '~(?:<<|>>|%[^\r\n]*+)(*SKIP)(*F)'
            // Match a TJ array and its operator as one token; skip non-TJ arrays (dash patterns, BBox, etc.) once.
            . '|\[(?:' . $tjItem . ')*+(?:\](?:' . $gap . '*+TJ(?![A-Za-z0-9*\'"])|(*SKIP)(*F))|(*SKIP)(*F))'
            // Combine a string and Tj into one token; keep strings before ' or " (or comment-separated Tj); skip others.
            . '|(?:' . $string . '|<[0-9A-Fa-f\0\s]*+>)(?:(?:' . $ws . '*+Tj(?![A-Za-z0-9*\'"]))|(?=' . $gap . '*+(?:Tj(?![A-Za-z0-9*\'"])|[\'"]))|(*SKIP)(*F))'
            // Combine /Name + Do or /Name + size + Tf into one token; keep comment-separated forms; skip other names.
            . '|' . $name . '(?:' . $ws . '++Do(?![A-Za-z0-9*\'"])|' . $ws . '++' . $number . '(?:' . $ws . '++' . $number . ')*+' . $ws . '*+Tf(?![A-Za-z0-9*\'"])|(?=' . $gap . '*+(?:Do(?![A-Za-z0-9*\'"])|' . $number . '(?:' . $gap . '++' . $number . ')*+' . $gap . '*+Tf(?![A-Za-z0-9*\'"])))|(*SKIP)(*F))'
            // Combine numeric operands with Tm/cm/Td/TD/Tc/Tw/Tz/TL into one token; keep bare numbers before Tf, " strings, or comment-separated ops; skip drawing runs once.
            . '|(?<![A-Za-z0-9.])' . $number . '(?:' . $ws . '++' . $number . ')*+(?:' . $ws . '*+(?:T[mdDcwzL]|cm)(?![A-Za-z0-9*\'"])|(?=' . $gap . '*+(?:T[mdDfcwzL](?![A-Za-z0-9*])|cm(?![A-Za-z0-9*])|["(<]))|(*SKIP)(*F))'
            . '|(?<![A-Za-z0-9*\'"./#+-])(?:T[jJmdDfcwzL*]|BT|cm|Do|[qQ\'"])(?![A-Za-z0-9*\'"])~';
    }
}
