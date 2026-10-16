<?php

namespace Unico\Core\Signature\Dto;

final readonly class SignaturePlacement
{
    public function __construct(
        public int $page,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        /** Documento della busta su cui sta il punto di firma (0 = principale). */
        public int $document = 0,
    ) {}
}
