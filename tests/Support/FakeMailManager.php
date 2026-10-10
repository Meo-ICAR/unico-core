<?php

namespace Unico\Core\Tests\Support;

/** Sostituisce `Mail::`: registra le email "inviate" senza trasporto. */
class FakeMailManager
{
    /** @var list<array{to: string, subject: string, body: string}> */
    public array $sent = [];

    public bool $fail = false;

    public function to(string $address): object
    {
        $manager = $this;

        return new class($manager, $address)
        {
            public function __construct(private FakeMailManager $manager, private string $address) {}

            public function send($mailable): void
            {
                if ($this->manager->fail) {
                    throw new \RuntimeException('SMTP non raggiungibile');
                }

                $this->manager->sent[] = ['to' => $this->address, 'subject' => $mailable->subjectLine, 'body' => $mailable->bodyContent];
            }
        };
    }
}
