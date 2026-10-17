<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Pdf\ModuleKeyLabel;

class ModuleKeyLabelTest extends TestCase
{
    public function test_le_chiavi_dei_moduli_hanno_un_nome_leggibile(): void
    {
        $this->assertSame('Cliente: codice fiscale', ModuleKeyLabel::for('client.tax_code'));
        $this->assertSame('Indirizzo del cliente: CAP', ModuleKeyLabel::for('client.address.zip_code'));
        $this->assertSame('Titolare effettivo 2: data di nascita', ModuleKeyLabel::for('kyc.owner2.birth_date'));
        $this->assertSame('Esecutore: numero documento', ModuleKeyLabel::for('kyc.executor.doc_number'));
        $this->assertSame('Documento d\'identità: ente di rilascio', ModuleKeyLabel::for('document.identity.emitted_by'));
        $this->assertSame('Altro campo strano', ModuleKeyLabel::for('altro.campo_strano'));
        $this->assertSame(['Cliente: email'], ModuleKeyLabel::many(['client.email', 'client.email']));
    }
}
