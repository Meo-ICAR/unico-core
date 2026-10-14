<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Mail\RuleBasedClassifier;
use Unico\Core\Models\IncomingEmail;
use Unico\Core\Tests\Support\CoreDatabase;

class RuleBasedClassifierTest extends TestCase
{
    protected function setUp(): void
    {
        CoreDatabase::boot();
        config(['unico-core.mail.rules' => []]);
    }

    private function classify(array $attributes): ?string
    {
        return (new RuleBasedClassifier)->classify(new IncomingEmail($attributes));
    }

    public function test_recognises_bounces(): void
    {
        $this->assertSame('bounce', $this->classify(['subject' => 'Ciao', 'body_text' => 'x', 'from_email' => 'MAILER-DAEMON@mx.example.com']));
        $this->assertSame('bounce', $this->classify(['subject' => 'Mancato recapito', 'body_text' => 'x', 'from_email' => 'a@example.com']));
    }

    public function test_postmaster_welcome_is_not_a_bounce(): void
    {
        $this->assertSame('provider_notification', $this->classify([
            'subject' => 'Benvenuto', 'body_text' => 'La casella di posta è pronta.', 'from_email' => 'postmaster@cliente.it',
        ]));
    }

    public function test_recognises_provider_notifications(): void
    {
        $this->assertSame('provider_notification', $this->classify(['subject' => 'Avviso', 'body_text' => 'x', 'from_email' => 'info@aruba.it']));
        $this->assertSame('provider_notification', $this->classify(['subject' => 'Sicurezza', 'body_text' => 'x', 'from_email' => 'no-reply@accounts.google.com']));
    }

    public function test_recognises_meeting_invites_and_spam(): void
    {
        $this->assertSame('meeting_invite', $this->classify(['subject' => 'Invitation: Riunione', 'body_text' => 'x', 'from_email' => 'a@example.com']));
        $this->assertSame('spam', $this->classify(['subject' => 'Free crypto now', 'body_text' => 'x', 'from_email' => 'a@example.com']));
    }

    public function test_ordinary_mail_is_other_and_empty_mail_too(): void
    {
        $this->assertSame('other', $this->classify(['subject' => 'Preventivo', 'body_text' => 'Buongiorno', 'from_email' => 'a@example.com']));
        $this->assertSame('other', $this->classify(['subject' => '', 'body_text' => '', 'from_email' => 'a@example.com']));
    }

    public function test_app_rules_run_before_spam_and_in_order(): void
    {
        config(['unico-core.mail.rules' => [
            'complaint' => ['/\breclamo\b/iu'],
            'privacy' => ['/\bgdpr\b/i', '/\breclamo\b/iu'],
        ]]);

        $this->assertSame('complaint', $this->classify(['subject' => 'Reclamo GDPR', 'body_text' => 'x', 'from_email' => 'a@example.com']));
        $this->assertSame('privacy', $this->classify(['subject' => 'GDPR', 'body_text' => 'x', 'from_email' => 'a@example.com']));
    }

    public function test_bounce_wins_over_app_rules(): void
    {
        config(['unico-core.mail.rules' => ['complaint' => ['/\breclamo\b/iu']]]);

        $this->assertSame('bounce', $this->classify(['subject' => 'Delivery Status Notification: reclamo', 'body_text' => 'x', 'from_email' => 'a@example.com']));
    }
}
