<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Enums\SignerRole;
use Unico\Core\Signature\Dto\EnvelopeSigner;
use Unico\Core\Signature\Dto\SignaturePlacement;

class SignatureMultiPlacementTest extends TestCase
{
    public function test_un_firmatario_puo_firmare_in_piu_punti(): void
    {
        $first = new SignaturePlacement(2, 340.0, 646.0, 142.0, 26.0);
        $second = new SignaturePlacement(10, 318.0, 187.0, 142.0, 26.0);

        $signer = new EnvelopeSigner(1, 'cliente', SignerRole::Client, 'Mario', 'Rossi', 'mario@example.test', '+393381234567', null, $first, [$second]);

        $this->assertSame([$first, $second], $signer->placements());
        $this->assertSame([$first], (new EnvelopeSigner(1, 'cliente', SignerRole::Client, 'Mario', 'Rossi', null, null, null, $first))->placements());
    }

    public function test_il_ruolo_dei_moduli_importati_si_riconosce(): void
    {
        $this->assertSame(SignerRole::Fornitore, SignerRole::fromSlot('collaborator'));
        $this->assertSame(SignerRole::Fornitore, SignerRole::fromSlot('fornitore'));
        $this->assertSame(SignerRole::Client, SignerRole::fromSlot('client'));
    }
}
