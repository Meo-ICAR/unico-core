<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Documents\Reminders\Escalation;
use Unico\Core\Enums\MailType;
use Unico\Core\Mail\EmailTemplateResolver;
use Unico\Core\Mail\SenderAccountResolver;
use Unico\Core\Models\Company;
use Unico\Core\Models\EmailTemplate;
use Unico\Core\Models\MailAccount;
use Unico\Core\Tests\Support\CoreDatabase;

class MailResolversTest extends TestCase
{
    private Company $acme;

    private Company $beta;

    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        CoreDatabase::boot();
        CoreDatabase::truncate('email_templates', 'mail_accounts', 'companies');
        $this->acme = Company::create(['name' => 'Acme']);
        $this->beta = Company::create(['name' => 'Beta']);
    }

    private function account(?Company $company, string $type, string $email, bool $smtp = true): MailAccount
    {
        return MailAccount::create([
            'name' => $email, 'email_address' => $email, 'imap_host' => 'imap.example.test', 'imap_port' => 993, 'imap_username' => $email,
            'smtp_host' => $smtp ? 'smtp.example.test' : null, 'smtp_password' => $smtp ? 'segreto' : null,
            'company_id' => $company?->id, 'type' => $type,
        ]);
    }

    private function template(array $attributes = []): EmailTemplate
    {
        return EmailTemplate::create($attributes + ['code' => 'DOC_EXPIRING', 'name' => 'T', 'subject' => 'S', 'body' => 'B']);
    }

    // --- Casella mittente: company e tipo ---

    public function test_the_sender_is_chosen_by_company_and_type(): void
    {
        $sollecito = $this->account($this->acme, 'sollecito', 'solleciti@acme.test');
        $this->account($this->acme, 'processo', 'processi@acme.test');
        $this->account($this->beta, 'sollecito', 'solleciti@beta.test');
        $resolver = new SenderAccountResolver;

        $this->assertSame($sollecito->id, $resolver->resolve($this->acme->id, MailType::Sollecito)?->id);
        $this->assertSame('processi@acme.test', $resolver->resolve($this->acme->id, 'processo')?->email_address);
        $this->assertSame('solleciti@beta.test', $resolver->resolve($this->beta->id, MailType::Sollecito)?->email_address);
    }

    public function test_without_a_dedicated_account_the_general_one_and_then_the_shared_one_are_used(): void
    {
        $resolver = new SenderAccountResolver;
        $this->assertNull($resolver->resolve($this->acme->id, MailType::Sollecito));

        $shared = $this->account(null, 'sollecito', 'solleciti@unico.test');
        $this->assertSame($shared->id, $resolver->resolve($this->acme->id, MailType::Sollecito)?->id);

        $general = $this->account($this->acme, 'generale', 'info@acme.test');
        $this->assertSame($general->id, $resolver->resolve($this->acme->id, MailType::Sollecito)?->id);
        // Il comune non serve a una company senza casella sua del tipo giusto se ne ha una generale: quella viene prima.
        $own = $this->account($this->acme, 'sollecito', 'solleciti@acme.test');
        $this->assertSame($own->id, $resolver->resolve($this->acme->id, MailType::Sollecito)?->id);
    }

    public function test_inactive_accounts_and_accounts_that_cannot_send_are_skipped(): void
    {
        $noSmtp = $this->account($this->acme, 'sollecito', 'sola-lettura@acme.test', smtp: false);
        $inactive = $this->account($this->acme, 'sollecito', 'spenta@acme.test');
        $inactive->update(['is_active' => false]);
        $ok = $this->account($this->acme, 'sollecito', 'attiva@acme.test');

        $this->assertSame($ok->id, (new SenderAccountResolver)->resolve($this->acme->id, MailType::Sollecito)?->id);
        $this->assertNotSame($noSmtp->id, $ok->id);
    }

    public function test_a_company_never_gets_the_account_of_another_company(): void
    {
        $this->account($this->beta, 'sollecito', 'solleciti@beta.test');

        $this->assertNull((new SenderAccountResolver)->resolve($this->acme->id, MailType::Sollecito));
        $this->assertNull((new SenderAccountResolver)->resolve(null, MailType::Sollecito));
    }

    // --- Template: company, app, tipo e severity ---

    public function test_the_company_template_wins_over_the_shared_one_and_the_app_over_the_generic(): void
    {
        $shared = $this->template(['name' => 'comune']);
        $app = $this->template(['name' => 'app', 'app_identifier' => 'unicooam']);
        $company = $this->template(['name' => 'company', 'company_id' => $this->acme->id]);
        $resolver = new EmailTemplateResolver;

        $this->assertSame($company->id, $resolver->resolve('DOC_EXPIRING', $this->acme->id, 'unicooam')?->id);
        $this->assertSame($app->id, $resolver->resolve('DOC_EXPIRING', $this->beta->id, 'unicooam')?->id);
        $this->assertSame($shared->id, $resolver->resolve('DOC_EXPIRING', $this->beta->id, 'unicoloan')?->id);
        $this->assertSame($shared->id, $resolver->resolve('DOC_EXPIRING')?->id);
    }

    public function test_a_template_of_another_company_or_app_is_never_used(): void
    {
        $this->template(['company_id' => $this->beta->id]);
        $this->template(['code' => 'ALTRO', 'app_identifier' => 'unicobpm']);
        $resolver = new EmailTemplateResolver;

        $this->assertNull($resolver->resolve('DOC_EXPIRING', $this->acme->id));
        $this->assertNull($resolver->resolve('ALTRO', $this->acme->id, 'unicooam'));
    }

    public function test_inactive_templates_and_other_types_are_skipped(): void
    {
        $this->template(['is_active' => false]);
        $this->template(['code' => 'PROCESSO_X', 'type' => 'processo']);
        $resolver = new EmailTemplateResolver;

        $this->assertNull($resolver->resolve('DOC_EXPIRING'));
        $this->assertNull($resolver->resolve('PROCESSO_X', type: MailType::Sollecito));
        $this->assertNotNull($resolver->resolve('PROCESSO_X', type: MailType::Processo));
    }

    public function test_the_severity_picks_the_exact_grade_then_the_closest_lower_one_then_the_plain_template(): void
    {
        $plain = $this->template(['name' => 'senza grado']);
        $regular = $this->template(['name' => 'regular', 'severity' => 'regular']);
        $alert = $this->template(['name' => 'alert', 'severity' => 'alert']);
        $resolver = new EmailTemplateResolver;

        $this->assertSame($alert->id, $resolver->resolve('DOC_EXPIRING', severity: 'alert')?->id);
        // Per «warning» non c'è: vale il grado inferiore più vicino, mai uno più alto.
        $this->assertSame($regular->id, $resolver->resolve('DOC_EXPIRING', severity: 'warning')?->id);
        $this->assertSame($plain->id, $resolver->resolve('DOC_EXPIRING', severity: 'ok')?->id);
        // Senza severity richiesta valgono solo i template senza grado.
        $this->assertSame($plain->id, $resolver->resolve('DOC_EXPIRING')?->id);
    }

    public function test_the_company_template_beats_a_better_matching_severity_of_the_shared_one(): void
    {
        $this->template(['name' => 'comune alert', 'severity' => 'alert']);
        $own = $this->template(['name' => 'company regular', 'severity' => 'regular', 'company_id' => $this->acme->id]);

        $this->assertSame($own->id, (new EmailTemplateResolver)->resolve('DOC_EXPIRING', $this->acme->id, severity: 'alert')?->id);
    }

    // --- Escalation ---

    public function test_the_severity_follows_the_expiry_and_the_reminders_already_sent(): void
    {
        $this->assertSame('ok', Escalation::severity(45, 0));
        $this->assertSame('regular', Escalation::severity(30, 0));
        $this->assertSame('warning', Escalation::severity(7, 0));
        $this->assertSame('warning', Escalation::severity(0, 0));
        $this->assertSame('alert', Escalation::severity(-1, 0));
        // Più solleciti inviati fanno salire il grado anche se la scadenza è lontana, mai scendere.
        $this->assertSame('regular', Escalation::severity(45, 1));
        $this->assertSame('warning', Escalation::severity(45, 2));
        $this->assertSame('alert', Escalation::severity(45, 3));
        $this->assertSame('alert', Escalation::severity(-10, 1));
        $this->assertSame('warning', Escalation::severity(5, 1));
    }

    public function test_the_thresholds_come_from_the_configuration(): void
    {
        config(['unico-core.documents.reminders.escalation' => ['days' => ['alert' => 0, 'warning' => 3, 'regular' => 10], 'sent' => ['regular' => 2, 'warning' => 4, 'alert' => 6]]]);

        $this->assertSame('ok', Escalation::severity(20, 1));
        $this->assertSame('regular', Escalation::severity(10, 0));
        $this->assertSame('regular', Escalation::severity(20, 2));
        $this->assertSame('alert', Escalation::severity(20, 6));
    }

    public function test_accounts_can_be_filtered_by_type(): void
    {
        $this->account($this->acme, 'processo', 'processi@acme.test');
        $this->account($this->acme, 'sollecito', 'solleciti@acme.test');

        $this->assertSame(['processi@acme.test'], MailAccount::query()->ofType('processo')->pluck('email_address')->all());
    }
}
