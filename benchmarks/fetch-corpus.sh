#!/bin/sh
# Downloads the benchmark corpus into benchmarks/corpus (about 300MB, not committed):
#   corpus/pdfjs  the pdf.js test PDFs, which are small and deliberately awkward
#   corpus/real   ordinary public documents listed in urls.txt
set -e
cd "$(dirname "$0")"
mkdir -p corpus/real

if [ ! -d corpus/pdfjs ]; then
    git clone --depth 1 --filter=blob:none --sparse https://github.com/mozilla/pdf.js.git corpus/pdfjs
    git -C corpus/pdfjs sparse-checkout set test/pdfs
fi

while read -r name url; do
    [ -s "corpus/real/$name" ] && continue
    echo "fetching $name"
    curl -sL --max-time 180 -A 'Mozilla/5.0 yetipdf-benchmark' -o "corpus/real/$name" "$url" || true
    # Some hosts answer with an HTML error page; keep only real PDFs.
    if [ "$(head -c 5 "corpus/real/$name" 2>/dev/null)" != "%PDF-" ]; then
        echo "  not a PDF, skipped"
        mv "corpus/real/$name" "corpus/real/$name.rejected"
    fi
done < urls.txt
