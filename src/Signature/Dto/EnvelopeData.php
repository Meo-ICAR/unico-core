<?php

namespace Unico\Core\Signature\Dto;

final readonly class EnvelopeData
{
    /**
     * @param  array<int, EnvelopeSigner>  $signers  ordinati per position
     */
    public function __construct(
        public string $name,
        public string $fileName,
        public string $pdf,
        public array $signers,
        public ?\DateTimeInterface $expiresAt,
        public string $externalId,
        public ?string $emailSubject = null,
        /** Altri documenti del plico: nei punti di firma `SignaturePlacement::$document` 0 è il principale, 1 il primo di questi. */
        public array $extraDocuments = [],
    ) {}

    /** @return list<EnvelopeDocument> tutti i documenti della busta, il principale per primo */
    public function documents(): array
    {
        return [new EnvelopeDocument($this->name, $this->fileName, $this->pdf), ...array_values($this->extraDocuments)];
    }
}
