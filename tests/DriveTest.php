<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;
use Unico\Core\Drive\AuditFolderProvisioner;
use Unico\Core\Drive\CompanyFolderProvisioner;
use Unico\Core\Drive\DocumentDriveSync;
use Unico\Core\Drive\DriveFolders;
use Unico\Core\Drive\DriveUploader;
use Unico\Core\Drive\EmailDriveArchiver;
use Unico\Core\Drive\GoogleDriveFactory;
use Unico\Core\Drive\ZipExporter;
use Unico\Core\Models\Audit;
use Unico\Core\Models\Company;
use Unico\Core\Models\Document;
use Unico\Core\Models\DocumentType;
use Unico\Core\Models\IncomingEmail;
use Unico\Core\Tests\Support\CoreDatabase;
use Unico\Core\Tests\Support\FakeDrive;
use Unico\Core\Tests\Support\FakeDriveFiles;

require_once __DIR__.'/Support/FakeDrive.php';

class DriveTest extends TestCase
{
    private $drive;

    private FakeDriveFiles $files;

    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('documents', 'document_types', 'incoming_emails', 'audits', 'companies');
        [$this->drive, $this->files] = FakeDrive::make();
        config([
            'unico-core.drive' => [
                'root_folder_id' => 'ROOT',
                'company_initial_folders' => ['STARTUP'],
                'company_folders' => ['NOMINE', 'RECLAMI', 'RELAZIONE DPO/2026'],
                'audit_folder' => 'AUDIT',
                'audit_buckets' => ['fornitore' => 'FORNITORI', 'cliente' => 'MANDATARIE'],
                'email_folder' => 'RECLAMI',
            ],
        ]);
    }

    private function folders(): DriveFolders
    {
        return new DriveFolders($this->drive);
    }

    private function company(array $attributes = []): Company
    {
        return Company::create($attributes + ['name' => 'Azienda '.uniqid(), 'documents_storage' => 'google_drive']);
    }

    // --- DriveFolders ---

    public function test_ensure_creates_the_whole_path_and_returns_the_last_folder(): void
    {
        $id = $this->folders()->ensure('ROOT', 'A/B/C');

        $this->assertSame('C', $this->files->nodes[$id]['name']);
        $this->assertSame(['A'], $this->files->children('ROOT'));
        $this->assertSame(3, count($this->files->nodes));
    }

    public function test_ensure_reuses_existing_folders_on_a_shared_drive(): void
    {
        $existing = $this->files->addFolder('A', 'ROOT');

        $first = $this->folders()->ensure('ROOT', ['A', 'B']);
        $second = $this->folders()->ensure('ROOT', ['A', 'B']);

        $this->assertSame($first, $second);
        $this->assertSame(2, count($this->files->nodes));
        $this->assertSame(['B'], $this->files->children($existing));
    }

    public function test_every_call_asks_for_shared_drive_support(): void
    {
        $this->folders()->ensure('ROOT', 'X');

        foreach ($this->files->calls as [$method, $params]) {
            $this->assertTrue($params['supportsAllDrives'] ?? false, $method);
        }
    }

    public function test_names_with_quotes_and_backslashes_are_escaped(): void
    {
        $this->assertSame("a\\'b", DriveFolders::escape("a'b"));
        $this->assertSame('a\\\\b', DriveFolders::escape('a\\b'));

        $id = $this->folders()->ensure('ROOT', ["L'Aquila"]);
        $this->assertSame($id, $this->folders()->ensure('ROOT', ["L'Aquila"]));
        $this->assertSame(1, count($this->files->nodes));
    }

    public function test_empty_path_segments_are_ignored(): void
    {
        $this->assertSame('ROOT', $this->folders()->ensure('ROOT', ' / '));
    }

    // --- DriveUploader ---

    public function test_uploader_sends_a_local_file_into_the_folder(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'up_');
        file_put_contents($path, 'contenuto');

        $file = (new DriveUploader($this->drive))->uploadFile($path, 'ROOT', 'nome.txt');
        unlink($path);

        $this->assertSame('nome.txt', $file->getName());
        $this->assertSame('contenuto', $this->files->nodes[$file->getId()]['data']);
        $this->assertSame(['ROOT'], $this->files->nodes[$file->getId()]['parents']);
        $this->assertSame('contenuto', (new DriveUploader($this->drive))->download($file->getId()));
    }

    public function test_uploader_rejects_a_missing_file(): void
    {
        try {
            (new DriveUploader($this->drive))->uploadFile('/non/esiste.txt', 'ROOT');
            $this->fail('atteso RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('non esiste', $e->getMessage());
        }
    }

    public function test_uploader_knows_which_files_already_exist(): void
    {
        $this->files->addFile('presente.txt', 'ROOT', 'x');
        $uploader = new DriveUploader($this->drive);

        $this->assertTrue($uploader->exists('ROOT', 'presente.txt'));
        $this->assertFalse($uploader->exists('ROOT', 'assente.txt'));
        $this->assertFalse($uploader->exists('ALTRA', 'presente.txt'));
    }

    // --- Cartelle company e audit ---

    public function test_company_folder_goes_under_the_root_with_initial_subfolders(): void
    {
        $company = $this->company();

        (new CompanyFolderProvisioner($this->folders()))->provisionInitial($company);

        $companyFolder = $company->fresh()->drive_folder_id;
        $this->assertSame([$company->name], $this->files->children('ROOT'));
        $this->assertSame(['STARTUP'], $this->files->children($companyFolder));
    }

    public function test_company_folder_goes_under_the_parent_company_folder(): void
    {
        $holding = $this->company(['drive_folder_id' => 'HOLDING']);
        $company = $this->company(['parent_id' => $holding->id]);

        (new CompanyFolderProvisioner($this->folders()))->provisionInitial($company);

        $this->assertSame([$company->name], $this->files->children('HOLDING'));
        $this->assertSame([], $this->files->children('ROOT'));
    }

    public function test_company_with_a_folder_is_left_alone(): void
    {
        $company = $this->company(['drive_folder_id' => 'GIA']);

        (new CompanyFolderProvisioner($this->folders()))->provisionInitial($company);

        $this->assertSame([], $this->files->nodes);
    }

    public function test_company_folder_needs_a_parent(): void
    {
        config(['unico-core.drive.root_folder_id' => null]);

        try {
            (new CompanyFolderProvisioner($this->folders()))->provisionInitial($this->company());
            $this->fail('atteso RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cartella madre', $e->getMessage());
        }
    }

    public function test_standard_folders_are_created_once_including_nested_paths(): void
    {
        $company = $this->company();
        $provisioner = new CompanyFolderProvisioner($this->folders());
        $provisioner->provisionInitial($company);

        $provisioner->provisionStandardFolders($company->fresh());
        $provisioner->provisionStandardFolders($company->fresh());

        $root = $company->fresh()->drive_folder_id;
        $this->assertSame(['STARTUP', 'NOMINE', 'RECLAMI', 'RELAZIONE DPO'], $this->files->children($root));
    }

    public function test_standard_folders_wait_for_the_company_folder(): void
    {
        (new CompanyFolderProvisioner($this->folders()))->provisionStandardFolders($this->company());

        $this->assertSame([], $this->files->nodes);
    }

    private function audit(?Company $company, string $type, ?object $subject): Audit
    {
        $audit = Audit::create(['company_id' => $company?->id, 'auditable_type' => $type, 'auditable_id' => 1]);
        $audit->setRelation('auditable', $subject);

        return $audit;
    }

    public function test_audit_folder_is_nested_by_bucket_and_subject(): void
    {
        $company = $this->company(['drive_folder_id' => 'CMP']);
        $audit = $this->audit($company, 'fornitore', (object) ['name' => 'ACME Srl']);

        $id = (new AuditFolderProvisioner($this->folders()))->provision($audit);

        $this->assertSame($id, $audit->fresh()->drive_folder_id);
        $this->assertSame('AUDIT', $this->files->nodes[$id]['name']);
        $bucket = array_values(array_filter($this->files->nodes, fn ($n) => $n['name'] === 'FORNITORI'));
        $this->assertSame(['CMP'], $bucket[0]['parents']);
        $this->assertSame(['ACME Srl'], $this->files->children($bucket[0]['id']));
    }

    public function test_audit_folder_is_skipped_when_something_is_missing(): void
    {
        $provisioner = new AuditFolderProvisioner($this->folders());
        $company = $this->company(['drive_folder_id' => 'CMP']);

        $this->assertNull($provisioner->provision($this->audit($company, 'sconosciuto', (object) ['name' => 'X'])));
        $this->assertNull($provisioner->provision($this->audit($this->company(), 'fornitore', (object) ['name' => 'X'])));
        $this->assertNull($provisioner->provision($this->audit($company, 'cliente', null)));
        $this->assertSame([], $this->files->nodes);
    }

    // --- Sincronizzazione documenti ---

    private function syncWith(array $mediaByDocument): DocumentDriveSync
    {
        return new class(new DriveUploader($this->drive), $mediaByDocument) extends DocumentDriveSync
        {
            public function __construct(DriveUploader $uploader, private array $media)
            {
                parent::__construct($uploader);
            }

            protected function mediaFor(Document $document): ?object
            {
                return $this->media[$document->name] ?? null;
            }
        };
    }

    public function test_sync_uploads_documents_and_updates_their_drive_fields(): void
    {
        $company = $this->company(['drive_folder_id' => 'CMP']);
        $type = DocumentType::create(['name' => 'Tipo', 'code' => 'T1']);
        $document = Document::create(['company_id' => $company->id, 'documentable_type' => 'company', 'documentable_id' => $company->id, 'document_type_id' => $type->id, 'name' => 'visura']);

        $media = new class('visura.pdf', 'pdf!') { public function __construct(public string $file_name, string $content, public string $p = '') { $this->p = tempnam(sys_get_temp_dir(), 'm_'); file_put_contents($this->p, $content); } public function getPath() { return $this->p; } };

        $result = $this->syncWith(['visura' => $media])->syncCompanyDocuments($company);

        $this->assertSame(1, $result['synced']);
        $document = $document->fresh();
        $this->assertSame('synced', $document->sync_status);
        $this->assertSame('google_drive', $document->source_app);
        $this->assertSame('CMP', $document->app_drive_id);
        $this->assertTrue(str_starts_with($document->document_url, 'https://drive.example/'));
        $this->assertSame(['visura.pdf'], $this->files->children('CMP'));
    }

    public function test_sync_marks_failures_and_goes_on(): void
    {
        $company = $this->company(['drive_folder_id' => 'CMP']);
        $type = DocumentType::create(['name' => 'Tipo', 'code' => 'T2']);
        $make = fn ($name) => Document::create(['company_id' => $company->id, 'documentable_type' => 'company', 'documentable_id' => 1, 'document_type_id' => $type->id, 'name' => $name]);
        $quota = $make('quota');
        $missing = $make('mancante');
        $none = $make('senza-file');
        $done = $make('fatto');
        $done->update(['sync_status' => 'synced', 'document_url' => 'https://gia']);

        $mk = fn ($path) => new class($path) { public string $file_name = 'f.txt'; public function __construct(private string $p) {} public function getPath() { return $this->p; } };
        $real = tempnam(sys_get_temp_dir(), 'm_');
        file_put_contents($real, 'x');
        $this->files->failUploads = true;

        $result = $this->syncWith(['quota' => $mk($real), 'mancante' => $mk('/non/esiste'), 'fatto' => $mk($real)])->syncCompanyDocuments($company);

        $this->assertSame(['synced' => 0, 'failed' => 2, 'skipped' => 2], array_diff_key($result, ['errors' => 1]));
        $this->assertSame('failed', $quota->fresh()->sync_status);
        $this->assertSame('failed', $missing->fresh()->sync_status);
        $this->assertStringContainsString('storageQuotaExceeded', $result['errors']['quota']);
        $this->assertStringContainsString('File locale mancante', $result['errors']['mancante']);
        $this->assertSame('local', $none->fresh()->sync_status);
    }

    public function test_sync_needs_a_company_folder(): void
    {
        try {
            $this->syncWith([])->syncCompanyDocuments($this->company());
            $this->fail('atteso RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('drive_folder_id', $e->getMessage());
        }
    }

    public function test_sync_skips_when_company_storage_is_not_google_drive(): void
    {
        $company = $this->company(['documents_storage' => 'local']);

        $result = $this->syncWith([])->syncCompanyDocuments($company);

        $this->assertSame(['synced' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []], $result);
    }

    // --- Archiviazione email ---

    private function archiver(array $attachments = [], ?object $disk = null): EmailDriveArchiver
    {
        return new class($this->folders(), new DriveUploader($this->drive), $attachments, $disk) extends EmailDriveArchiver
        {
            public function __construct(DriveFolders $f, DriveUploader $u, private array $att, private ?object $disk)
            {
                parent::__construct($f, $u);
            }

            protected function attachments(IncomingEmail $email): iterable
            {
                return $this->att;
            }

            protected function localDisk(): object
            {
                return $this->disk;
            }
        };
    }

    private function email(Company $company, array $attributes = []): IncomingEmail
    {
        static $n = 0;

        return IncomingEmail::create($attributes + [
            'company_id' => $company->id,
            'message_id' => 'm'.++$n.'@example.com',
            'thread_id' => 'thread-1',
            'from_email' => 'mario@example.com',
            'from_name' => 'Mario Rossi',
            'to' => [['email' => 'dpo@azienda.it', 'name' => 'DPO']],
            'subject' => 'Reclamo: ordine n. 5/2',
            'body_text' => 'Testo del reclamo',
            'received_at' => '2026-09-01 10:30:00',
        ]);
    }

    public function test_email_is_archived_in_a_folder_per_sender_with_body_and_attachments(): void
    {
        $company = $this->company(['drive_folder_id' => 'CMP']);
        $email = $this->email($company);
        $path = tempnam(sys_get_temp_dir(), 'att_');
        file_put_contents($path, 'allegato');
        $attachment = new class($path) { public string $file_name = 'prova.pdf'; public function __construct(private string $p) {} public function getPath() { return $this->p; } };

        $result = $this->archiver([$attachment])->archive($email);

        $this->assertNull($result['local_path']);
        $this->assertSame($result['folder_id'], $email->fresh()->drive_folder_id);
        $this->assertSame(['2026-09-01_1030 - Reclamo ordine n. 5 2.txt', 'prova.pdf'], $this->files->children($result['folder_id']));
        $sender = array_values(array_filter($this->files->nodes, fn ($n) => $n['name'] === 'Mario Rossi'));
        $this->assertSame(1, count($sender));
        $body = array_values(array_filter($this->files->nodes, fn ($n) => str_ends_with($n['name'], '.txt')))[0]['data'];
        $this->assertStringContainsString('Da: Mario Rossi <mario@example.com>', $body);
        $this->assertStringContainsString('Testo del reclamo', $body);
    }

    public function test_archiving_twice_does_not_duplicate_files(): void
    {
        $company = $this->company(['drive_folder_id' => 'CMP']);
        $email = $this->email($company);
        $archiver = $this->archiver();

        $archiver->archive($email);
        $before = count($this->files->nodes);
        $archiver->archive($email->fresh());

        $this->assertSame($before, count($this->files->nodes));
    }

    public function test_emails_of_the_same_thread_share_one_folder(): void
    {
        $company = $this->company(['drive_folder_id' => 'CMP']);
        $first = $this->email($company);
        $reply = $this->email($company, ['from_email' => 'interno@azienda.it', 'from_name' => 'Ufficio', 'received_at' => '2026-09-02 09:00:00']);
        $archiver = $this->archiver();

        $a = $archiver->archive($first)['folder_id'];
        $b = $archiver->archive($reply)['folder_id'];

        $this->assertSame($a, $b);
        $this->assertSame(0, count(array_filter($this->files->nodes, fn ($n) => $n['name'] === 'Ufficio')));
    }

    private function memoryDisk(): object
    {
        return new class
        {
            public array $written = [];

            public function put(string $path, string $contents): void
            {
                $this->written[$path] = $contents;
            }

            public function path(string $path): string
            {
                return '/storage/'.$path;
            }
        };
    }

    public function test_falls_back_to_the_local_disk_when_the_company_has_no_drive_folder(): void
    {
        $company = $this->company();
        $disk = $this->memoryDisk();

        $result = $this->archiver([], $disk)->archive($this->email($company));

        $this->assertNull($result['folder_id']);
        $this->assertSame("/storage/reclami/{$company->id}/Mario Rossi", $result['local_path']);
        $this->assertSame(1, count($disk->written));
        $this->assertSame([], $this->files->nodes);
    }

    public function test_falls_back_to_the_local_disk_when_upload_fails_but_keeps_the_folder(): void
    {
        $company = $this->company(['drive_folder_id' => 'CMP']);
        $email = $this->email($company);
        $disk = $this->memoryDisk();
        $this->files->failUploads = true;

        $result = $this->archiver([], $disk)->archive($email);

        $this->assertNotNull($result['local_path']);
        $this->assertNotNull($email->fresh()->drive_folder_id);
        $this->assertSame($result['folder_id'], $email->fresh()->drive_folder_id);
    }

    // --- ZIP ---

    public function test_zip_export_keeps_the_tree_resolves_shortcuts_and_exports_google_docs(): void
    {
        $sub = $this->files->addFolder('Sotto', 'ROOT');
        $this->files->addFile('a.txt', 'ROOT', 'AAA');
        $this->files->addFile('b.txt', $sub, 'BBB');
        $target = $this->files->addFile('vero.txt', 'ALTROVE', 'VERO');
        $this->files->addShortcut('scorciatoia.txt', 'ROOT', $target);
        $this->files->addShortcut('rotta.txt', 'ROOT', 'NONESISTE');
        $this->files->addFile('Relazione', 'ROOT', '', 'application/vnd.google-apps.document');

        $path = (new ZipExporter($this->drive))->exportFolderToZip('ROOT', 'out.zip');
        $zip = new ZipArchive;
        $zip->open($path);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);

        $this->assertSame('AAA', $entries['a.txt']);
        $this->assertSame('BBB', $entries['Sotto/b.txt']);
        $this->assertSame('VERO', $entries['scorciatoia.txt']);
        $this->assertFalse(isset($entries['rotta.txt']));
        $this->assertTrue(isset($entries['Relazione.docx']));
        $this->assertTrue(array_key_exists('Sotto/', $entries));
    }

    // --- Factory ---

    public function test_factory_needs_the_credentials_file(): void
    {
        config(['unico-core.drive.credentials' => '/non/esiste.json']);

        try {
            GoogleDriveFactory::make();
            $this->fail('atteso RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('/non/esiste.json', $e->getMessage());
        }
    }

    public function test_factory_builds_a_drive_service_with_optional_impersonation(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cred_');
        file_put_contents($path, json_encode(['type' => 'service_account', 'client_id' => '1234567890', 'client_email' => 'sa@example.iam.gserviceaccount.com', 'private_key' => 'x']));
        config(['unico-core.drive.credentials' => $path, 'unico-core.drive.impersonate' => 'utente@azienda.it']);

        $this->assertTrue(GoogleDriveFactory::make() instanceof \Google\Service\Drive);
        $this->assertTrue(GoogleDriveFactory::make(readOnly: true) instanceof \Google\Service\Drive);
        unlink($path);
    }
}
