<?php

namespace Unico\Core\Documents;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;
use Unico\Core\Models\Audit;
use Unico\Core\Models\ComplaintRegistry;
use Unico\Core\Models\Document;
use Unico\Core\Models\Employee;
use Unico\Core\Models\TrainingRecord;

/**
 * Organizza i file dei Document su disco per azienda, tipo di proprietario e istanza
 * (company/dipendenti/mario-rossi/), invece delle cartelle numeriche di Spatie, così i documenti
 * si ritrovano anche sfogliando il filesystem. Per i documenti a livello azienda la sottocartella
 * è il `codegroup` del tipo documento.
 *
 * Due documenti con lo stesso nome file nello stesso percorso si sovrascrivono: scelta voluta,
 * per avere un percorso leggibile. Registrato solo per Document (custom_path_generators).
 */
class DocumentPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return $this->getBasePath($media).'/';
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->getBasePath($media).'/conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getBasePath($media).'/responsive-images/';
    }

    protected function getBasePath(Media $media): string
    {
        /** @var Document|null $document */
        $document = $media->model;

        $company = $this->companyFolder($document?->company?->name);
        $type = $document?->documentable_type;

        // Un documento di un corso va accanto al certificato: company/dipendenti/<dipendente>/training/
        if ($type === 'training_record') {
            $trainable = $document->documentable instanceof TrainingRecord ? $document->documentable->trainable : null;
            $employee = $trainable instanceof Employee ? $this->instanceFolder($trainable) : null;

            return $this->join($employee ? [$company, 'dipendenti', $employee, 'training'] : [$company, 'training']);
        }

        // Evidenze di un audit: company/reclami/<tipo soggetto>/<soggetto controllato>/
        if ($type === 'audit') {
            $audit = $document->documentable instanceof Audit ? $document->documentable : null;
            $auditableType = $audit?->auditable_type ? Str::slug($audit->auditable_type) : null;

            return $this->join([$company, 'reclami', $auditableType, $audit ? $this->instanceFolder($audit->auditable) : null]);
        }

        // Le filiali stanno sotto l'azienda, in una cartella con il loro nome.
        if ($type === 'branch') {
            return $this->join([$company, $this->instanceFolder($document->documentable)]);
        }

        $owner = $this->ownerFolder($type);

        return $this->join($owner
            ? [$company, $owner, $this->instanceFolder($document?->documentable)]
            : [$company, $this->categoryFolder($document?->documentType?->codegroup)]);
    }

    /** @param  array<int, string|null>  $segments */
    private function join(array $segments): string
    {
        return implode('/', array_filter($segments));
    }

    protected function companyFolder(?string $companyName): string
    {
        return Str::slug($companyName ?? '') ?: 'azienda-sconosciuta';
    }

    protected function ownerFolder(?string $documentableType): ?string
    {
        return match ($documentableType) {
            null, 'company' => null,
            'employee' => 'dipendenti',
            'client' => 'client',
            'cliente' => 'clientis',
            'fornitore', 'external_processor' => 'fornitores',
            'complaint' => 'reclami',
            default => Str::slug($documentableType) ?: null,
        };
    }

    protected function instanceFolder(?Model $documentable): ?string
    {
        if (! $documentable) {
            return null;
        }

        $label = match (true) {
            $documentable instanceof ComplaintRegistry => $documentable->protocol_number,
            default => $documentable->name ?? null,
        };

        return $label ? (Str::slug($label) ?: null) : null;
    }

    protected function categoryFolder(?string $codegroup): ?string
    {
        return $codegroup ? (Str::slug($codegroup) ?: null) : null;
    }
}
