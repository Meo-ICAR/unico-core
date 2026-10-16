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
        /** @var list<string> id dei documenti presso il provider, nello stesso ordine di `EnvelopeData::documents()` */
        public array $documentRefs = [],
    ) {}
}
