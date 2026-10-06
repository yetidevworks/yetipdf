#!/bin/sh
# Scores text extraction against pdftotext (poppler) over the corpus.
#
#   benchmarks/bench.sh                      yetipdf only
#   benchmarks/bench.sh yetipdf prinsfrank smalot
#
# pdftotext is the reference: "recall" is the share of its words a library also found, and a document
# that fails counts as zero. Only PDFs where pdftotext finds at least 10 words are scored.
# JOBS sets how many PDFs run at once (default 6); use JOBS=1 for clean timings.
set -e
cd "$(dirname "$0")"
command -v pdftotext >/dev/null || { echo "pdftotext (poppler) is needed as the reference" >&2; exit 1; }
[ -d corpus ] || { echo "No corpus yet: run benchmarks/fetch-corpus.sh" >&2; exit 1; }
LIBS="${*:-yetipdf}"
JOBS="${JOBS:-6}"
mkdir -p out

{ ls corpus/pdfjs/test/pdfs/*.pdf; ls corpus/real/*.pdf; } > out/files.txt

# The reference only needs producing once per file.
while read -r pdf; do
    [ -f "out/ref.$(php -r 'echo md5($argv[1]);' "$pdf").json" ] || echo "$pdf"
done < out/files.txt | xargs -P "$JOBS" -I{} php ref.php {} out

php eligible.php out/files.txt out > out/eligible.txt
echo "$(wc -l < out/eligible.txt | tr -d ' ') of $(wc -l < out/files.txt | tr -d ' ') PDFs have enough text to score"

for lib in $LIBS; do
    xargs -P "$JOBS" -I{} php runner.php "$lib" {} out < out/eligible.txt
done

# shellcheck disable=SC2086
php score.php out/eligible.txt out $LIBS
