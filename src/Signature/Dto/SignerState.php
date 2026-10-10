<?php

namespace Unico\Core\Signature\Dto;

use Unico\Core\Enums\SignerStatus;
use Carbon\CarbonInterface;

final readonly class SignerState
{
    public function __construct(
        public string $providerSignerRef,
        public SignerStatus $status,
        public ?CarbonInterface $signedAt,
    ) {}
}
