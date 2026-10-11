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
        CoreDatabase::truncate('email_templates', 'companies');
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

    public function test_code_and_severity_together_are_unique_in_the_same_scope(): void
    {
        $this->template('CHECK_BLACKLIST', 'alert');

        $this->expectException(\InvalidArgumentException::class);

        $this->template('CHECK_BLACKLIST', 'alert');
    }

    public function test_the_same_code_and_severity_can_exist_for_another_company_or_app(): void
    {
        $company = \Unico\Core\Models\Company::create(['name' => 'Azienda']);
        $this->template('DOC_EXPIRING', 'alert');
        EmailTemplate::create(['code' => 'DOC_EXPIRING', 'name' => 'T', 'subject' => 'S', 'body' => 'B', 'severity' => 'alert', 'company_id' => $company->id]);
        EmailTemplate::create(['code' => 'DOC_EXPIRING', 'name' => 'T', 'subject' => 'S', 'body' => 'B', 'severity' => 'alert', 'app_identifier' => 'unicooam']);

        $this->assertSame(3, EmailTemplate::where('code', 'DOC_EXPIRING')->count());
    }

    public function test_an_existing_template_can_be_saved_again(): void
    {
        $template = $this->template('DOC_EXPIRING', null);
        $template->update(['subject' => 'Nuovo oggetto']);

        $this->assertSame('Nuovo oggetto', $template->fresh()->subject);
    }
}
