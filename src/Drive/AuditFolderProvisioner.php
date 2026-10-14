<?php

namespace Unico\Core\Drive;

use Unico\Core\Models\Audit;

/**
 * Crea la cartella delle evidenze di un audit: `<cartella company>/<gruppo>/<soggetto>/AUDIT`,
 * dove il gruppo dipende dal tipo di soggetto controllato (`unico-core.drive.audit_buckets`).
 */
class AuditFolderProvisioner
{
    public function __construct(private DriveFolders $folders) {}

    /** @return string|null ID della cartella, o null se non c'è nulla da fare */
    public function provision(Audit $audit): ?string
    {
        $bucket = config('unico-core.drive.audit_buckets.'.$audit->auditable_type);
        $companyFolderId = $audit->company?->drive_folder_id;
        $name = $this->subjectName($audit->auditable);

        if (blank($bucket) || blank($companyFolderId) || blank($name)) {
            return null;
        }

        $folderId = $this->folders->ensure($companyFolderId, [$bucket, $name, config('unico-core.drive.audit_folder', 'AUDIT')]);

        $audit->forceFill(['drive_folder_id' => $folderId])->saveQuietly();

        return $folderId;
    }

    private function subjectName(mixed $subject): ?string
    {
        if (! $subject) {
            return null;
        }

        $name = $subject->name ?? trim(($subject->first_name ?? '').' '.($subject->last_name ?? ''));

        return filled($name) ? $name : null;
    }
}
