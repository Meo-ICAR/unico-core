<?php

namespace Unico\Core\Drive;

use RuntimeException;
use Throwable;
use Unico\Core\Models\Company;
use Unico\Core\Models\Document;

/**
 * Carica nella cartella Drive della company i documenti che hanno un file locale e non sono
 * ancora sincronizzati, e allinea `document_url`, `app_id`, `app_drive_id`, `source_app`
 * e `sync_status`. Un documento che non si carica diventa `failed` e non ferma gli altri.
 */
class DocumentDriveSync
{
    public function __construct(private DriveUploader $uploader) {}

    /** @return array{synced: int, failed: int, skipped: int, errors: array<string, string>} */
    public function syncCompanyDocuments(Company $company): array
    {
        $result = ['synced' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];

        if (! $company->usesGoogleDriveStorage()) {
            return $result;
        }

        if (blank($company->drive_folder_id)) {
            throw new RuntimeException("La company \"{$company->name}\" non ha una cartella Google Drive collegata (drive_folder_id).");
        }

        foreach ($company->documents()->get() as $document) {
            $media = $this->mediaFor($document);

            if (! $media || ($document->sync_status === 'synced' && filled($document->document_url))) {
                $result['skipped']++;

                continue;
            }

            $label = (string) ($document->name ?? $document->id);

            if (! is_file($media->getPath())) {
                $document->update(['sync_status' => 'failed']);
                $result['failed']++;
                $result['errors'][$label] = 'File locale mancante su disco: '.$media->getPath();

                continue;
            }

            try {
                $file = $this->uploader->uploadFile($media->getPath(), $company->drive_folder_id, $media->file_name);

                $document->update([
                    'document_url' => $file->getWebViewLink(),
                    'app_id' => $file->getId(),
                    'app_drive_id' => $company->drive_folder_id,
                    'source_app' => 'google_drive',
                    'sync_status' => 'synced',
                ]);

                $result['synced']++;
            } catch (Throwable $e) {
                $document->update(['sync_status' => 'failed']);
                $result['failed']++;
                $result['errors'][$label] = $e->getMessage();
            }
        }

        return $result;
    }

    /** File locale del documento (il firmato, se c'è); punto di sostituzione per i test. */
    protected function mediaFor(Document $document): ?object
    {
        return $document->currentMedia();
    }
}
