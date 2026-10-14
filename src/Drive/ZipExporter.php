<?php

namespace Unico\Core\Drive;

use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use RuntimeException;
use ZipArchive;

/**
 * Scarica ricorsivamente il contenuto di una cartella Google Drive in un file
 * ZIP locale, preservando la struttura delle sottodirettrici. Le scorciatoie
 * (application/vnd.google-apps.shortcut) vengono risolte al file di
 * destinazione reale (shortcutDetails.targetId): nello ZIP compaiono con il
 * nome della scorciatoia così com'è nella cartella, ma con il contenuto vero
 * del file a cui puntano — altrimenti finirebbero nello ZIP come file da 1KB
 * inutilizzabili. I file nativi Google (Documenti/Fogli/Presentazioni, senza
 * un contenuto binario proprio) vengono esportati nel formato Office
 * equivalente.
 */
class ZipExporter
{
    /**
     * Evita di seguire all'infinito uno shortcut che punta a un altro
     * shortcut (o, in teoria, un ciclo), e di riprocessare due volte lo
     * stesso file se compare più volte nell'albero.
     */
    private const MAX_SHORTCUT_HOPS = 5;

    public function __construct(private Drive $service) {}

    /**
     * Costruisce lo ZIP e ne restituisce il percorso locale (file temporaneo,
     * a carico del chiamante ripulirlo dopo l'uso).
     */
    public function exportFolderToZip(string $rootFolderId, string $zipFileName = 'export.zip'): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'drive-zip-');
        rename($zipPath, $zipPath .= '-'.basename($zipFileName));

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Impossibile creare il file ZIP temporaneo: {$zipPath}");
        }

        $this->addFolderContents($zip, $rootFolderId, '');

        $zip->close();

        return $zipPath;
    }

    private function addFolderContents(ZipArchive $zip, string $folderId, string $relativePath): void
    {
        $pageToken = null;

        do {
            $results = $this->service->files->listFiles([
                'q' => "'{$folderId}' in parents and trashed = false",
                'fields' => 'nextPageToken, files(id, name, mimeType, shortcutDetails)',
                'pageSize' => 200,
                'pageToken' => $pageToken,
                'supportsAllDrives' => true,
                'includeItemsFromAllDrives' => true,
            ]);

            foreach ($results->getFiles() as $file) {
                $entryPath = ltrim($relativePath.'/'.$file->getName(), '/');

                if ($file->getMimeType() === 'application/vnd.google-apps.folder') {
                    $zip->addEmptyDir($entryPath);
                    $this->addFolderContents($zip, $file->getId(), $entryPath);

                    continue;
                }

                $this->addFileEntry($zip, $file, $entryPath);
            }

            $pageToken = $results->getNextPageToken();
        } while ($pageToken);
    }

    private function addFileEntry(ZipArchive $zip, DriveFile $file, string $entryPath): void
    {
        $resolved = $this->resolveShortcut($file);

        if ($resolved === null) {
            // Scorciatoia rotta (il file di destinazione non esiste più):
            // meglio ometterla dallo ZIP che inserire un file vuoto/errato.
            return;
        }

        [$targetId, $targetMimeType] = $resolved;

        try {
            if (str_starts_with($targetMimeType, 'application/vnd.google-apps.')) {
                [$content, $extraExtension] = $this->exportNativeGoogleFile($targetId, $targetMimeType);

                if ($extraExtension && ! str_ends_with(mb_strtolower($entryPath), '.'.$extraExtension)) {
                    $entryPath .= '.'.$extraExtension;
                }
            } else {
                $content = $this->service->files->get($targetId, ['alt' => 'media', 'supportsAllDrives' => true])->getBody()->getContents();
            }
        } catch (\Throwable $e) {
            // Un singolo file non scaricabile (permessi, file corrotto, ecc.)
            // non deve far fallire l'intero export: lo si segnala nello ZIP
            // stesso con un file di testo al posto suo.
            $zip->addFromString($entryPath.'.ERRORE.txt', "Impossibile scaricare questo file: {$e->getMessage()}");

            return;
        }

        $zip->addFromString($entryPath, $content);
    }

    /**
     * @return array{0: string, 1: string}|null [targetId, targetMimeType], o null se la scorciatoia è rotta.
     */
    private function resolveShortcut(DriveFile $file): ?array
    {
        $current = $file;
        $hops = 0;

        while ($current->getMimeType() === 'application/vnd.google-apps.shortcut') {
            if (++$hops > self::MAX_SHORTCUT_HOPS) {
                return null;
            }

            $targetId = $current->getShortcutDetails()?->getTargetId();

            if (! $targetId) {
                return null;
            }

            try {
                $current = $this->service->files->get($targetId, ['fields' => 'id, name, mimeType, shortcutDetails', 'supportsAllDrives' => true]);
            } catch (\Throwable) {
                return null;
            }
        }

        return [$current->getId(), $current->getMimeType()];
    }

    /**
     * @return array{0: string, 1: ?string} [contenuto binario, estensione da aggiungere al nome file]
     */
    private function exportNativeGoogleFile(string $fileId, string $mimeType): array
    {
        [$exportMimeType, $extension] = match ($mimeType) {
            'application/vnd.google-apps.document' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx'],
            'application/vnd.google-apps.spreadsheet' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'],
            'application/vnd.google-apps.presentation' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'pptx'],
            default => ['application/pdf', 'pdf'],
        };

        $content = $this->service->files->export($fileId, $exportMimeType, ['alt' => 'media'])->getBody()->getContents();

        return [$content, $extension];
    }
}
