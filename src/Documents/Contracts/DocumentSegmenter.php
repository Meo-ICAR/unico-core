<?php

namespace Unico\Core\Documents\Contracts;

/** Capisce se un PDF contiene più documenti distinti e a quali pagine. Senza AI configurata vale `NullDocumentSegmenter`. */
interface DocumentSegmenter
{
    public function enabled(): bool;

    /**
     * Intervalli di pagine (da 1) dei documenti contenuti nel PDF, in ordine e senza buchi; null se non è stato possibile capirlo.
     *
     * @return list<array{from: int, to: int, label: ?string}>|null
     */
    public function segments(string $pdfBytes, int $pages): ?array;
}
