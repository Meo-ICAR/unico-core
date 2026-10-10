<?php

namespace Unico\Core\Signature\Contracts;

use Unico\Core\Signature\Dto\EnvelopeData;
use Unico\Core\Signature\Dto\EnvelopeRef;
use Unico\Core\Signature\Dto\EnvelopeStatus;
use Unico\Core\Signature\Dto\SignatureEvent;
use Illuminate\Http\Request;

interface SignatureProvider
{
    public function name(): string;

    /** @return array<int, string> campi di contatto obbligatori per ogni firmatario: 'email' e/o 'phone' */
    public function requiredContactFields(): array;

    public function createEnvelope(EnvelopeData $envelope): EnvelopeRef;

    public function envelopeStatus(string $providerRef): EnvelopeStatus;

    /** @return string contenuto binario del PDF firmato */
    public function downloadSigned(string $providerRef): string;

    public function cancelEnvelope(string $providerRef): void;

    /** Verifica autenticità e normalizza; null = firma non valida (401); un evento con type 'ignored' = valido ma ignorabile (204). */
    public function parseWebhook(Request $request): ?SignatureEvent;
}
