<?php

namespace Unico\Core\Drive;

use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use RuntimeException;

class DriveUploader
{
    public function __construct(private Drive $service) {}

    /**
     * Carica un file locale. Con una cartella di «Il mio Drive» non condivisa e senza delega
     * di dominio fallisce con `storageQuotaExceeded` (vedi GoogleDriveFactory).
     */
    public function uploadFile(string $path, ?string $folderId = null, ?string $name = null): DriveFile
    {
        if (! is_file($path)) {
            throw new RuntimeException("Il file locale non esiste: {$path}");
        }

        return $this->upload(file_get_contents($path), $name ?: basename($path), $folderId, mime_content_type($path) ?: 'application/octet-stream');
    }

    public function uploadContents(string $contents, string $name, ?string $folderId = null, string $mime = 'text/plain'): DriveFile
    {
        return $this->upload($contents, $name, $folderId, $mime);
    }

    /** Il file `$name` esiste già nella cartella (non nel cestino). */
    public function exists(string $folderId, string $name): bool
    {
        $results = $this->service->files->listFiles([
            'q' => "'".DriveFolders::escape($folderId)."' in parents and trashed = false and name = '".DriveFolders::escape($name)."'",
            'fields' => 'files(id)',
            'pageSize' => 1,
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
        ]);

        return count($results->getFiles()) > 0;
    }

    /** Contenuto binario di un file (sola lettura: non richiede quota). */
    public function download(string $fileId): string
    {
        return $this->service->files->get($fileId, ['alt' => 'media', 'supportsAllDrives' => true])->getBody()->getContents();
    }

    private function upload(string $contents, string $name, ?string $folderId, string $mime): DriveFile
    {
        $metadata = ['name' => $name];

        if ($folderId) {
            $metadata['parents'] = [$folderId];
        }

        return $this->service->files->create(new DriveFile($metadata), [
            'data' => $contents,
            'mimeType' => $mime,
            'uploadType' => 'multipart',
            'fields' => 'id, name, webViewLink',
            'supportsAllDrives' => true,
        ]);
    }
}
