<?php

namespace Unico\Core\Tests;

use Unico\Core\Signature\PhoneNumber;
use PHPUnit\Framework\TestCase;

class PhoneNumberNationalTest extends TestCase
{
    public function test_toglie_simboli_e_prefisso_italiano(): void
    {
        foreach (['+39 333 111 2222', '0039 333-111.2222', '393331112222', '3331112222', '(333) 1112222'] as $phone) {
            $this->assertSame('3331112222', PhoneNumber::national($phone), $phone);
        }
    }

    public function test_tiene_il_prefisso_zero_dei_fissi_e_gestisce_il_vuoto(): void
    {
        $this->assertSame('061234567', PhoneNumber::national('+39 06 1234567'));
        $this->assertSame('', PhoneNumber::national(''));
        $this->assertSame('', PhoneNumber::national('abc'));
    }

    public function test_due_numeri_coincidono_se_uguali_in_forma_nazionale(): void
    {
        $this->assertTrue(PhoneNumber::same('+393331112222', '333 111 2222'));
        $this->assertFalse(PhoneNumber::same('3331112222', '3339998888'));
        $this->assertFalse(PhoneNumber::same('', ''));
    }
}
