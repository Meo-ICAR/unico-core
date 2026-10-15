<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;

class PartitaIvaTest extends TestCase
{
    public function test_accetta_partita_iva_italiana_con_checksum_corretto(): void
    {
        $this->assertNull(controllaPartitaIva('12345678903'));
        $this->assertTrue(partitaIvaValida('12345678903'));
        $this->assertTrue(partitaIvaItalianaChecksum('12345678903'));
    }

    public function test_rifiuta_checksum_italiano_errato(): void
    {
        $this->assertSame('La Partita IVA non è valida.', controllaPartitaIva('12345678900'));
        $this->assertFalse(partitaIvaValida('12345678900'));
    }

    public function test_accetta_prefisso_it_e_controlla_il_checksum(): void
    {
        $this->assertNull(controllaPartitaIva('it12345678903'));
        $this->assertSame(
            'La Partita IVA italiana non è valida (checksum errato).',
            controllaPartitaIva('IT12345678900'),
        );
    }

    public function test_accetta_le_altre_partite_iva_ue_col_solo_prefisso(): void
    {
        $this->assertNull(controllaPartitaIva('DE12345678901'));
        $this->assertNull(controllaPartitaIva('XI12345678901'));
        $this->assertSame('Prefisso nazione non valido per Partita IVA UE.', controllaPartitaIva('XX12345678901'));
    }

    public function test_rifiuta_lunghezza_e_caratteri_non_numerici(): void
    {
        $this->assertSame(
            'La Partita IVA deve essere di 11 caratteri (Italia) o 13 caratteri (UE).',
            controllaPartitaIva('12345'),
        );
        $this->assertSame(
            'La Partita IVA italiana deve contenere solo cifre.',
            controllaPartitaIva('1234567890A'),
        );
        $this->assertSame(
            'La parte numerica della Partita IVA italiana deve contenere solo cifre.',
            controllaPartitaIva('IT1234567890A'),
        );
    }

    public function test_rifiuta_la_partita_iva_di_soli_zeri_anche_se_il_checksum_torna(): void
    {
        $this->assertNotNull(controllaPartitaIva('00000000000'));
        $this->assertFalse(partitaIvaValida('00000000000'));
        $this->assertFalse(partitaIvaValida('IT00000000000'));
    }
}
