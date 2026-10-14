<?php

namespace Unico\Core\Drive;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use Unico\Core\Models\IncomingEmail;

/**
 * Archivia un'email su Drive in `<cartella company>/<sottocartella>/<mittente>/`: il testo come
 * file .txt e tutti gli allegati. Le email di una conversazione finiscono nella stessa cartella,
 * anche se la risposta arriva da un indirizzo interno. Quali email archiviare lo decide l'app.
 */
class EmailDriveArchiver
{
    public function __construct(private DriveFolders $folders, private DriveUploader $uploader) {}

    /**
     * Se Drive non è utilizzabile (quota, permessi, company senza cartella) i file vanno nel disco
     * dei documenti, in `<sottocartella>/<company>/<mittente>/`, per il caricamento manuale.
     *
     * @param  string|null  $subfolder  Sottocartella della company; di default `unico-core.drive.email_folder`
     * @return array{folder_id: ?string, local_path: ?string}
     */
    public function archive(IncomingEmail $email, ?string $subfolder = null): array
    {
        $subfolder ??= config('unico-core.drive.email_folder', 'RECLAMI');

        try {
            return ['folder_id' => $this->archiveOnDrive($email, $subfolder), 'local_path' => null];
        } catch (Throwable $e) {
            Log::warning('Archiviazione su Drive fallita, salvo in locale', [
                'incoming_email_id' => $email->id,
                'company_id' => $email->company_id,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return ['folder_id' => $email->drive_folder_id, 'local_path' => $this->archiveLocally($email, $subfolder)];
        }
    }

    private function archiveOnDrive(IncomingEmail $email, string $subfolder): string
    {
        $companyFolderId = $email->company?->drive_folder_id;

        if (blank($companyFolderId)) {
            throw new RuntimeException('La company non ha una cartella Google Drive collegata (drive_folder_id).');
        }

        $folderId = $email->drive_folder_id
            ?? $this->threadFolderId($email)
            ?? $this->folders->ensure($companyFolderId, [$subfolder, $this->senderFolderName($this->threadReference($email))]);

        // Salvato subito: se un upload fallisce, i tentativi successivi e le altre email
        // della conversazione riusano la stessa cartella.
        $email->forceFill(['drive_folder_id' => $folderId])->saveQuietly();

        $this->uploadIfMissing($folderId, $this->bodyFileName($email), $this->renderBody($email));

        foreach ($this->attachments($email) as $media) {
            if (is_file($media->getPath()) && ! $this->uploader->exists($folderId, $media->file_name)) {
                $this->uploader->uploadFile($media->getPath(), $folderId, $media->file_name);
            }
        }

        return $folderId;
    }

    private function archiveLocally(IncomingEmail $email, string $subfolder): string
    {
        $disk = $this->localDisk();
        $directory = Str::slug($subfolder).'/'.$email->company_id.'/'.$this->senderFolderName($this->threadReference($email));

        $disk->put($directory.'/'.$this->bodyFileName($email), $this->renderBody($email));

        foreach ($this->attachments($email) as $media) {
            if (is_file($media->getPath())) {
                $disk->put($directory.'/'.$media->file_name, file_get_contents($media->getPath()));
            }
        }

        return $disk->path($directory);
    }

    /** @return iterable<int, object> Allegati con `getPath()` e `file_name`; punto di sostituzione per i test */
    protected function attachments(IncomingEmail $email): iterable
    {
        return $email->getMedia(IncomingEmail::ATTACHMENTS);
    }

    protected function localDisk(): object
    {
        return Storage::disk(config('unico-core.documents_disk'));
    }

    /** L'email più vecchia della conversazione: dà il nome del mittente anche per le risposte interne. */
    private function threadReference(IncomingEmail $email): IncomingEmail
    {
        return IncomingEmail::withTrashed()
            ->where('company_id', $email->company_id)
            ->where('thread_id', $email->thread_id)
            ->orderBy('received_at')
            ->first() ?? $email;
    }

    private function threadFolderId(IncomingEmail $email): ?string
    {
        return IncomingEmail::withTrashed()
            ->where('company_id', $email->company_id)
            ->where('thread_id', $email->thread_id)
            ->whereNotNull('drive_folder_id')
            ->orderBy('received_at')
            ->value('drive_folder_id');
    }

    private function senderFolderName(IncomingEmail $email): string
    {
        $name = trim((string) $email->from_name);

        if ($name === '' || str_contains($name, '@')) {
            $name = (string) $email->from_email;
        }

        return $this->sanitize($name) ?: 'Sconosciuto';
    }

    private function bodyFileName(IncomingEmail $email): string
    {
        $subject = Str::limit($this->sanitize((string) $email->subject), 80, '');

        return $email->received_at->format('Y-m-d_Hi').' - '.($subject ?: 'email').'.txt';
    }

    private function renderBody(IncomingEmail $email): string
    {
        $body = $email->body_text ?: html_entity_decode(strip_tags((string) $email->body_html));

        return implode("\n", [
            'Da: '.$email->from_name.' <'.$email->from_email.'>',
            'A: '.collect($email->to)->pluck('email')->filter()->implode(', '),
            'Data: '.$email->received_at->format('d/m/Y H:i'),
            'Oggetto: '.$email->subject,
            '',
            $body,
        ]);
    }

    private function sanitize(string $value): string
    {
        $clean = preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $clean) ?? '');
    }

    private function uploadIfMissing(string $folderId, string $name, string $contents): void
    {
        if (! $this->uploader->exists($folderId, $name)) {
            $this->uploader->uploadContents($contents, $name, $folderId);
        }
    }
}
