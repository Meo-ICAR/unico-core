<?php

namespace Unico\Core\Mail\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Unico\Core\Mail\MailFetcher;
use Unico\Core\Models\MailAccount;

/** Scansiona una casella in coda. Due scansioni della stessa casella non si sovrappongono. */
class FetchMailAccount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public MailAccount $account, public ?int $limit = null)
    {
        $this->onQueue(config('unico-core.mail.queue', 'mail'));
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('mail-account:'.$this->account->getKey()))->dontRelease()];
    }

    public function handle(MailFetcher $fetcher): void
    {
        $fetcher->fetch($this->account, $this->limit);
    }
}
