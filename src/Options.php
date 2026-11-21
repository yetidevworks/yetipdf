<?php

declare(strict_types=1);

namespace YetiPdf\Read;

final class Options
{
    public function __construct(
        /** User password, for PDFs that need one to open. */
        public readonly string $password = '',
        /** Rejoin words split by a hyphen at the end of a line. */
        public readonly bool $dehyphenate = true,
        /** Replace ligature characters (ﬁ, ﬂ, ﬀ ...) with the letters they stand for. */
        public readonly bool $expandLigatures = true,
        /** Include the text annotations show on the page: typed-on notes, stamps, and the visible values of form fields. */
        public readonly bool $annotations = true,
        /** Gap, as a fraction of the font size, that separates two words. */
        public readonly float $wordGap = 0.11,
    ) {
    }
}

// The name this class had before 1.3 (yetidevworks/yetipdf), kept until 2.0: see legacy.php.
if (!\class_exists('YetiPdf\\Options', false)) {
    \class_alias(Options::class, 'YetiPdf\\Options', false);
}
