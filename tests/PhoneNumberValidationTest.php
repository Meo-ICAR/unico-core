<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Unico\Core\Signature\PhoneNumber;

class PhoneNumberValidationTest extends TestCase
{
    public static function cellulari(): array
    {
        return [
            'nazionale' => ['3331234567'],
            'con spazi' => ['333 123 4567'],
            'con prefisso +39' => ['+39 333 1234567'],
            'con prefisso 0039' => ['0039 333 1234567'],
            'con trattini' => ['333-123-4567'],
            'con parentesi' => ['(333) 1234567'],
            'nove cifre' => ['320123456'],
        ];
    }

    public static function fissi(): array
    {
        return [['0612345678'], ['+39 06 1234567'], ['02 12345678'], ['0171 123456']];
    }

    public static function esteri(): array
    {
        return [['+44 7911 123456'], ['0044 20 7946 0958'], ['+49 151 12345678']];
    }

    public static function non_validi(): array
    {
        return [
            'vuoto' => [''],
            'lettere' => ['333abc4567'],
            'troppo corto' => ['12345'],
            'troppo lungo' => ['+39 333 123456789012'],
            'italiano che non inizia per 3 o 0' => ['5551234567'],
            'cellulare troppo corto' => ['333123'],
            'solo prefisso' => ['+39'],
            'simboli' => ['333#1234567'],
        ];
    }

    #[DataProvider('cellulari')]
    public function test_riconosce_i_cellulari_italiani(string $phone): void
    {
        $this->assertNull(PhoneNumber::check($phone), $phone);
        $this->assertTrue(PhoneNumber::isMobile($phone), $phone);
        $this->assertSame('mobile', PhoneNumber::type($phone));
    }

    #[DataProvider('fissi')]
    public function test_riconosce_i_fissi_italiani_e_non_li_accetta_come_cellulare(string $phone): void
    {
        $this->assertNull(PhoneNumber::check($phone), $phone);
        $this->assertSame('landline', PhoneNumber::type($phone));
        $this->assertFalse(PhoneNumber::isMobile($phone));
        $this->assertIsString(PhoneNumber::check($phone, mobileOnly: true));
    }

    #[DataProvider('esteri')]
    public function test_accetta_i_numeri_esteri_in_forma_internazionale(string $phone): void
    {
        $this->assertNull(PhoneNumber::check($phone), $phone);
        $this->assertSame('other', PhoneNumber::type($phone));
    }

    #[DataProvider('non_validi')]
    public function test_rifiuta_i_numeri_non_validi_con_un_messaggio(string $phone): void
    {
        $this->assertIsString(PhoneNumber::check($phone), $phone);
        $this->assertFalse(PhoneNumber::isValid($phone));
        $this->assertNull(PhoneNumber::type($phone));
    }

    public function test_null_non_e_valido(): void
    {
        $this->assertFalse(PhoneNumber::isValid(null));
        $this->assertIsString(PhoneNumber::check(null));
    }

    public function test_un_numero_estero_non_e_accettato_come_cellulare_italiano_ma_si_puo_ammettere(): void
    {
        $this->assertIsString(PhoneNumber::check('+44 7911 123456', mobileOnly: true));
        $this->assertNull(PhoneNumber::check('+44 7911 123456', mobileOnly: true, allowForeign: true));
    }

    public function test_il_messaggio_spiega_il_problema(): void
    {
        $this->assertStringContainsString('cifre', (string) PhoneNumber::check('333abc4567'));
        $this->assertStringContainsString('cellulare', (string) PhoneNumber::check('0612345678', mobileOnly: true));
    }
}
