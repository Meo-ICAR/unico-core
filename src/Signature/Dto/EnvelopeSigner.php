<?php

namespace Unico\Core\Signature\Dto;

use Unico\Core\Enums\SignerRole;

final readonly class EnvelopeSigner
{
    public function __construct(
        public int $position,
        public string $slot,
        public SignerRole $role,
        public string $firstName,
        public string $lastName,
        public ?string $email,
        public ?string $phone,
        public ?string $taxCode,
        public SignaturePlacement $placement,
    ) {}
}
