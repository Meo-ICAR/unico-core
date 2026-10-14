<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Pdf\SignatureAnchors;

class PdfSignatureAnchorsTest extends TestCase
{
    private function html(): string
    {
        return <<<'HTML'
<doc><page width="595.000000" height="842.000000">
<line xMin="100.0" yMin="100.0" xMax="200.0" yMax="110.0"><word>Testo</word></line>
</page>
<page width="595.000000" height="842.000000">
<line xMin="340.0" yMin="667.0" xMax="540.0" yMax="672.0"><word>{{Sig1_ev_:signer1:signature:dimension(width=50mm,</word><word>height=9mm)}}</word></line>
<line xMin="318.0" yMin="500.0" xMax="518.0" yMax="505.0"><word>{{Sig2_ev_:signer2:signature:dimension(width=60mm,</word><word>height=10mm)}}</word></line>
</page>
<page width="595.000000" height="842.000000">
<line xMin="560.0" yMin="100.0" xMax="590.0" yMax="105.0"><word>{{Sig1_ev_:signer1:signature:dimension(width=50mm,</word><word>height=9mm)}}</word></line>
</page></doc>
HTML;
    }

    public function test_i_segnaposto_diventano_slot_con_pagina_posizione_e_misura(): void
    {
        $slots = (new SignatureAnchors)->fromBoundingBoxes($this->html());

        $this->assertCount(3, $slots);
        $this->assertSame(['page' => 2, 'x' => 340, 'y' => 646, 'width' => 142, 'height' => 26, 'role' => 'client', 'slot' => 'cliente'], $slots[0]);
        $this->assertSame('collaboratore', $slots[1]['slot']);
        $this->assertSame('fornitore', $slots[1]['role']);
        $this->assertSame(170, $slots[1]['width']);
    }

    public function test_il_riquadro_non_esce_dalla_pagina(): void
    {
        $slots = (new SignatureAnchors)->fromBoundingBoxes($this->html());

        $this->assertSame(3, $slots[2]['page']);
        $this->assertSame(595 - 142, $slots[2]['x']);
    }

    public function test_senza_segnaposto_nessuno_slot(): void
    {
        $this->assertSame([], (new SignatureAnchors)->fromBoundingBoxes('<doc><page width="595" height="842"><line xMin="1" yMin="1" xMax="2" yMax="2"><word>ciao</word></line></page></doc>'));
    }
}
