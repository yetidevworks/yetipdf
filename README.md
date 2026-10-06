# YetiPDF

Fast PDF text extraction in pure PHP, with no binaries and no dependencies. It runs about as quick as the C++ tool everybody else shells out to.

I wrote **YetiPDF** because I wanted PDFs to show up in **YetiSearch** results, and nothing in PHP did the job. One library needed 779MB to read a book. Another gave up on one PDF in eight. The popular answer is to install poppler and call `pdftotext`, which is great until you're on shared hosting with `exec` turned off. So I started over, with one goal: get the words out of a PDF, quickly, without falling over.

That's all it does. It won't render pages or pull out images. If you want to index PDFs for search, or hand their contents to an LLM, this is for you.

## How fast?

Here are six real documents, one at a time, on PHP 8.4 with no JIT. **pdftotext** is poppler's command line tool, written in C++.

| Document | Pages | YetiPDF | pdftotext | PrinsFrank | smalot |
|---|---|---|---|---|---|
| PostgreSQL 16 manual | 3,057 | **1.80s** | 2.22s | 8.13s | 41.06s |
| NIST SP 800-53 | 492 | **0.66s** | 0.83s | 2.91s | 51.57s |
| IRS Publication 17 | 142 | **0.35s** | 0.41s | 1.82s | 5.64s |
| The Memoir Class manual | 625 | **0.43s** | 1.01s | failed | 6.24s |
| RFC 9110 | 194 | **0.17s** | 0.26s | 0.68s | 15.91s |
| Pro Git | 501 | **0.13s** | 0.29s | 0.88s | 0.68s |

Yes, that's PHP beating a C++ binary. The PostgreSQL manual is just over a million words, and YetiPDF reads all of it in under two seconds.

It isn't magic. PHP's `zlib` and regex engines are C underneath, and YetiPDF hands them nearly all of the heavy lifting. It also skips work that a text extractor has no use for. Every line, fill and colour change on a page gets stepped over before PHP ever sees it, and images are never decompressed at all.

Memory matters just as much if you're running on a 256MB host:

| Document | YetiPDF | pdftotext | PrinsFrank | smalot |
|---|---|---|---|---|
| PostgreSQL 16 manual | 67MB | 72MB | 48MB | 430MB |
| NIST SP 800-53 | 52MB | 71MB | 71MB | 193MB |
| IRS Publication 17 | 45MB | 29MB | 82MB | 193MB |
| Pro Git | 31MB | 17MB | 27MB | 779MB |

Those figures are for pulling the whole document into one string. Read it a page at a time, as in the example below, and the PostgreSQL manual peaks at 55MB.

## How accurate?

Fast is no use if the text is wrong. And "wrong" for search has a specific meaning: a word that comes out glued to its neighbours can't be found.

I tested against 218 PDFs. Twenty are ordinary documents: research papers, tax forms, government standards, software manuals, and the Universal Declaration of Human Rights in Japanese, Chinese, Korean and Russian. The other 198 come from the pdf.js test collection, and those exist because they're awkward. `pdftotext` is the reference, and the score is how many of its words each library also found. A document that fails scores zero.

| | YetiPDF | PrinsFrank 3.6 | smalot 2.12 |
|---|---|---|---|
| Documents read | **218 of 218** | 191 | 211 |
| Words recovered | **99.8%** | 88.7% | 93.9% |
| Documents scoring 95% or better | **190** | 113 | 120 |
| Ordinary documents scoring 95% or better | **19 of 20** | 14 | 13 |
| Time for all 218 | **5.8s** | 22.5s | 147.2s |

Two things about that table. `pdftotext` is a reference and not the truth, so when YetiPDF and `pdftotext` disagree and YetiPDF is the one that's right, it still loses points. The one ordinary document under 95% is a NIST standard where `pdftotext` turns the small-caps heading into "dvanced ncryption tandard". And most of the files that score badly are ones nothing can read, because the PDF never says which character each glyph stands for.

I also ran all 1,009 PDFs in the pdf.js collection through it, including the ones with no text. Nothing crashed and nothing hung. The lot took 15 seconds.

## Install

```bash
composer require yetidevworks/yetipdf
```

You need **PHP 8.3** or newer and the `zlib` extension, which you almost certainly have. That's it.

## Use it

```php
use YetiPdf\YetiPdf;

$doc = YetiPdf::open('manual.pdf');

foreach ($doc->pages() as $number => $text) {
    // index $text as page $number
}
```

Pages come back one at a time, so memory stays flat however long the document is. There are a few other things you can ask for:

```php
$doc->text();          // the whole document as one string
$doc->page(12);        // just one page
$doc->pageCount();
$doc->info();          // ['Title' => ..., 'Author' => ..., ...]
$doc->isEncrypted();
$doc->warnings();      // anything it had to work around, in plain English

YetiPdf::parse($bytes);   // if you already have the PDF in a string
```

And some options, all with sensible defaults:

```php
use YetiPdf\Options;

$doc = YetiPdf::open('report.pdf', new Options(
    password: 'secret',        // for PDFs that need one to open
    dehyphenate: true,         // "exam-" + "ple" across a line break becomes "example"
    expandLigatures: true,     // "ﬁ" becomes "fi"
    annotations: true,         // include typed-on notes and form field values
    wordGap: 0.11,             // how big a gap counts as a space, as a fraction of the font size
));
```

There's a command line tool as well:

```bash
vendor/bin/yetipdf manual.pdf            # text to stdout, a form feed between pages
vendor/bin/yetipdf --stats manual.pdf    # plus page count, time, memory and warnings
```

## It doesn't give up

Real PDFs are a mess. Their internal index is often wrong, lengths are miscounted, and compressed data gets cut off. Fonts are worse.

**YetiPDF** treats all of that as normal. If the index is broken, it scans the file and builds a new one. If a compressed block is cut short, it keeps what it could read. A font with a mangled character table loses the characters that were mangled, and the rest of the page comes through fine.

Only two things throw an exception, and both happen when you open the file:

- `InvalidPdfException`: it can't be read, or it isn't a PDF.
- `EncryptedException`: it needs a password and you didn't give the right one.

After that, nothing throws. A page that can't be read comes back empty and leaves a note in `warnings()`.

PDFs that are "protected" against copying or printing but open without a password read normally. That covers the old RC4 scheme as well as AES-128 and AES-256, though the AES ones need the `openssl` extension.

## Words come out as words

A PDF doesn't store sentences. It stores instructions like "draw these three letters here, now move a bit, now draw two more". Often there are no spaces at all, and the gaps between words are just gaps.

So **YetiPDF** works out where each piece of text lands on the page and rebuilds the words from that. It's the reason a justified LaTeX paper comes out as readable sentences and not as `besuperiorinqualitywhilebeingmoreparallelizable`. And it does a few more things along the way:

- Words hyphenated across a line break are put back together.
- Ligatures become ordinary letters, so "ﬁnd" is searchable as "find".
- Accents that TeX draws separately from their letter are combined, so you get "Brébeuf" and not "Br´ebeuf".
- Fake bold, where the same text is drawn twice, shows up once.
- Text typed onto a page as a note, and the values filled into form fields, are included.

## What it doesn't do yet

I'd rather you knew now.

- **Scanned pages.** There's no OCR. A scan that already has a hidden text layer works fine, because that layer is ordinary text.
- **Some CJK fonts.** A few PDFs use fonts that never say which character each glyph is, and rely on tables that live outside the file. Text in those fonts is left out, and `warnings()` names the font. Most Japanese, Chinese and Korean PDFs read correctly, including the four in my set of ordinary documents, but one of the pdf.js test files hits this.
- **Arabic and Hebrew ordering.** Right-to-left text comes out in the order it's drawn on the page, which is backwards for reading.
- **Columns.** Text comes out in the order the PDF draws it. That's nearly always what you'd expect, and for search it rarely matters.
- **Huge files.** The whole file is read into memory, so a 1GB PDF needs 1GB.

## Run the benchmarks yourself

Don't take my word for any of this. The scripts are in the repo:

```bash
benchmarks/fetch-corpus.sh                         # about 300MB, downloaded once
composer install -d benchmarks                     # the other libraries, for comparison
benchmarks/bench.sh yetipdf prinsfrank smalot
php benchmarks/diff.php some.pdf                   # which words differ from pdftotext
```

You'll need `pdftotext` installed, since it's the reference. If you find a PDF that **YetiPDF** reads badly, `diff.php` shows exactly which words went missing, and I'd love to see it in an issue.

The numbers above came from an Apple Silicon Mac. The single-document timings are each tool run alone, best of three (smalot got one run, for obvious reasons), and the 218-file totals ran six files at a time.

## Tests

```bash
composer test
```

## License

MIT. The bundled glyph list in `data/glyphlist.php` is Adobe's, under the BSD 3-Clause license reproduced in that file.

Enjoy!

— Andy
