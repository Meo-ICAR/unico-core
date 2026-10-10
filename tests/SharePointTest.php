<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Unico\Core\Enums\SyncStatus;
use Unico\Core\Models\Document;
use Unico\Core\SharePoint\DocumentSharePointSync;
use Unico\Core\SharePoint\SharePointUploader;
use Unico\Core\Tests\Support\CoreDatabase;
use Unico\Core\Tests\Support\FakeGraph;

class SharePointTest extends TestCase
{
    private FakeGraph $graph;

    private SharePointUploader $uploader;

    private string $tmp;

    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('documents');
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap(\Unico\Core\MorphMap::all(), merge: true);
        config(['unico-core.sharepoint' => [
            'tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's', 'drive_id' => 'DRIVE1', 'upload_root' => 'Unico',
        ]]);
        $this->graph = new FakeGraph;
        $this->uploader = new SharePointUploader($this->graph);
        $this->tmp = tempnam(sys_get_temp_dir(), 'sp');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp);
    }

    private function document(array $attributes = []): Document
    {
        return Document::create($attributes + ['documentable_type' => 'company', 'documentable_id' => 99, 'name' => 'Doc']);
    }

    public function test_is_configured_needs_all_four_settings(): void
    {
        $this->assertTrue($this->uploader->isConfigured());

        foreach (['tenant_id', 'client_id', 'client_secret', 'drive_id'] as $key) {
            $saved = config("unico-core.sharepoint.{$key}");
            config(["unico-core.sharepoint.{$key}" => '']);
            $this->assertFalse($this->uploader->isConfigured(), $key);
            config(["unico-core.sharepoint.{$key}" => $saved]);
        }
    }

    public function test_sanitize_and_remote_path(): void
    {
        $this->assertSame('a-b-c', $this->uploader->sanitize('a/b:c'));
        $this->assertSame('_', $this->uploader->sanitize(' .. '));
        $this->assertSame(120, mb_strlen($this->uploader->sanitize(str_repeat('x', 300))));
        $this->assertSame('Unico/Rossi%20%26%20C/visura%20%281%29.pdf', $this->uploader->remotePath(['Unico', 'Rossi & C', ''], 'visura (1).pdf'));
    }

    public function test_small_file_is_uploaded_with_a_single_put(): void
    {
        file_put_contents($this->tmp, 'contenuto');

        $result = $this->uploader->upload($this->tmp, ['Unico', 'Rossi'], 'a.pdf');

        $this->assertCount(1, $this->graph->calls);
        $this->assertSame('https://graph.microsoft.com/v1.0/drives/DRIVE1/root:/Unico/Rossi/a.pdf:/content?@microsoft.graph.conflictBehavior=rename', $this->graph->calls[0]['url']);
        $this->assertSame(['id' => 'ITEM1', 'etag' => '"etag1"', 'web_url' => 'https://tenant.sharepoint.com/sites/x/Documenti/file.pdf', 'drive_id' => 'DRIVE1'], $result);
    }

    public function test_large_file_uses_an_upload_session_in_blocks(): void
    {
        $size = SharePointUploader::SIMPLE_UPLOAD_LIMIT + 1;
        file_put_contents($this->tmp, str_repeat('x', $size));

        $this->uploader->upload($this->tmp, ['Unico'], 'grande.bin');

        $this->assertSame('POST', $this->graph->calls[0]['method']);
        $this->assertTrue(str_ends_with($this->graph->calls[0]['url'], ':/createUploadSession'));

        $puts = array_slice($this->graph->calls, 1);
        $this->assertCount(2, $puts);
        $this->assertSame('https://upload.example/session1', $puts[0]['url']);
        $this->assertFalse($puts[0]['auth'], 'l\'uploadUrl è già firmato: niente bearer');
        $this->assertSame('bytes 0-'.(SharePointUploader::CHUNK_SIZE - 1).'/'.$size, $puts[0]['headers']['Content-Range']);
        $this->assertSame('bytes '.SharePointUploader::CHUNK_SIZE.'-'.($size - 1).'/'.$size, $puts[1]['headers']['Content-Range']);
        $this->assertSame($size, $puts[0]['bytes'] + $puts[1]['bytes']);
    }

    public function test_errors_are_reported_without_the_response_body(): void
    {
        file_put_contents($this->tmp, 'x');
        $this->graph->failWith = 403;

        try {
            $this->uploader->upload($this->tmp, [], 'a.pdf');
            $this->fail('attesa eccezione');
        } catch (RuntimeException $e) {
            $this->assertSame('Upload SharePoint fallito (HTTP 403)', $e->getMessage());
        }

        try {
            $this->uploader->upload('/percorso/inesistente', [], 'a.pdf');
            $this->fail('attesa eccezione');
        } catch (RuntimeException $e) {
            $this->assertTrue(str_starts_with($e->getMessage(), 'File locale non trovato'));
        }
    }

    public function test_sync_updates_the_document_columns(): void
    {
        file_put_contents($this->tmp, 'contenuto');
        $document = $this->document(['metadata' => ['bundle_id' => 'b1']]);

        (new DocumentSharePointSync($this->uploader))->upload($document, $this->tmp, 'a.pdf');

        $fresh = $document->fresh();
        $this->assertSame(SyncStatus::SYNCED->value, $fresh->sync_status);
        $this->assertSame('sharepoint', $fresh->source_app);
        $this->assertSame('ITEM1', $fresh->app_id);
        $this->assertSame('DRIVE1', $fresh->app_drive_id);
        $this->assertSame('"etag1"', $fresh->app_etag);
        $this->assertSame('https://tenant.sharepoint.com/sites/x/Documenti/file.pdf', $fresh->document_url);
        $this->assertSame('b1', $fresh->metadata['bundle_id']);
        $this->assertSame('https://tenant.sharepoint.com/sites/x/Documenti/file.pdf', $fresh->metadata['web_url']);
        $this->assertSame(['Unico', 'company-99'], (new DocumentSharePointSync($this->uploader))->folders($document));
    }

    public function test_long_urls_stay_only_in_metadata_and_replace_the_old_cloud_url(): void
    {
        file_put_contents($this->tmp, 'x');
        $long = 'https://tenant.sharepoint.com/'.str_repeat('a', 300);
        $graph = new class extends FakeGraph
        {
            public string $url = '';

            public function put(string $url, string $bytes, array $headers = [], bool $authenticated = true): array
            {
                return ['status' => 200, 'json' => ['id' => 'I', 'webUrl' => $this->url], 'body' => ''];
            }
        };
        $graph->url = $long;
        $document = $this->document(['document_url' => 'https://vecchio.example/x']);

        (new DocumentSharePointSync(new SharePointUploader($graph)))->upload($document, $this->tmp, 'a.pdf');

        $fresh = $document->fresh();
        $this->assertNull($fresh->document_url);
        $this->assertSame($long, $fresh->metadata['web_url']);
        $this->assertSame($long, $fresh->resolved_url);
    }

    public function test_failure_is_recorded_on_the_document(): void
    {
        $document = $this->document();

        (new DocumentSharePointSync($this->uploader))->markFailed($document, new RuntimeException(str_repeat('e', 900)));

        $fresh = $document->fresh();
        $this->assertSame('failed', $fresh->sync_status);
        $this->assertSame(500, strlen($fresh->metadata['sync_error']));
    }

    public function test_sync_status_enum_matches_the_column_values(): void
    {
        $this->assertSame(['local', 'syncing', 'synced', 'failed'], array_map(fn ($c) => $c->value, SyncStatus::cases()));
        foreach (SyncStatus::cases() as $status) {
            $this->assertNotEmpty($status->getLabel());
            $this->assertStringStartsWith('heroicon-', $status->getIcon());
        }
    }
}
