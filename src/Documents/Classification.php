<?php

namespace Unico\Core\Documents;

use Unico\Core\Models\DocumentType;

/** Esito della classificazione: il tipo scelto (o nessuno) e come ci si è arrivati. */
final class Classification
{
    public function __construct(
        public readonly ?DocumentType $type,
        public readonly ?int $confidence = null,
        public readonly ?string $abstract = null,
        /** procedural (regex sul nome file) | ai */
        public readonly ?string $operator = null,
        public readonly ?string $label = null,
        /** consumo AI, se la classificazione ha chiamato l'AI */
        public readonly ?array $usage = null,
    ) {}

    public function matched(): bool
    {
        return $this->type !== null;
    }
}
