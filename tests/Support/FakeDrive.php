<?php

namespace Unico\Core\Tests\Support;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\FileList;
use RuntimeException;

/**
 * Drive condiviso simulato in memoria. Riproduce il comportamento che ha causato i duplicati:
 * senza `supportsAllDrives` e `includeItemsFromAllDrives` la ricerca non trova nulla.
 */
class FakeDriveFiles
{
    /** @var array<string, array{id: string, name: string, mimeType: string, parents: array<int, string>, data: ?string, shortcut: ?string}> */
    public array $nodes = [];

    /** @var array<int, array{0: string, 1: array}> */
    public array $calls = [];

    public bool $failUploads = false;

    private int $sequence = 0;

    public function addFolder(string $name, string $parent = 'root'): string
    {
        return $this->insert($name, 'application/vnd.google-apps.folder', $parent, null);
    }

    public function addFile(string $name, string $parent, string $data, string $mime = 'text/plain'): string
    {
        return $this->insert($name, $mime, $parent, $data);
    }

    public function addShortcut(string $name, string $parent, string $targetId): string
    {
        $id = $this->insert($name, 'application/vnd.google-apps.shortcut', $parent, null);
        $this->nodes[$id]['shortcut'] = $targetId;

        return $id;
    }

    private function insert(string $name, string $mime, string $parent, ?string $data): string
    {
        $id = 'id'.(++$this->sequence);
        $this->nodes[$id] = ['id' => $id, 'name' => $name, 'mimeType' => $mime, 'parents' => [$parent], 'data' => $data, 'shortcut' => null];

        return $id;
    }

    public function listFiles(array $params = []): FileList
    {
        $this->calls[] = ['listFiles', $params];

        if (empty($params['supportsAllDrives']) || empty($params['includeItemsFromAllDrives'])) {
            return new FileList(['files' => []]);
        }

        preg_match("/'((?:[^'\\\\]|\\\\.)*)' in parents/", $params['q'], $parent);
        preg_match("/ name = '((?:[^'\\\\]|\\\\.)*)'/", $params['q'], $name);
        preg_match("/mimeType = '([^']*)'/", $params['q'], $mime);

        $unescape = fn (?string $v) => $v === null ? null : stripcslashes($v);
        $files = [];
        foreach ($this->nodes as $node) {
            if (in_array($unescape($parent[1] ?? null), $node['parents'], true)
                && (! isset($name[1]) || $node['name'] === $unescape($name[1]))
                && (! isset($mime[1]) || $node['mimeType'] === $mime[1])) {
                $files[] = $this->toArray($node);
            }
        }

        return new FileList(['files' => $files]);
    }

    public function create(DriveFile $file, array $params = []): DriveFile
    {
        $this->calls[] = ['create', $params];

        if (empty($params['supportsAllDrives'])) {
            throw new RuntimeException('Drive condiviso: manca supportsAllDrives');
        }
        if ($this->failUploads && isset($params['data'])) {
            throw new RuntimeException('storageQuotaExceeded');
        }

        $id = $this->insert($file->getName(), $file->getMimeType() ?: ($params['mimeType'] ?? 'application/octet-stream'), $file->getParents()[0] ?? 'root', $params['data'] ?? null);

        return new DriveFile(['id' => $id, 'name' => $file->getName(), 'webViewLink' => "https://drive.example/$id"]);
    }

    public function get(string $id, array $params = []): object
    {
        $this->calls[] = ['get', $params];

        if (! isset($this->nodes[$id])) {
            throw new RuntimeException("File non trovato: $id");
        }

        if (($params['alt'] ?? null) === 'media') {
            return $this->body($this->nodes[$id]['data'] ?? '');
        }

        return new DriveFile($this->toArray($this->nodes[$id]));
    }

    public function export(string $id, string $mime, array $params = []): object
    {
        return $this->body('export:'.$id.':'.$mime);
    }

    private function body(string $contents): object
    {
        return new class($contents)
        {
            public function __construct(private string $contents) {}

            public function getBody(): object
            {
                $contents = $this->contents;

                return new class($contents)
                {
                    public function __construct(private string $c) {}

                    public function getContents(): string
                    {
                        return $this->c;
                    }
                };
            }
        };
    }

    private function toArray(array $node): array
    {
        $data = ['id' => $node['id'], 'name' => $node['name'], 'mimeType' => $node['mimeType']];
        if ($node['shortcut']) {
            $data['shortcutDetails'] = ['targetId' => $node['shortcut']];
        }

        return $data;
    }

    /** @return array<int, string> Nomi dei figli di una cartella. */
    public function children(string $parent): array
    {
        return array_values(array_map(fn ($n) => $n['name'], array_filter($this->nodes, fn ($n) => in_array($parent, $n['parents'], true))));
    }
}

class FakeDrive
{
    public static function make(): array
    {
        $drive = new Drive(new Client);
        $drive->files = $files = new FakeDriveFiles;

        return [$drive, $files];
    }
}
