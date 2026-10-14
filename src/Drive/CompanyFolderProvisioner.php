<?php

namespace Unico\Core\Drive;

use RuntimeException;
use Unico\Core\Models\Company;

/**
 * Crea la cartella Drive di una company: dentro quella della capogruppo se esiste, altrimenti
 * dentro `unico-core.drive.root_folder_id`. Le sottocartelle vengono create in due tempi,
 * perché una company nasce come lead e la struttura completa serve solo quando diventa attiva.
 */
class CompanyFolderProvisioner
{
    public function __construct(private DriveFolders $folders) {}

    /** Crea la cartella della company e le sottocartelle iniziali (`company_initial_folders`). */
    public function provisionInitial(Company $company): void
    {
        if (filled($company->drive_folder_id)) {
            return;
        }

        $parentId = $company->parent?->drive_folder_id ?: config('unico-core.drive.root_folder_id');

        if (blank($parentId)) {
            throw new RuntimeException('Nessuna cartella madre: manca drive_folder_id della capogruppo e unico-core.drive.root_folder_id.');
        }

        $companyFolderId = $this->folders->ensure($parentId, $company->name);

        foreach ((array) config('unico-core.drive.company_initial_folders', []) as $path) {
            $this->folders->ensure($companyFolderId, $path);
        }

        $company->forceFill(['drive_folder_id' => $companyFolderId])->saveQuietly();
    }

    /** Completa la struttura con `company_folders`; non fa nulla se la company non ha ancora una cartella. */
    public function provisionStandardFolders(Company $company): void
    {
        if (blank($company->drive_folder_id)) {
            return;
        }

        foreach ((array) config('unico-core.drive.company_folders', []) as $path) {
            $this->folders->ensure($company->drive_folder_id, $path);
        }
    }
}
