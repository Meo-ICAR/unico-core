<?php

namespace Unico\Core\Tests;

use Carbon\Carbon;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Unico\Core\Models\PdfModule;
use Unico\Core\Models\PdfModuleField;
use Unico\Core\Pdf\ModuleFormatter;
use Unico\Core\Pdf\PdfFormException;
use Unico\Core\Pdf\PdfFormFiller;
use Unico\Core\Pdf\ResolvedModuleData;
use Unico\Core\Tests\Support\CoreDatabase;
use Unico\Core\Tests\Support\ReadsPdfFields;

class PdfFormFillerTest extends TestCase
{
    use ReadsPdfFields;

    private string $root;

    public static function setUpBeforeClass(): void
    {
        CoreDatabase::boot();
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/pdfmod_'.uniqid();
        mkdir($this->root.'/module', 0777, true);
        file_put_contents($this->root.'/module/modulo-prova.pdf', $this->fixturePdfContents());

        $app = Container::getInstance();
        config([
            'filesystems.default' => 'moduli',
            'filesystems.disks.moduli' => ['driver' => 'local', 'root' => $this->root],
            'unico-core.pdf.modules_disk' => 'moduli',
            'unico-core.pdf.pdftk_binary' => 'pdftk',
        ]);
        $app->instance('filesystem', new FilesystemManager($app));
        Facade::clearResolvedInstance('filesystem');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);
    }

    /** @param  array<int, array<string, mixed>>  $fields */
    private function module(array $fields, string $path = 'module/modulo-prova.pdf'): PdfModule
    {
        $module = new PdfModule(['name' => 'Modulo prova', 'file_path' => $path]);
        $module->setRelation('pdfModuleFields', collect(array_map(fn (array $attributes) => new PdfModuleField($attributes), $fields)));

        return $module;
    }

    /** @param  array<string, mixed>  $values */
    private function data(array $values): ResolvedModuleData
    {
        return new ResolvedModuleData($values);
    }

    public function test_build_field_values_formats_text_and_resolves_checkboxes(): void
    {
        $module = $this->module([
            ['pdf_field_name' => 'cliente', 'pdf_field_type' => 'text', 'source_key' => 'client.nominativo', 'formatter' => 'upper'],
            ['pdf_field_name' => 'importo', 'pdf_field_type' => 'text', 'source_key' => 'pratica.amount', 'formatter' => 'money_it'],
            ['pdf_field_name' => 'consenso', 'pdf_field_type' => 'checkbox', 'source_key' => 'client.is_person', 'checkbox_on_value' => 'si'],
            ['pdf_field_name' => 'non_mappato', 'pdf_field_type' => 'text', 'source_key' => null],
        ]);

        $values = (new PdfFormFiller)->buildFieldValues($module, $this->data([
            'client.nominativo' => 'Rossi Mario',
            'pratica.amount' => '15000.00',
            'client.is_person' => true,
        ]));

        $this->assertSame(['cliente' => 'ROSSI MARIO', 'importo' => '15.000,00', 'consenso' => 'si'], $values);
    }

    public function test_checkbox_off_when_falsy_and_negated_condition(): void
    {
        $module = $this->module([
            ['pdf_field_name' => 'a', 'pdf_field_type' => 'checkbox', 'source_key' => 'client.is_person', 'checkbox_on_value' => 'si'],
            ['pdf_field_name' => 'b', 'pdf_field_type' => 'checkbox', 'checkbox_when' => '!client.is_person', 'checkbox_on_value' => 'si'],
            ['pdf_field_name' => 'c', 'pdf_field_type' => 'checkbox', 'checkbox_on_value' => 'si'],
        ]);

        $values = (new PdfFormFiller)->buildFieldValues($module, $this->data(['client.is_person' => false]));

        $this->assertSame(['a' => 'Off', 'b' => 'si'], $values);
    }

    public function test_checkbox_when_can_compare_with_a_value(): void
    {
        $module = $this->module([
            ['pdf_field_name' => 'a', 'pdf_field_type' => 'checkbox', 'checkbox_when' => 'client.sex=M', 'checkbox_on_value' => 'si'],
            ['pdf_field_name' => 'b', 'pdf_field_type' => 'checkbox', 'checkbox_when' => 'client.sex=F', 'checkbox_on_value' => 'si'],
        ]);

        $values = (new PdfFormFiller)->buildFieldValues($module, $this->data(['client.sex' => 'M']));

        $this->assertSame(['a' => 'si', 'b' => 'Off'], $values);
    }

    public function test_empty_values_do_not_overwrite_the_template(): void
    {
        $module = $this->module([['pdf_field_name' => 'cliente', 'pdf_field_type' => 'text', 'source_key' => 'client.first_name']]);

        $this->assertSame([], (new PdfFormFiller)->buildFieldValues($module, $this->data(['client.first_name' => null])));
    }

    public function test_formatter_defaults(): void
    {
        $this->assertSame('06/10/2026', ModuleFormatter::format(Carbon::parse('2026-10-06'), null));
        $this->assertSame('Sì', ModuleFormatter::format(true, null));
        $this->assertSame('06/10/2026', ModuleFormatter::format('2026-10-06', ModuleFormatter::DateIt));
        $this->assertSame('', ModuleFormatter::format('', ModuleFormatter::Upper));
    }

    public function test_referenced_keys_include_the_checkbox_condition(): void
    {
        $field = new PdfModuleField(['pdf_field_type' => 'checkbox', 'source_key' => 'a.b', 'checkbox_when' => '!c.d=1']);

        $this->assertSame(['a.b', 'c.d'], $field->referencedKeys());
    }

    public function test_fill_writes_values_into_the_real_pdf(): void
    {
        $this->skipUnlessPdftk();

        $module = $this->module([
            ['pdf_field_name' => 'cliente', 'pdf_field_type' => 'text', 'source_key' => 'client.nominativo'],
            ['pdf_field_name' => 'importo', 'pdf_field_type' => 'text', 'source_key' => 'pratica.oggi', 'formatter' => 'date_it'],
            ['pdf_field_name' => 'consenso', 'pdf_field_type' => 'checkbox', 'source_key' => 'client.is_person', 'checkbox_on_value' => 'si'],
        ]);

        $pdf = (new PdfFormFiller)->fill($module, $this->data([
            'client.nominativo' => 'Niccolò D\'Avéna & Figli',
            'pratica.oggi' => Carbon::parse('2026-10-06'),
            'client.is_person' => true,
        ]));

        $this->assertStringStartsWith('%PDF', $pdf);

        $fields = $this->readPdfFields($pdf);
        $this->assertSame('Niccolò D\'Avéna & Figli', $fields['cliente']);
        $this->assertSame('06/10/2026', $fields['importo']);
        $this->assertSame('si', $fields['consenso']);
        $this->assertNull($fields['non_mappato']);
    }

    public function test_flatten_removes_the_form_fields(): void
    {
        $this->skipUnlessPdftk();

        $module = $this->module([['pdf_field_name' => 'cliente', 'pdf_field_type' => 'text', 'source_key' => 'client.first_name']]);

        $pdf = (new PdfFormFiller)->fill($module, $this->data(['client.first_name' => 'Mario']), flatten: true);

        $this->assertSame([], $this->readPdfFields($pdf));
    }

    public function test_flatten_content_works_on_raw_bytes(): void
    {
        $this->skipUnlessPdftk();

        $this->assertSame([], $this->readPdfFields((new PdfFormFiller)->flattenContent($this->fixturePdfContents())));
    }

    public function test_diagnostic_shows_each_field_name_and_checks_checkboxes(): void
    {
        $this->skipUnlessPdftk();

        $module = $this->module([
            ['pdf_field_name' => 'cliente', 'pdf_field_type' => 'text'],
            ['pdf_field_name' => 'importo', 'pdf_field_type' => 'text'],
            ['pdf_field_name' => 'consenso', 'pdf_field_type' => 'checkbox', 'checkbox_on_value' => 'si'],
        ]);

        $fields = $this->readPdfFields((new PdfFormFiller)->fillDiagnostic($module));

        $this->assertSame('cliente', $fields['cliente']);
        $this->assertSame('importo', $fields['importo']);
        $this->assertSame('si', $fields['consenso']);
    }

    public function test_store_diagnostic_writes_to_the_modules_disk(): void
    {
        $this->skipUnlessPdftk();

        $path = (new PdfFormFiller)->storeDiagnostic($this->module([['pdf_field_name' => 'cliente', 'pdf_field_type' => 'text']]));

        $this->assertSame('module-diagnostica/modulo-prova.pdf', $path);
        $this->assertFileExists($this->root.'/'.$path);
    }

    public function test_missing_template_throws(): void
    {
        $module = $this->module([['pdf_field_name' => 'cliente', 'pdf_field_type' => 'text', 'source_key' => 'client.first_name']], 'module/inesistente.pdf');

        $this->expectException(PdfFormException::class);
        $this->expectExceptionMessage('module/inesistente.pdf');

        (new PdfFormFiller)->fill($module, $this->data(['client.first_name' => 'Mario']));
    }

    public function test_missing_pdftk_binary_throws_a_dedicated_exception(): void
    {
        config(['unico-core.pdf.pdftk_binary' => '/percorso/inesistente/pdftk']);

        $module = $this->module([['pdf_field_name' => 'cliente', 'pdf_field_type' => 'text', 'source_key' => 'client.first_name']]);

        $this->expectException(PdfFormException::class);

        (new PdfFormFiller)->fill($module, $this->data(['client.first_name' => 'Mario']));
    }
}
