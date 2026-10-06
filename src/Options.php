<?php

declare(strict_types=1);

namespace YetiPdf;

final class Options
{
    public function __construct(
        /** User password, for PDFs that need one to open. */
        public readonly string $password = '',
        /** Rejoin words split by a hyphen at the end of a line. */
        public readonly bool $dehyphenate = true,
        /** Replace ligature characters (ﬁ, ﬂ, ﬀ ...) with the letters they stand for. */
        public readonly bool $expandLigatures = true,
        /** Include text typed onto the page as an annotation, and the visible values of form fields. */
        public readonly bool $annotations = true,
        /** Gap, as a fraction of the font size, that separates two words. */
        public readonly float $wordGap = 0.11,
    ) {
    }
}
