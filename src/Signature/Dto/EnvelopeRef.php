<?php

namespace Unico\Core\Signature\Dto;

final readonly class EnvelopeRef
{
    /**
     * @param  array<string, string>  $signerRefs  slot => id del firmatario presso il provider
     */
    public function __construct(
        public string $providerRef,
        public array $signerRefs,
    ) {}
}
