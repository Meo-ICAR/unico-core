<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Mail\BounceParser;

class BounceParserTest extends TestCase
{
    private function parser(): BounceParser
    {
        return new BounceParser;
    }

    public function test_parses_standard_dsn_hard_bounce(): void
    {
        $raw = <<<'EML'
        From: Mail Delivery Subsystem <mailer-daemon@example.com>
        Subject: Delivery Status Notification (Failure)
        Content-Type: multipart/report; report-type=delivery-status; boundary="b1"

        --b1
        Content-Type: message/delivery-status

        Reporting-MTA: dns; mx.example.com
        Final-Recipient: rfc822; mario.rossi@azienda-inesistente.it
        Action: failed
        Status: 5.1.1
        Diagnostic-Code: smtp; 550 5.1.1 <mario.rossi@azienda-inesistente.it> user unknown
        --b1--
        EML;

        $result = $this->parser()->parse($raw);

        $this->assertNotNull($result);
        $this->assertSame('mario.rossi@azienda-inesistente.it', $result['failed_email']);
        $this->assertSame('5.1.1', $result['status_code']);
        $this->assertSame('hard', $result['bounce_type']);
        $this->assertStringContainsString('550 5.1.1', $result['diagnostic_code']);
    }

    public function test_parses_soft_bounce_from_status(): void
    {
        $raw = "Final-Recipient: rfc822; test@example.com\nAction: failed\nStatus: 4.2.2\n";

        $result = $this->parser()->parse($raw);

        $this->assertSame('test@example.com', $result['failed_email']);
        $this->assertSame('4.2.2', $result['status_code']);
        $this->assertSame('soft', $result['bounce_type']);
    }

    public function test_falls_back_to_first_email_in_body_when_no_dsn(): void
    {
        $raw = 'Your message to destinatario@example.org could not be delivered.';

        $result = $this->parser()->parse($raw);

        $this->assertSame('destinatario@example.org', $result['failed_email']);
        $this->assertNull($result['status_code']);
        $this->assertSame('unknown', $result['bounce_type']);
    }

    public function test_returns_null_when_no_email_present(): void
    {
        $this->assertNull($this->parser()->parse('Nessun indirizzo qui, solo testo.'));
    }
}
