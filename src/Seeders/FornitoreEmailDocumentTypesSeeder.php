<?php

namespace Unico\Core\Seeders;

use Illuminate\Database\Seeder;
use Unico\Core\Models\DocumentType;

/**
 * Tipi documento per gli allegati che agenti e collaboratori (fornitori) mandano per email: fattura, nota di credito e
 * comunicazione dell'IBAN. UnicoPEC li usa per classificare gli allegati prima di archiviarli tra i documenti del produttore
 * (`documentable_type = fornitore`). Gli altri allegati usano i tipi che già esistono (es. COMUNICAZIONE_PEC). Ripetibile.
 */
class FornitoreEmailDocumentTypesSeeder extends Seeder
{
    /** @var array<string, array{name: string, description: string}> */
    public const TYPES = [
        'FATTURA_COLLABORATORE' => [
            'name' => 'Fattura del collaboratore',
            'description' => 'Fattura (anche elettronica, XML o PDF) emessa da un agente o collaboratore per i compensi di un proforma.',
        ],
        'NOTA_CREDITO_COLLABORATORE' => [
            'name' => 'Nota di credito del collaboratore',
            'description' => 'Nota di credito emessa da un agente o collaboratore per annullare o rettificare una sua fattura.',
        ],
        'COORDINATE_BANCARIE' => [
            'name' => 'Coordinate bancarie (IBAN)',
            'description' => 'Comunicazione o documento della banca con l\'IBAN su cui accreditare i compensi. Un cambio di IBAN va verificato prima di usarlo.',
        ],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $code => $attributes) {
            DocumentType::query()->updateOrCreate(['code' => $code], $attributes + [
                'source' => DocumentType::SOURCE_FORNITORE,
                'client_scope' => 'entrambi',
                'is_signed' => false,
                'is_monitored' => false,
                'is_ai_check' => true,
            ]);
        }
    }
}
