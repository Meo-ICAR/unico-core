<?php

namespace Unico\Core\Seeders;

use Illuminate\Database\Seeder;
use Unico\Core\Models\DocumentType;

/**
 * Tipi documento per i contratti Edison (GBD Energy): contratto, modulo SDD, dichiarazione sul titolo di possesso dell'immobile
 * e bolletta. I moduli PDF (`pdf_modules`) si caricano a parte: finché mancano, questi documenti non si producono.
 * Imposta anche il riconoscimento AI di visura camerale ed estratto conto, se non c'è già. Ripetibile.
 */
class EdisonDocumentTypesSeeder extends Seeder
{
    /** @var array<string, array<string, mixed>> */
    public const NEW_TYPES = [
        'Contratto di fornitura Edison' => ['is_signed' => true, 'is_template' => true, 'nature' => 'template_fillable', 'doctype' => 'modulo'],
        'Modulo SDD Edison' => ['is_signed' => true, 'is_template' => true, 'nature' => 'template_fillable', 'doctype' => 'modulo'],
        'Dichiarazione titolo di possesso immobile' => ['is_signed' => true, 'is_template' => true, 'nature' => 'template_fillable', 'doctype' => 'modulo'],
        'Bolletta luce/gas' => ['is_signed' => false, 'is_template' => false, 'nature' => 'incoming', 'ai_kind' => 'bolletta'],
    ];

    public function run(): void
    {
        foreach (self::NEW_TYPES as $name => $attributes) {
            DocumentType::query()->firstOrCreate(['name' => $name], $attributes + [
                'is_practice' => true, 'is_client' => true, 'is_person' => true, 'is_company' => true,
            ]);
        }

        foreach (['VISURA_CAMERALE' => 'visura', 'ESTRATTO_CONTO' => 'estratto_conto'] as $code => $kind) {
            DocumentType::query()->where('code', $code)->whereNull('ai_kind')->update(['ai_kind' => $kind]);
        }
    }
}
