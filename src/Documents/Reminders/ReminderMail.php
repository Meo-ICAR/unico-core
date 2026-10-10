<?php

namespace Unico\Core\Documents\Reminders;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Email di sollecito: oggetto e corpo HTML arrivano già composti dal template. */
class ReminderMail extends Mailable
{
    public function __construct(public string $subjectLine, public string $bodyContent) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->bodyContent);
    }
}
