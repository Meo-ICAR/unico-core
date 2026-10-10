<?php

namespace Unico\Core\Tests;

use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;
use Unico\Core\Models\EmailTemplate;
use Unico\Core\Tests\Support\CoreDatabase;

class EmailTemplateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('email_templates');
    }

    private function template(string $code, ?string $severity): EmailTemplate
    {
        return EmailTemplate::create(['code' => $code, 'name' => 'T', 'subject' => 'S', 'body' => 'B', 'severity' => $severity]);
    }

    public function test_the_same_code_can_have_one_template_per_severity(): void
    {
        $this->template('CHECK_BLACKLIST', 'warning');
        $this->template('CHECK_BLACKLIST', 'alert');
        $this->template('CHECK_BLACKLIST', null);

        $this->assertSame(['alert', 'warning'], EmailTemplate::where('code', 'CHECK_BLACKLIST')->whereNotNull('severity')->orderBy('severity')->pluck('severity')->all());
    }

    public function test_code_and_severity_together_are_unique(): void
    {
        $this->template('CHECK_BLACKLIST', 'alert');

        $this->expectException(QueryException::class);

        $this->template('CHECK_BLACKLIST', 'alert');
    }
}
