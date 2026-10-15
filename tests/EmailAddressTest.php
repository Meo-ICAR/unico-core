<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Unico\Core\Support\EmailAddress;

class EmailAddressTest extends TestCase
{
    public static function valide(): array
    {
        return [
            'semplice' => ['mario.rossi@example.com'],
            'con più' => ['mario+bolletta@example.it'],
            'sottodominio' => ['info@mail.azienda.example.it'],
            'trattino nel dominio' => ['a@my-company.it'],
            'maiuscole e spazi attorno' => ['  Mario.Rossi@Example.COM  '],
            'con mailto' => ['mailto:mario@example.com'],
            'con apice' => ["o'brien@example.ie"],
        ];
    }

    public static function non_valide(): array
    {
        return [
            'vuota' => [''],
            'solo spazi' => ['   '],
            'senza chiocciola' => ['mario.example.com'],
            'due chiocciole' => ['a@b@example.com'],
            'senza suffisso' => ['mario@example'],
            'dominio con punto finale' => ['mario@example.com.'],
            'dominio che inizia con punto' => ['mario@.example.com'],
            'punti consecutivi nel nome' => ['ma..rio@example.com'],
            'nome che inizia con punto' => ['.mario@example.com'],
            'nome che finisce con punto' => ['mario.@example.com'],
            'nome vuoto' => ['@example.com'],
            'spazio dentro' => ['mario rossi@example.com'],
            'trattino a inizio etichetta' => ['mario@-example.com'],
            'suffisso numerico' => ['mario@example.123'],
            'suffisso di una lettera' => ['mario@example.c'],
            'nome troppo lungo' => [str_repeat('a', 65).'@example.com'],
            'troppo lunga' => [str_repeat('a', 60).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 63).'.it'],
        ];
    }

    #[DataProvider('valide')]
    public function test_accetta_gli_indirizzi_validi(string $email): void
    {
        $this->assertNull(EmailAddress::check($email), $email);
        $this->assertTrue(EmailAddress::isValid($email));
    }

    #[DataProvider('non_valide')]
    public function test_rifiuta_gli_indirizzi_non_validi_con_un_messaggio(string $email): void
    {
        $message = EmailAddress::check($email);

        $this->assertIsString($message, $email);
        $this->assertNotSame('', $message);
        $this->assertFalse(EmailAddress::isValid($email));
    }

    public function test_normalizza_spazi_maiuscole_e_mailto(): void
    {
        $this->assertSame('mario.rossi@example.com', EmailAddress::normalize('  mailto:Mario.Rossi@Example.COM '));
        $this->assertSame('', EmailAddress::normalize('   '));
    }

    public function test_propone_la_correzione_dei_refusi_comuni_nel_dominio(): void
    {
        $this->assertSame('mario@gmail.com', EmailAddress::suggest('mario@gmial.com'));
        $this->assertSame('mario@gmail.com', EmailAddress::suggest('mario@gmail.con'));
        $this->assertSame('mario@hotmail.it', EmailAddress::suggest('mario@hotmial.it'));
        $this->assertNull(EmailAddress::suggest('mario@gmail.com'));
        $this->assertNull(EmailAddress::suggest('mario@azienda.it'));
        $this->assertNull(EmailAddress::suggest('non è una email'));
    }

    public function test_il_messaggio_dice_cosa_manca(): void
    {
        $this->assertStringContainsString('@', (string) EmailAddress::check('mario.example.com'));
        $this->assertStringContainsString('suffisso', (string) EmailAddress::check('mario@example'));
    }
}
