<?php

namespace Unico\Core\Drive;

use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

/**
 * Cerca e crea cartelle su Drive. Tutte le chiamate passano `supportsAllDrives` e
 * `includeItemsFromAllDrives`: senza, sui Drive condivisi la ricerca non trova nulla
 * e ogni esecuzione crea una cartella duplicata.
 */
class DriveFolders
{
    public const MIME = 'application/vnd.google-apps.folder';

    public function __construct(private Drive $service) {}

    /** ID della cartella `$name` dentro `$parentId`, o null se non esiste. */
    public function find(string $parentId, string $name): ?string
    {
        $results = $this->service->files->listFiles([
            'q' => "'".self::escape($parentId)."' in parents and trashed = false and mimeType = '".self::MIME."' and name = '".self::escape($name)."'",
            'fields' => 'files(id, name)',
            'pageSize' => 1,
            'supportsAllDrives' => true,
            'includeItemsFromAllDrives' => true,
        ]);

        foreach ($results->getFiles() as $existing) {
            return $existing->getId();
        }

        return null;
    }

    /**
     * Garantisce il percorso `$path` sotto `$parentId` e restituisce l'ID dell'ultima cartella.
     * Il percorso è una stringa `A/B/C` o un array di nomi; le cartelle esistenti si riusano.
     *
     * @param  string|array<int, string>  $path
     */
    public function ensure(string $parentId, string|array $path): string
    {
        $names = is_array($path) ? $path : explode('/', $path);

        foreach (array_filter(array_map('trim', $names), fn ($n) => $n !== '') as $name) {
            $parentId = $this->find($parentId, $name) ?? $this->create($parentId, $name);
        }

        return $parentId;
    }

    private function create(string $parentId, string $name): string
    {
        return $this->service->files->create(new DriveFile([
            'name' => $name,
            'mimeType' => self::MIME,
            'parents' => [$parentId],
        ]), ['fields' => 'id', 'supportsAllDrives' => true])->id;
    }

    /** Protegge apici e barre rovesce nelle query di Drive. */
    public static function escape(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
