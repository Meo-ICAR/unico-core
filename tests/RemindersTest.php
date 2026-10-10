<?php

namespace Unico\Core\Tests;

use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Unico\Core\Documents\Reminders\ConfigRecipientResolver;
use Unico\Core\Documents\Reminders\DocumentReminderService;
use Unico\Core\Documents\Reminders\ExpiryStatus;
use Unico\Core\Documents\Reminders\ScheduleSynchronizer;
use Unico\Core\Enums\DocumentStatus;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentAnalysis;
use Unico\Core\Models\DocumentReminder;
use Unico\Core\Models\DocumentSchedule;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\EmailTemplate;
use Unico\Core\Models\Employee;
use Unico\Core\Tests\Support\CoreDatabase;
use Unico\Core\Tests\Support\FakeMailManager;

class RemindersTest extends TestCase
{
    private FakeMailManager $mail;

    private DocumentReminderService $service;

    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('document_schedules', 'document_reminders', 'document_analyses', 'documents', 'document_types', 'email_templates', 'employees');
        Carbon::setTestNow('2026-10-10 09:00:00');
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap(\Unico\Core\MorphMap::all(), merge: true);

        $defaults = (require __DIR__.'/../config/unico-core.php')['documents']['reminders'];
        config(['unico-core.documents.reminders' => $defaults]);

        $this->mail = new FakeMailManager;
        Container::getInstance()->instance('mail.manager', $this->mail);
        Facade::clearResolvedInstance('mail.manager');

        $this->service = new DocumentReminderService(new ConfigRecipientResolver);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    private function template(array $attributes = []): EmailTemplate
    {
        return EmailTemplate::create($attributes + [
            'code' => 'DOC_EXPIRING', 'name' => 'Scadenza', 'subject' => 'Documento in scadenza: {documento_nome}',
            'body' => '<p>Gentile {agente_nome}, scade il {data_scadenza}.</p>', 'is_active' => true,
        ]);
    }

    private function employee(string $name = 'Mario Rossi', ?string $email = 'mario@example.com'): Employee
    {
        return Employee::create(['name' => $name, 'email' => $email]);
    }

    private function type(array $attributes = []): DocumentType
    {
        return DocumentType::create($attributes + ['name' => 'Tipo '.uniqid(), 'code' => 'T'.uniqid(), 'is_monitored' => true]);
    }

    private function doc(Employee $owner, ?DocumentType $type = null, array $attributes = []): Document
    {
        return Document::create($attributes + [
            'documentable_type' => 'employee',
            'documentable_id' => $owner->id,
            'document_type_id' => ($type ?? $this->type())->id,
            'name' => 'Doc '.uniqid(),
            'is_monitored' => true,
            'expires_at' => Carbon::now()->addDays(7)->toDateString(),
        ]);
    }

    private function ids(): array
    {
        return $this->service->scheduleQuery()->pluck('documents.id')->all();
    }

    // --- Cosa entra nello scadenziario ---

    public function test_schedule_includes_expiring_pending_rejected_and_anomalous_documents(): void
    {
        $e = $this->employee();
        $expiring = $this->doc($e);
        $pending = $this->doc($e, null, ['expires_at' => null, 'is_monitored' => false, 'status' => DocumentStatus::PENDING]);
        $rejected = $this->doc($e, null, ['expires_at' => null, 'is_monitored' => false, 'status' => DocumentStatus::REJECTED]);
        $anomalous = $this->doc($e, null, ['expires_at' => null, 'is_monitored' => false, 'status' => DocumentStatus::UPLOADED]);
        DocumentAnalysis::create(['document_id' => $anomalous->id, 'kind' => 'verification', 'outcome' => 'failed']);
        $clean = $this->doc($e, null, ['expires_at' => null, 'is_monitored' => false, 'status' => DocumentStatus::UPLOADED]);
        DocumentAnalysis::create(['document_id' => $clean->id, 'kind' => 'verification', 'outcome' => 'passed']);

        $ids = $this->ids();

        foreach ([$expiring, $pending, $rejected, $anomalous] as $included) {
            $this->assertContains($included->id, $ids);
        }
        $this->assertNotContains($clean->id, $ids);
    }

    public function test_only_the_latest_verification_decides_about_anomalies(): void
    {
        $doc = $this->doc($this->employee(), null, ['expires_at' => null, 'is_monitored' => false, 'status' => DocumentStatus::UPLOADED]);
        DocumentAnalysis::create(['document_id' => $doc->id, 'kind' => 'verification', 'outcome' => 'failed']);
        DocumentAnalysis::create(['document_id' => $doc->id, 'kind' => 'verification', 'outcome' => 'passed']);

        $this->assertNotContains($doc->id, $this->ids());
    }

    public function test_far_recently_reminded_and_na_documents_are_left_out(): void
    {
        $e = $this->employee();
        $far = $this->doc($e, null, ['expires_at' => Carbon::now()->addDays(200)->toDateString()]);
        $recent = $this->doc($e, null, ['last_sent_at' => Carbon::now()->subDays(2)]);
        $old = $this->doc($e, null, ['last_sent_at' => Carbon::now()->subDays(10)]);
        $na = $this->doc($e, null, ['status' => DocumentStatus::NA]);

        $ids = $this->ids();

        $this->assertSame([$old->id], array_values(array_intersect($ids, [$far->id, $recent->id, $old->id, $na->id])));
    }

    public function test_superseded_versions_are_left_out(): void
    {
        $e = $this->employee();
        $type = $this->type();
        $old = $this->doc($e, $type, ['emitted_at' => '2025-01-01', 'expires_at' => '2026-10-15']);
        $new = $this->doc($e, $type, ['emitted_at' => '2025-10-01', 'expires_at' => '2026-10-20']);

        $this->assertSame([$new->id], $this->ids());

        // Una versione più recente respinta non sostituisce la precedente.
        $new->update(['status' => DocumentStatus::REJECTED]);
        $this->assertContains($old->id, $this->ids());

        // Stessa emissione: prevale la scadenza più lontana.
        $tieA = $this->doc($e, $type = $this->type(), ['emitted_at' => '2025-05-05', 'expires_at' => '2026-10-12']);
        $tieB = $this->doc($e, $type, ['emitted_at' => '2025-05-05', 'expires_at' => '2026-10-30']);
        $ids = $this->ids();
        $this->assertNotContains($tieA->id, $ids);
        $this->assertContains($tieB->id, $ids);
    }

    public function test_replaced_and_renewed_documents_are_left_out(): void
    {
        $e = $this->employee();
        $replacement = $this->doc($e, null, ['expires_at' => null, 'is_monitored' => false, 'status' => DocumentStatus::APPROVED]);
        $replaced = $this->doc($e, null, ['replaced_by_id' => $replacement->id]);

        $renewer = $this->type();
        $renewable = $this->type(['renewed_by_id' => $renewer->id]);
        $oldByRenewal = $this->doc($e, $renewable, ['emitted_at' => '2025-01-01', 'expires_at' => '2026-10-15']);
        $this->assertContains($oldByRenewal->id, $this->ids());

        $this->doc($e, $renewer, ['emitted_at' => '2025-06-01', 'expires_at' => '2030-01-01', 'is_monitored' => false, 'status' => DocumentStatus::APPROVED]);
        $ids = $this->ids();

        $this->assertNotContains($replaced->id, $ids);
        $this->assertNotContains($oldByRenewal->id, $ids);
    }

    // --- Soglie e invio ---

    public function test_thresholds_default_and_per_type(): void
    {
        $e = $this->employee();
        $this->assertSame([30, 15, 7, 1, 0], $this->service->notifyThresholds($this->doc($e)));
        $this->assertSame([3, 2], $this->service->notifyThresholds($this->doc($e, $this->type(['notify_days_before' => [3, 2]]))));
    }

    public function test_sends_one_grouped_email_per_subject_on_threshold_days_only(): void
    {
        $this->template();
        $mario = $this->employee();
        $luisa = $this->employee('Luisa Verdi', 'luisa@example.com');
        $a = $this->doc($mario, null, ['name' => 'Patente']);
        $b = $this->doc($mario, null, ['name' => 'Polizza']);
        $off = $this->doc($mario, null, ['name' => 'Fuori soglia', 'expires_at' => Carbon::now()->addDays(20)->toDateString()]);
        $other = $this->doc($luisa, null, ['name' => 'Corso']);

        $stats = $this->service->sendReminders();

        $this->assertSame(['sent' => 3, 'skipped' => 0, 'failed' => 0, 'groups' => 2], $stats);
        $this->assertCount(2, $this->mail->sent);

        $toMario = collect($this->mail->sent)->firstWhere('to', 'mario@example.com');
        $this->assertSame('Sollecito scadenze documenti (2)', $toMario['subject']);
        $this->assertTrue(str_contains($toMario['body'], 'Gentile Mario Rossi'));
        $this->assertTrue(str_contains($toMario['body'], 'Patente') && str_contains($toMario['body'], 'Polizza'));
        $this->assertFalse(str_contains($toMario['body'], 'Fuori soglia'));

        $toLuisa = collect($this->mail->sent)->firstWhere('to', 'luisa@example.com');
        $this->assertSame('Documento in scadenza: Corso', $toLuisa['subject']);

        $fresh = $a->fresh();
        $this->assertSame(1, $fresh->reminders_count);
        $this->assertSame(DocumentStatus::PROVISIONAL, $fresh->status);
        $this->assertNotNull($fresh->last_sent_at);
        $reminder = DocumentReminder::where('document_id', $a->id)->first();
        $this->assertSame(7, (int) $reminder->days_before);
        $this->assertSame('sent', $reminder->status);
        $this->assertSame(DocumentStatus::UPLOADED, $off->fresh()->status);
    }

    public function test_the_same_threshold_is_not_sent_twice(): void
    {
        $this->template();
        $doc = $this->doc($this->employee());

        $this->service->sendReminders();
        $doc->update(['last_sent_at' => Carbon::now()->subDays(6)]);
        $stats = $this->service->sendReminders();

        $this->assertSame(0, $stats['sent']);
        $this->assertCount(1, $this->mail->sent);

        $forced = $this->service->sendReminders(onlyDueToday: false);
        $this->assertSame(1, $forced['sent']);
        $this->assertSame(1, DocumentReminder::where('document_id', $doc->id)->count(), 'una sola riga per documento e soglia');
    }

    public function test_missing_documents_are_reminded_again_after_the_configured_days(): void
    {
        $this->template();
        $doc = $this->doc($this->employee(), null, ['expires_at' => null, 'is_monitored' => false, 'status' => DocumentStatus::PENDING]);

        $this->assertSame(1, $this->service->sendReminders()['sent']);
        $this->assertSame(DocumentStatus::PENDING, $doc->fresh()->status, 'un documento assente resta da fornire');
        $this->assertTrue(str_contains($this->mail->sent[0]['body'], 'da fornire'));

        $this->assertSame(0, $this->service->sendReminders()['sent']);

        Carbon::setTestNow('2026-10-18 09:00:00');
        $this->assertSame(1, $this->service->sendReminders()['sent']);
    }

    public function test_without_a_template_nothing_is_sent(): void
    {
        $this->doc($this->employee());
        $this->template(['is_active' => false]);

        $this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 0, 'groups' => 0], $this->service->sendReminders());
        $this->assertSame([], $this->mail->sent);
    }

    public function test_subject_without_email_uses_the_fallback_address(): void
    {
        $this->template();
        $this->doc($this->employee('Senza Mail', null));

        $this->assertSame(['sent' => 0, 'skipped' => 1, 'failed' => 0, 'groups' => 1], $this->service->sendReminders());

        config(['unico-core.documents.reminders.fallback_email' => 'ufficio@example.com']);
        $this->assertSame(1, $this->service->sendReminders()['sent']);
        $this->assertSame('ufficio@example.com', $this->mail->sent[0]['to']);
    }

    public function test_failed_sends_are_recorded_and_can_be_retried(): void
    {
        $this->template();
        $doc = $this->doc($this->employee());
        $this->mail->fail = true;

        $stats = $this->service->sendReminders();

        $this->assertSame(1, $stats['failed']);
        $reminder = DocumentReminder::where('document_id', $doc->id)->first();
        $this->assertSame('failed', $reminder->status);
        $this->assertSame('SMTP non raggiungibile', $reminder->error_message);
        $this->assertSame(0, (int) $doc->fresh()->reminders_count);

        $this->mail->fail = false;
        $this->assertSame(1, $this->service->sendReminders()['sent']);
        $this->assertSame('sent', DocumentReminder::where('document_id', $doc->id)->first()->status);
    }

    public function test_recipient_names_are_escaped_in_the_email(): void
    {
        $this->template();
        $this->doc($this->employee('<b>Evil</b> & Co'), null, ['name' => '<script>x</script>']);

        $this->service->sendReminders();

        $body = $this->mail->sent[0]['body'];
        $this->assertFalse(str_contains($body, '<script>'));
        $this->assertTrue(str_contains($body, '&lt;b&gt;Evil&lt;/b&gt; &amp; Co'));
    }

    public function test_list_placeholder_is_replaced_in_place(): void
    {
        $template = $this->template(['body' => '<p>Ciao {agente_nome}</p>{elenco_documenti}<p>Grazie</p>']);
        $doc = $this->doc($this->employee(), null, ['name' => 'Patente']);

        $rendered = $this->service->renderGroupEmail($template, 'Mario', collect([$doc]));

        $this->assertTrue(str_contains($rendered['body'], '<li><strong>Patente</strong>'));
        $this->assertTrue(str_ends_with($rendered['body'], '<p>Grazie</p>'));
        $this->assertFalse(str_contains($rendered['body'], '{elenco_documenti}'));
    }

    // --- Stato e scadenziario ---

    public function test_expiry_status_severity_follows_the_most_urgent_document(): void
    {
        $e = $this->employee();
        $this->assertSame(ExpiryStatus::OK, $this->service->expiryStatus()->severity);

        $this->doc($e, null, ['expires_at' => '2026-11-01']);
        $this->assertSame(ExpiryStatus::REGULAR, $this->service->expiryStatus()->severity);

        $this->doc($e, null, ['expires_at' => '2026-10-15']);
        $this->assertSame(ExpiryStatus::WARNING, $this->service->expiryStatus()->severity);

        $this->doc($e, null, ['expires_at' => '2026-10-01', 'name' => 'Scaduto']);
        $status = $this->service->expiryStatus();
        $this->assertSame(ExpiryStatus::ALERT, $status->severity);
        $this->assertSame(3, $status->count);
        $this->assertTrue(str_contains($status->details, 'SCADUTO il 01/10/2026'));
    }

    public function test_schedule_sync_rewrites_the_table_and_expired_status_reads_it(): void
    {
        $e = $this->employee('Mario Rossi');
        $doc = $this->doc($e, $this->type(['name' => 'Patente']), ['name' => 'Patente Mario', 'expires_at' => '2026-08-01']);
        $sync = new ScheduleSynchronizer($this->service, new ConfigRecipientResolver);

        $this->assertSame(1, $sync->sync());
        $this->assertSame(1, $sync->sync(), 'una seconda esecuzione non duplica le righe');

        $row = DocumentSchedule::first();
        $this->assertSame($doc->id, $row->document_id);
        $this->assertSame('Mario Rossi', $row->entity_name);
        $this->assertSame('Patente', $row->document_type_name);
        $this->assertSame('employee|'.$e->id, $row->documentable_group_key);
        $this->assertSame(-70, (int) $row->days_until_expiry);

        $expired = $this->service->expiredStatus();
        $this->assertSame(1, $expired->count);
        $this->assertSame(ExpiryStatus::ALERT, $expired->severity);
        $this->assertTrue(str_contains($expired->details, 'scaduto il 01/08/2026 (70 giorni fa)'));
    }

    public function test_expired_status_is_ok_without_overdue_rows(): void
    {
        $this->assertSame(ExpiryStatus::OK, $this->service->expiredStatus()->severity);
    }

    // --- Destinatari ---

    public function test_recipient_resolver_uses_config_fallbacks_and_joins(): void
    {
        $resolver = new ConfigRecipientResolver;

        $viaPec = Employee::create(['name' => 'Anna', 'email' => null, 'pec' => 'anna@pec.it']);
        $this->assertSame(['name' => 'Anna', 'email' => 'anna@pec.it'], $resolver->resolve($viaPec));

        $this->assertSame('Dipendente', $resolver->typeLabel('employee'));
        $this->assertSame('Custom Thing', $resolver->typeLabel('custom_thing'));
        $this->assertSame('Sconosciuto', $resolver->typeLabel(null));

        config(['unico-core.documents.reminders.recipients.employee.name' => ['name+email']]);
        $this->assertSame('Anna anna@pec.it', $resolver->resolve($viaPec)['name'] === 'Anna' ? 'Anna anna@pec.it' : '');
    }

    public function test_document_without_subject_gets_a_placeholder_recipient(): void
    {
        config(['unico-core.documents.reminders.fallback_email' => 'ufficio@example.com']);
        $doc = new Document(['documentable_type' => 'employee', 'documentable_id' => 9999]);

        $this->assertSame(['name' => 'Destinatario non disponibile', 'email' => 'ufficio@example.com'], (new ConfigRecipientResolver)->resolveForDocument($doc));
        $this->assertSame('-', (new ConfigRecipientResolver)->entityName($doc));
    }
}
