<?php

namespace Unico\Core\Tests\Support;

use Unico\Core\Mail\Contracts\ImapConnector;
use Unico\Core\Models\MailAccount;
use Illuminate\Support\Collection;

/**
 * Test double minimale della catena webklex/php-imap usata da FetchMailAccountJob:
 * factory -> client -> folder -> query -> collection di messaggi.
 */
class FakeImapConnectionFactory implements ImapConnector
{
    /** @param array<int, FakeImapMessage> $messages */
    public function __construct(public array $messages = []) {}

    public function make(MailAccount $account): object
    {
        return new FakeImapClient($this->messages);
    }
}

class FakeImapClient
{
    /** @param array<int, FakeImapMessage> $messages */
    public function __construct(private array $messages) {}

    public function connect(): void {}

    public function getFolder(string $name): FakeImapFolder
    {
        return new FakeImapFolder($this->messages);
    }
}

class FakeImapFolder
{
    /** @param array<int, FakeImapMessage> $messages */
    public function __construct(private array $messages) {}

    public function messages(): FakeImapQuery
    {
        return new FakeImapQuery($this->messages);
    }
}

class FakeImapQuery
{
    /** @param array<int, FakeImapMessage> $messages */
    public function __construct(private array $messages) {}

    public function unseen(): self
    {
        return $this;
    }

    public function since(mixed $date): self
    {
        return $this;
    }

    public function get(): Collection
    {
        return collect($this->messages);
    }
}

class FakeImapAttribute
{
    public function __construct(private array $items = []) {}

    public function toArray(): array
    {
        return $this->items;
    }

    public function __toString(): string
    {
        return implode(' ', $this->items);
    }
}

class FakeImapMessage
{
    public bool $seen = false;

    /**
     * @param  array<int, array{name:string,content:string}>  $attachments
     */
    public function __construct(
        public string $fromMail = 'mittente@example.com',
        public string $fromName = 'Mittente Test',
        public string $subject = 'Oggetto di test',
        public string $textBody = 'Corpo del messaggio.',
        public string $messageId = 'msg-1@example.com',
        public array $attachments = [],
        public string $inReplyTo = '',
        public string $references = '',
    ) {}

    public function getFrom(): array
    {
        return [(object) ['mail' => $this->fromMail, 'personal' => $this->fromName]];
    }

    public function getTo(): array
    {
        return [(object) ['mail' => 'dpo@azienda.it', 'personal' => 'DPO']];
    }

    public function getCc(): array
    {
        return [];
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getTextBody(): string
    {
        return $this->textBody;
    }

    public function getHTMLBody(): string
    {
        return '';
    }

    public function getMessageId(): FakeImapAttribute
    {
        return new FakeImapAttribute([$this->messageId]);
    }

    public function getInReplyTo(): FakeImapAttribute
    {
        return new FakeImapAttribute($this->inReplyTo === '' ? [] : [$this->inReplyTo]);
    }

    public function getReferences(): FakeImapAttribute
    {
        return new FakeImapAttribute($this->references === '' ? [] : [$this->references]);
    }

    public function getDate(): string
    {
        return '2026-09-01 10:00:00';
    }

    public function hasAttachments(): bool
    {
        return $this->attachments !== [];
    }

    public function getAttachments(): array
    {
        return array_map(fn ($a) => new FakeImapItemAttachment($a['name'], $a['content']), $this->attachments);
    }

    public function setFlag(string $flag): void
    {
        if ($flag === 'Seen') {
            $this->seen = true;
        }
    }
}

class FakeImapItemAttachment
{
    public function __construct(private string $name, private string $content) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getContent(): string
    {
        return $this->content;
    }
}
