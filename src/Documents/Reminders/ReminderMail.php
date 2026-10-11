<?php

namespace Unico\Core\Documents\Reminders;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Email di sollecito: oggetto e corpo HTML arrivano già composti dal template. Allegati: file Spatie (`Media`) o percorsi.
 * Un file che non si trova non blocca l'invio: finisce nel log (solo l'id).
 */
class ReminderMail extends Mailable
{
    /** @param list<Media|string> $fileAttachments */
    public function __construct(public string $subjectLine, public string $bodyContent, public array $fileAttachments = []) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->bodyContent);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $attachments = [];

        foreach ($this->fileAttachments as $file) {
            if ($file instanceof Media) {
                $path = $file->getPath();

                if (is_file($path)) {
                    $attachments[] = Attachment::fromPath($path)->as($file->file_name)->withMime($file->mime_type);
                } elseif ($file->getDisk()->exists($file->getPathRelativeToRoot())) {
                    $attachments[] = Attachment::fromStorageDisk($file->disk, $file->getPathRelativeToRoot())->as($file->file_name)->withMime($file->mime_type);
                } else {
                    Log::warning("Allegato del sollecito non trovato (media {$file->id}).");
                }
            } elseif (is_string($file) && (is_file($file) || filter_var($file, FILTER_VALIDATE_URL))) {
                $attachments[] = Attachment::fromPath($file);
            }
        }

        return $attachments;
    }
}
