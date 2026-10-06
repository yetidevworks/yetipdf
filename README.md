# YetiPDF

Fast, dependency-free PDF text extraction for PHP, built for search indexing.

YetiPDF does one thing: it gets the words out of a PDF, page by page, quickly and without falling over. It does not render pages, extract images, or try to reproduce layout. If you want to index PDFs for search, feed them to an LLM, or check what a document says, that is the job it was written for.

- **Pure PHP 8.3+.** The only required extension is `zlib`. No binaries, no `exec`, no Composer dependencies.
- **Fast.** On large documents it runs at about the speed of poppler's `pdftotext`, a C++ program, and well ahead of the other PHP libraries. See [Benchmarks](#benchmarks).
- **Low, flat memory.** Pages are read one at a time through a generator. A 3,000-page manual peaks around 70MB.
- **Hard to break.** A damaged cross-reference table is rebuilt by scanning the file. A wrong stream length, a truncated compressed stream, or a font with a malformed character map costs you as little text as possible, never the document.
- **Words come out as words.** Spaces are rebuilt from where the text sits on the page, so kerned and justified text (LaTeX, InDesign) doesn't run together, words hyphenated across lines are rejoined, and ligatures and TeX-style accents become ordinary letters.

## Install

```bash
composer require yetidevworks/yetipdf
```

## Use

```php
use YetiPdf\YetiPdf;

$doc = YetiPdf::open('manual.pdf');

foreach ($doc->pages() as $number => $text) {
    // index $text as page $number
}
```

Everything else:

```php
$doc->text();          // whole document as one string
$doc->page(12);        // one page
$doc->pageCount();
$doc->info();          // ['Title' => ..., 'Author' => ..., ...]
$doc->isEncrypted();
$doc->warnings();      // what had to be worked around, in plain language

YetiPdf::parse($bytes);   // from a string you already have
```

Options:

```php
use YetiPdf\Options;

$doc = YetiPdf::open('report.pdf', new Options(
    password: 'secret',        // for PDFs that need one to open
    dehyphenate: true,         // "exam-" + "ple" at a line break becomes "example"
    expandLigatures: true,     // "ﬁ" becomes "fi"
    annotations: true,         // include typed-on notes and form field values
    wordGap: 0.11,             // gap, as a fraction of the font size, that counts as a space
));
```

There is a small command line tool too:

```bash
vendor/bin/yetipdf manual.pdf            # text to stdout, pages separated by form feeds
vendor/bin/yetipdf --stats manual.pdf    # plus page count, time, memory and warnings on stderr
```

## Errors

`YetiPdf::open()` and `YetiPdf::parse()` throw in two cases only:

- `InvalidPdfException`: the file can't be read, isn't a PDF, or is too damaged to find a single page in.
- `EncryptedException`: the PDF needs a password and the one given (or none) didn't open it.

Both extend `YetiPdf\Exception\PdfException`. After a document opens, nothing throws. A page that can't be read comes back as an empty string and leaves a line in `warnings()`.

PDFs that are "protected" against printing or copying but open without a password are read normally. That covers RC4, AES-128 and AES-256; the AES ones need the `openssl` extension.

## What it reads

- Classic and stream cross-reference tables, object streams, incremental updates, and files where all of that is broken.
- Flate, LZW, ASCII85, ASCIIHex and RunLength streams, with PNG and TIFF predictors. Image streams are recognised and skipped without being decompressed.
- Type 1, TrueType, Type 3 and composite (Type 0) fonts: ToUnicode maps, the standard encodings, `/Differences` arrays through the Adobe glyph list, encodings embedded in Type 1 font programs, the Unicode CJK CMaps, and legacy CJK encodings (Shift-JIS, GBK, Big5, UHC) when `mbstring` is available.
- Text inside form XObjects, typed-on (FreeText) annotations, and the visible values of form fields.

## What it doesn't do yet

- **Scanned pages.** There is no OCR. A scan with a hidden text layer works, because that layer is ordinary text.
- **Fonts with no way to map glyphs to characters**: composite fonts that have no ToUnicode map and rely on the embedded font's own tables or on Adobe's CJK character collections. Text in those fonts is left out, and `warnings()` names the font.
- **Right-to-left reordering.** Arabic and Hebrew come out in the order they are drawn, which is visual order, and Arabic presentation forms are not folded back to base letters.
- **Reading order across columns.** Text comes out in the order the PDF draws it. That is nearly always sensible within a column, and for search the order between columns rarely matters.
- **Very large files.** The file is read into memory whole, so a 1GB PDF needs 1GB.

## Benchmarks

Measured over 218 PDFs: 20 ordinary documents (papers, tax forms and guides, standards, software manuals, and the UDHR in Japanese, Chinese, Korean and Russian) and 198 from the pdf.js test collection, which exist because they are awkward. Poppler's `pdftotext` 26.08 is the reference. Recall is the share of its words a library also found, and a document that fails to extract counts as zero. PHP 8.4 without JIT, on an Apple Silicon Mac, six files at a time.

| | YetiPDF | PrinsFrank 3.6 | smalot 2.12 | pdftotext |
|---|---|---|---|---|
| Documents extracted | 218 / 218 | 191 / 218 | 211 / 218 | reference |
| Documents at 95%+ recall | 190 | 113 | 120 | reference |
| Words recovered, all files | 99.8% | 88.7% | 93.9% | reference |
| Ordinary documents at 95%+ recall | 19 / 20 | 14 / 20 | 13 / 20 | reference |
| Total time | 6.6s | 22.5s | 147.2s | 11.8s |
| Slowest file | 2.1s | 8.4s | 52.6s | 2.2s |
| Peak memory, worst file | 83MB | 92MB | 779MB | 73MB |

The slowest file for everyone is the PostgreSQL 16 manual: 3,057 pages and just over a million words.

Two cautions about reading these numbers. `pdftotext` is a reference, not the truth: where it and YetiPDF disagree, YetiPDF is sometimes the one that is right (small caps that `pdftotext` drops the first letter of, for example), and those cases count against YetiPDF here. And most of the files below 95% are ones no extractor reads, because the PDF carries no usable mapping from glyphs to characters.

Run them yourself:

```bash
benchmarks/fetch-corpus.sh                         # about 300MB, downloaded once
composer install -d benchmarks                     # only needed to compare the other libraries
benchmarks/bench.sh yetipdf prinsfrank smalot
php benchmarks/diff.php some.pdf                   # which words differ from pdftotext for one file
```

## Tests

```bash
composer test
```

## License

MIT. The bundled glyph list (`data/glyphlist.php`) is Adobe's, under the BSD 3-Clause license reproduced in that file.
