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
    ) {}
}
