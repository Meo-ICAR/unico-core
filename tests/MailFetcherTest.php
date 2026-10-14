<?php

namespace Unico\Core\Tests;

use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Unico\Core\Events\IncomingEmailReceived;
use Unico\Core\Mail\MailFetcher;
use Unico\Core\Mail\RuleBasedClassifier;
use Unico\Core\Models\Company;
use Unico\Core\Models\IncomingEmail;
use Unico\Core\Models\MailAccount;
use Unico\Core\Tests\Support\CoreDatabase;
use Unico\Core\Tests\Support\FakeImapConnectionFactory;
use Unico\Core\Tests\Support\FakeImapMessage;

require_once __DIR__.'/Support/FakeImap.php';

class MailFetcherTest extends TestCase
{
    private Dispatcher $events;

    /** @var array<int, IncomingEmail> */
    private array $received = [];

    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('incoming_emails', 'mail_accounts', 'companies');
        $this->received = [];
        $this->events = new Dispatcher;
        $this->events->listen(IncomingEmailReceived::class, fn ($e) => $this->received[] = $e->email);
    }

    private function account(array $attributes = []): MailAccount
    {
        $company = Company::create(['name' => 'Azienda '.uniqid()]);

        return MailAccount::create($attributes + [
            'company_id' => $company->id,
            'name' => 'Casella',
            'email_address' => uniqid().'@example.com',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_username' => 'user',
            'smtp_host' => null,
        ]);
    }

    /** @param array<int, FakeImapMessage> $messages */
    private function fetcher(array $messages, ?Dispatcher $events = null): MailFetcher
    {
        return new MailFetcher(new FakeImapConnectionFactory($messages), new RuleBasedClassifier, $events ?? $this->events);
    }

    public function test_imports_new_emails_and_announces_each_one(): void
    {
        $account = $this->account();
        $messages = [
            new FakeImapMessage(messageId: 'a@example.com', subject: 'Prima'),
            new FakeImapMessage(messageId: 'b@example.com', subject: 'Seconda'),
        ];

        $imported = $this->fetcher($messages)->fetch($account);

        $this->assertCount(2, $imported);
        $this->assertSame(2, IncomingEmail::count());
        $this->assertCount(2, $this->received);
        $this->assertTrue($messages[0]->seen);
        $this->assertSame($account->company_id, IncomingEmail::first()->company_id);
        $this->assertTrue($account->fresh()->last_synced_at !== null);
    }

    public function test_never_imports_the_same_message_twice(): void
    {
        $account = $this->account();
        $message = fn () => new FakeImapMessage(messageId: 'dup@example.com');

        $this->fetcher([$message()])->fetch($account);
        $second = $this->fetcher([$message()])->fetch($account);

        $this->assertCount(0, $second);
        $this->assertSame(1, IncomingEmail::count());
    }

    public function test_does_not_reimport_a_deleted_email(): void
    {
        $account = $this->account();
        $this->fetcher([new FakeImapMessage(messageId: 'gone@example.com')])->fetch($account);
        IncomingEmail::first()->delete();

        $second = $this->fetcher([new FakeImapMessage(messageId: 'gone@example.com')])->fetch($account);

        $this->assertCount(0, $second);
    }

    public function test_same_message_id_in_another_company_is_imported(): void
    {
        $this->fetcher([new FakeImapMessage(messageId: 'same@example.com')])->fetch($this->account());
        $other = $this->fetcher([new FakeImapMessage(messageId: 'same@example.com')])->fetch($this->account());

        $this->assertCount(1, $other);
        $this->assertSame(2, IncomingEmail::count());
    }

    public function test_respects_the_limit(): void
    {
        $messages = array_map(fn ($i) => new FakeImapMessage(messageId: "m$i@example.com"), range(1, 5));

        $imported = $this->fetcher($messages)->fetch($this->account(), 3);

        $this->assertCount(3, $imported);
        $this->assertFalse($messages[4]->seen);
    }

    public function test_skips_messages_without_sender(): void
    {
        $imported = $this->fetcher([new FakeImapMessage(fromMail: '', messageId: 'nofrom@example.com')])->fetch($this->account());

        $this->assertCount(0, $imported);
    }

    public function test_builds_the_thread_from_references_and_reply_to(): void
    {
        $account = $this->account();
        $this->fetcher([
            new FakeImapMessage(messageId: 'root@example.com'),
            new FakeImapMessage(messageId: 'reply@example.com', inReplyTo: '<root@example.com>', references: '<root@example.com>'),
            new FakeImapMessage(messageId: 'reply-only@example.com', inReplyTo: '<root@example.com>'),
        ])->fetch($account);

        $reply = IncomingEmail::where('message_id', 'reply@example.com')->first();
        $this->assertSame('root@example.com', $reply->thread_id);
        $this->assertSame('root@example.com', IncomingEmail::where('message_id', 'reply-only@example.com')->first()->thread_id);
        $this->assertSame('root@example.com', IncomingEmail::where('message_id', 'root@example.com')->first()->thread_id);
        $this->assertSame(['reply-only@example.com', 'root@example.com'], $reply->threadMessages()->pluck('message_id')->sort()->values()->all());
    }

    public function test_truncates_long_subject_and_names_empty_subject(): void
    {
        $account = $this->account();
        $this->fetcher([
            new FakeImapMessage(messageId: 'long@example.com', subject: str_repeat('x', 400)),
            new FakeImapMessage(messageId: 'empty@example.com', subject: ''),
        ])->fetch($account);

        $this->assertSame(255, mb_strlen(IncomingEmail::where('message_id', 'long@example.com')->first()->subject));
        $this->assertSame('(Senza Oggetto)', IncomingEmail::where('message_id', 'empty@example.com')->first()->subject);
    }

    public function test_decodes_mime_encoded_subject(): void
    {
        $this->fetcher([new FakeImapMessage(messageId: 'mime@example.com', subject: '=?UTF-8?Q?Perch=C3=A9_s=C3=AC?=')])->fetch($this->account());

        $this->assertSame('Perché sì', IncomingEmail::first()->subject);
    }

    public function test_classifies_each_email(): void
    {
        $this->fetcher([
            new FakeImapMessage(messageId: 'bounce@example.com', fromMail: 'mailer-daemon@example.com', subject: 'Mail delivery failed'),
            new FakeImapMessage(messageId: 'plain@example.com', subject: 'Preventivo'),
        ])->fetch($this->account());

        $this->assertSame('bounce', IncomingEmail::where('message_id', 'bounce@example.com')->first()->classification);
        $this->assertSame('other', IncomingEmail::where('message_id', 'plain@example.com')->first()->classification);
    }

    public function test_a_failing_listener_does_not_stop_the_import(): void
    {
        $events = new Dispatcher;
        $events->listen(IncomingEmailReceived::class, fn () => throw new \RuntimeException('listener rotto'));

        $imported = $this->fetcher([
            new FakeImapMessage(messageId: 'x1@example.com'),
            new FakeImapMessage(messageId: 'x2@example.com'),
        ], $events)->fetch($this->account());

        $this->assertCount(2, $imported);
        $this->assertSame(2, IncomingEmail::count());
    }

    public function test_keeps_the_email_when_an_attachment_cannot_be_stored(): void
    {
        $this->fetcher([new FakeImapMessage(
            messageId: 'att@example.com',
            attachments: [['name' => 'documento.pdf', 'content' => 'contenuto']],
        )])->fetch($this->account());

        $this->assertSame(1, IncomingEmail::count());
    }

    public function test_attachment_names_are_made_safe(): void
    {
        $method = new ReflectionMethod(MailFetcher::class, 'safeAttachmentName');
        $fetcher = $this->fetcher([]);

        $this->assertSame('..-..-etc-passwd', $method->invoke($fetcher, '../../etc/passwd'));
        $this->assertSame('allegato', $method->invoke($fetcher, '   '));
        $this->assertSame('Perché.pdf', $method->invoke($fetcher, '=?UTF-8?Q?Perch=C3=A9.pdf?='));
        $this->assertSame('pdf', pathinfo($method->invoke($fetcher, str_repeat('a', 300).'.pdf'), PATHINFO_EXTENSION));
        $this->assertSame(124, mb_strlen($method->invoke($fetcher, str_repeat('a', 300).'.pdf')));
    }

    public function test_second_scan_after_a_sync_still_imports_new_mail(): void
    {
        $account = $this->account();
        $this->fetcher([new FakeImapMessage(messageId: 'one@example.com')])->fetch($account);

        $second = $this->fetcher([new FakeImapMessage(messageId: 'one@example.com'), new FakeImapMessage(messageId: 'two@example.com')])->fetch($account->fresh());

        $this->assertSame(['two@example.com'], $second->pluck('message_id')->all());
    }
}
