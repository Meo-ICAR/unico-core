<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Models\Client;
use Unico\Core\Tests\Support\CoreDatabase;

class ClientConsultantTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    public function test_a_client_with_roles_in_the_org_chart_is_a_consultant_on_save(): void
    {
        $consultant = Client::create(['name' => 'Studio Alfa', 'is_company' => false, 'employee_roles' => ['compliance']]);
        $plain = Client::create(['name' => 'Cliente Beta', 'is_company' => false]);

        $this->assertTrue($consultant->refresh()->is_company);
        $this->assertFalse($plain->refresh()->is_company);

        $plain->update(['employee_roles' => ['audit']]);
        $this->assertTrue($plain->refresh()->is_company);
    }
}
