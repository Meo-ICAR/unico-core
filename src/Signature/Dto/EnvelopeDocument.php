<?php

namespace Unico\Core\Signature\Dto;

/** Un documento della busta di firma oltre al principale (plico: più PDF, un solo OTP). */
final readonly class EnvelopeDocument
{
    public function __construct(
        public string $name,
        public string $fileName,
        public string $pdf,
    ) {}
}
