<?php

namespace Unico\Core\Signature;

use InvalidArgumentException;
use Unico\Core\Signature\Contracts\SignatureProvider;

class SignatureProviderManager
{
    /** @var array<string, SignatureProvider> */
    private array $resolved = [];

    public function provider(?string $name = null): SignatureProvider
    {
        $name ??= (string) config('unico-core.signature.driver');

        return $this->resolved[$name] ??= match ($name) {
            'fake' => new FakeSignatureProvider,
            'yousign' => new YousignSignatureProvider((array) config('unico-core.signature.yousign')),
            default => throw new InvalidArgumentException("Provider di firma [{$name}] non supportato."),
        };
    }
}
