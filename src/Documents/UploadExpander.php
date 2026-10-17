<?php

namespace Unico\Core\Documents;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use mikehaertl\pdftk\Pdf;
use Throwable;
use Unico\Core\Documents\Contracts\DocumentSegmenter;
use ZipArchive;

/**
 * Prepara un file caricato: uno ZIP si apre e ogni documento che contiene diventa un file a sé; un PDF che l'AI riconosce come
 * più documenti distinti si divide, uno per documento. Se qualcosa non va il file resta com'è: non si perde mai un caricamento.
 */
class UploadExpander
{
    private const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public function __construct(private DocumentSegmenter $segmenter) {}

    /** @return list<UploadedFile> */
    public function expand(UploadedFile $file): array
    {
        $files = $this->isZip($file) ? $this->unzip($file) : [$file];
        $out = [];

        foreach ($files as $item) {
            array_push($out, ...$this->split($item));
        }

        return $out === [] ? [$file] : $out;
    }

    private function isZip(UploadedFile $file): bool
    {
        return strtolower($file->getClientOriginalExtension()) === 'zip' || $file->getMimeType() === 'application/zip';
    }

    /** @return list<UploadedFile> */
    private function unzip(UploadedFile $file): array
    {
        $zip = new ZipArchive;

        if ($zip->open($file->getRealPath()) !== true) {
            return [$file];
        }

        $max = (int) config('unico-core.documents.upload.zip_max_files', 100);
        $maxBytes = (int) config('unico-core.documents.upload.zip_max_bytes', 200 * 1024 * 1024);
        $files = [];
        $total = 0;

        for ($i = 0; $i < $zip->numFiles && count($files) < $max; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');
            $base = basename($name);

            if ($name === '' || str_ends_with($name, '/') || str_starts_with($base, '.') || str_contains($name, '__MACOSX') || ! in_array(strtolower(pathinfo($base, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                continue;
            }

            $total += (int) ($stat['size'] ?? 0);

            if ($total > $maxBytes) {
                break;
            }

            $stream = $zip->getStream($name);

            if ($stream === false) {
                continue;
            }

            $path = tempnam(sys_get_temp_dir(), 'zip');
            file_put_contents($path, stream_get_contents($stream));
            fclose($stream);
            $files[] = new UploadedFile($path, $base, mime_content_type($path) ?: null, null, true);
        }

        $zip->close();

        return $files;
    }

    /** @return list<UploadedFile> */
    private function split(UploadedFile $file): array
    {
        if (! $this->segmenter->enabled() || strtolower($file->getClientOriginalExtension()) !== 'pdf') {
            return [$file];
        }

        try {
            $pdf = new Pdf($file->getRealPath(), ['command' => config('unico-core.pdf.pdftk_binary', 'pdftk')]);
            $pages = (int) ($pdf->getData()["NumberOfPages"] ?? 0);

            if ($pages < (int) config('unico-core.documents.upload.split_min_pages', 2)) {
                return [$file];
            }

            $segments = $this->segmenter->segments((string) file_get_contents($file->getRealPath()), $pages);

            if ($segments === null || count($segments) < 2 || ! $this->coversAllPages($segments, $pages)) {
                return [$file];
            }

            $parts = [];
            $stem = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

            foreach ($segments as $index => $segment) {
                $path = tempnam(sys_get_temp_dir(), 'pdf');
                $part = new Pdf($file->getRealPath(), ['command' => config('unico-core.pdf.pdftk_binary', 'pdftk')]);

                if (! $part->cat($segment['from'], $segment['to'])->saveAs($path)) {
                    return [$file];
                }

                $label = filled($segment['label']) ? ' - '.$segment['label'] : '';
                $parts[] = new UploadedFile($path, $stem.' ('.($index + 1).')'.$label.'.pdf', 'application/pdf', null, true);
            }

            return $parts;
        } catch (Throwable $e) {
            Log::warning('Divisione del PDF non riuscita', ['exception' => $e::class]);

            return [$file];
        }
    }

    /** Gli intervalli devono coprire le pagine una sola volta, in ordine. */
    private function coversAllPages(array $segments, int $pages): bool
    {
        $next = 1;

        foreach ($segments as $segment) {
            if ($segment['from'] !== $next || $segment['to'] < $segment['from']) {
                return false;
            }

            $next = $segment['to'] + 1;
        }

        return $next === $pages + 1;
    }
}
