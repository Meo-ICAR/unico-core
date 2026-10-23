<?php

namespace Unico\Core\Tests;

use PHPUnit\Framework\TestCase;
use Unico\Core\Import\RacesHistoricalImporter;
use Unico\Core\Import\RacesImportCatalog;
use Unico\Core\Tests\Support\CoreDatabase;

class ImportRacesTest extends TestCase
{
    protected function tearDown(): void
    {
        if (CoreDatabase::$capsule !== null) {
            $db = CoreDatabase::$capsule->getConnection('core');
            $db->statement('DROP TABLE IF EXISTS races_imp_dst');
            $db->statement('DROP TABLE IF EXISTS races_imp_src');
            $db->statement('DROP TABLE IF EXISTS races_imp_co');
        }

        parent::tearDown();
    }

    public function test_catalog_sends_anagrafiche_to_core_and_skips_views(): void
    {
        $dest = [];
        $legacy = [];
        foreach (RacesImportCatalog::tables() as $plan) {
            $dest[$plan['source_table']] = $plan['dest'];
            if (filled($plan['legacy'] ?? null)) {
                $legacy[] = $plan['dest_table'];
            }
        }

        $this->assertSame('core', $dest['clients']);
        $this->assertSame('core', $dest['documents']);
        $this->assertSame('proforma', $dest['provvigioni']);
        $this->assertSame('proforma', $dest['onorabilita']);
        $this->assertSame('oam', $dest['oam_pratiches']);
        $this->assertSame('oam', $dest['audits']);
        $this->assertNotContains('chat_messages', array_keys($dest));
        $this->assertNotContains('vwcoge', array_keys($dest));
        $this->assertNotContains('processes', array_keys($dest));
        $this->assertContains('documents', $legacy);
        $this->assertContains('clientis', $legacy);
        $this->assertContains('fornitoris', $legacy);

        $skipped = array_column(RacesImportCatalog::skipped(), 'table');
        $this->assertContains('vwcoge', $skipped);
        $this->assertContains('vwdocumenti', $skipped);
        $this->assertContains('chat_messages', $skipped);
        $this->assertContains('task_document_types', $skipped);
        $this->assertContains('processes', $skipped);
    }

    public function test_execute_matches_races_lists_unknown_enum_and_updates_on_second_run(): void
    {
        $this->schema();
        $db = CoreDatabase::$capsule->getConnection('core');
        $db->table('races_imp_co')->insert(['name' => 'Races Finance S.r.l.', 'vat_number' => '05822361007']);
        $db->table('races_imp_src')->insert([
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'label' => 'Banca Uno',
            'status' => 'boh',
            'company_id' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
        ]);

        $report = $this->importer(true)->run([$this->plan()]);

        $this->assertSame('trovata', $report['companies']['core']['action']);
        $this->assertSame(1, $report['tables'][0]['read']);
        $this->assertSame(1, $report['tables'][0]['inserted']);
        $this->assertSame(0, $report['tables'][0]['failed']);
        $this->assertSame(1, $report['unknown']['races_imp_dst.status=boh']);
        $row = $db->table('races_imp_dst')->first();
        $this->assertSame('Banca Uno', $row->name);
        $this->assertNull($row->status);
        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $row->legacy_id);
        $this->assertSame($report['companies']['core']['id'], $row->company_id);

        $db->table('races_imp_src')->where('id', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')->update(['label' => 'Banca Due', 'status' => 'ok']);
        $again = $this->importer(true)->run([$this->plan()]);

        $this->assertSame(0, $again['tables'][0]['inserted']);
        $this->assertSame(1, $again['tables'][0]['updated']);
        $this->assertSame(1, $db->table('races_imp_dst')->count());
        $this->assertSame('Banca Due', $db->table('races_imp_dst')->value('name'));
        $this->assertSame('ok', $db->table('races_imp_dst')->value('status'));
    }

    public function test_insert_only_adds_new_rows_and_leaves_existing_untouched(): void
    {
        $this->schema();
        $db = CoreDatabase::$capsule->getConnection('core');
        $db->table('races_imp_co')->insert(['name' => 'Races Finance S.r.l.', 'vat_number' => '05822361007']);
        $db->table('races_imp_dst')->insert(['name' => 'Carta identità', 'status' => 'ok']);
        $db->table('races_imp_src')->insert([
            ['id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'label' => 'Carta identità', 'status' => 'no', 'company_id' => null],
            ['id' => 'bbbbbbbb-bbbb-cccc-dddd-eeeeeeeeeeee', 'label' => 'Busta paga', 'status' => 'ok', 'company_id' => null],
        ]);

        $report = $this->importer(true)->run([[
            ...$this->plan(),
            'company' => false,
            'legacy' => null,
            'match' => [['name']],
            'insert_only' => true,
        ]]);

        $this->assertSame(1, $report['tables'][0]['inserted']);
        $this->assertSame(1, $report['tables'][0]['skipped']);
        $this->assertSame(0, $report['tables'][0]['updated']);
        $this->assertSame('ok', $db->table('races_imp_dst')->where('name', 'Carta identità')->value('status'));
        $this->assertSame(2, $db->table('races_imp_dst')->count());
    }

    public function test_global_insert_only_keeps_rows_already_in_destination(): void
    {
        $this->schema();
        $db = CoreDatabase::$capsule->getConnection('core');
        $db->table('races_imp_co')->insert(['name' => 'Races Finance S.r.l.', 'vat_number' => '05822361007']);
        $db->table('races_imp_dst')->insert(['legacy_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'name' => 'Banca Uno', 'status' => 'ok']);
        $db->table('races_imp_src')->insert([
            ['id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'label' => 'Banca Vecchia', 'status' => 'no', 'company_id' => null],
            ['id' => 'bbbbbbbb-bbbb-cccc-dddd-eeeeeeeeeeee', 'label' => 'Banca Nuova', 'status' => 'ok', 'company_id' => null],
        ]);

        $report = $this->importer(true, insertOnly: true)->run([$this->plan()]);

        $this->assertSame(1, $report['tables'][0]['inserted']);
        $this->assertSame(1, $report['tables'][0]['skipped']);
        $this->assertSame(0, $report['tables'][0]['updated']);
        $this->assertSame('Banca Uno', $db->table('races_imp_dst')->where('legacy_id', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')->value('name'));
        $this->assertSame('Banca Nuova', $db->table('races_imp_dst')->where('legacy_id', 'bbbbbbbb-bbbb-cccc-dddd-eeeeeeeeeeee')->value('name'));
    }

    public function test_dry_run_does_not_write_rows(): void
    {
        $this->schema();
        $db = CoreDatabase::$capsule->getConnection('core');
        $db->table('races_imp_src')->insert([
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'label' => 'Banca Uno',
            'status' => 'ok',
            'company_id' => null,
        ]);

        $report = $this->importer(false)->run([$this->plan()]);

        $this->assertSame('da creare', $report['companies']['core']['action']);
        $this->assertSame(1, $report['tables'][0]['inserted']);
        $this->assertSame(0, $db->table('races_imp_dst')->count());
        $this->assertSame(0, $db->table('races_imp_co')->count());
    }

    private function importer(bool $execute, bool $insertOnly = false): RacesHistoricalImporter
    {
        $connection = CoreDatabase::$capsule->getConnection('core');

        return new RacesHistoricalImporter(
            ['core' => $connection, 'proforma_src' => $connection],
            $execute,
            'Races',
            null,
            'races_imp_co',
            insertOnly: $insertOnly,
        );
    }

    /** @return array<string, mixed> */
    private function plan(): array
    {
        return [
            'source' => 'proforma_src',
            'source_table' => 'races_imp_src',
            'dest' => 'core',
            'dest_table' => 'races_imp_dst',
            'company' => true,
            'preserve_id' => false,
            'legacy' => 'legacy_id',
            'match' => [['legacy_id']],
            'renames' => ['label' => 'name'],
            'fk' => [],
            'local_fk' => [],
            'self_fk' => [],
            'morph' => null,
            'numeric_morph' => false,
            'prepare' => null,
            'pk' => 'id',
            'defaults' => [],
            'preserve_on_update' => [],
        ];
    }

    private function schema(): void
    {
        CoreDatabase::boot();
        $db = CoreDatabase::$capsule->getConnection('core');
        $db->statement('DROP TABLE IF EXISTS races_imp_dst');
        $db->statement('DROP TABLE IF EXISTS races_imp_src');
        $db->statement('DROP TABLE IF EXISTS races_imp_co');
        $db->statement('CREATE TABLE races_imp_co (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, name varchar(255) NOT NULL, vat_number varchar(50) NULL)');
        $db->statement("CREATE TABLE races_imp_dst (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, legacy_id char(36) NULL, name varchar(255) NULL, status enum('ok','no') NULL, company_id bigint unsigned NULL, UNIQUE KEY races_imp_dst_legacy (legacy_id))");
        $db->statement('CREATE TABLE races_imp_src (id char(36) NOT NULL PRIMARY KEY, label varchar(255) NULL, status varchar(20) NULL, company_id char(36) NULL)');
    }
}
