<?php

namespace Unico\Core\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Unico\Core\Models\DocumentType;

/**
 * Tipi documento del plico di una cessione del quinto (anche rinnovo) che mancavano nel catalogo, e profili di lettura AI dei documenti
 * di reddito e di finanziamento (`document_types.ai_kind`). Ripetibile e solo additivo: un tipo che esiste (per nome) non si tocca e un
 * profilo già scelto non si cambia. Da lanciare dove serve: `php artisan db:seed --class="Unico\\Core\\Seeders\\TipiDocumentoCessioneSeeder" --force`.
 * I tipi nuovi non entrano nelle check-list dei prodotti: si aggiungono ai requisiti dal pannello quando servono.
 */
class TipiDocumentoCessioneSeeder extends Seeder
{
    /**
     * Tipi nuovi: nome => attributi oltre a quelli comuni.
     *
     * @var array<string, array<string, mixed>>
     */
    public const NUOVI = [
        'Verifica assicurabilità (esito)' => [
            'description' => 'Esito della verifica di assicurabilità della cessione (compagnie e rapporto montante/TFR), spesso uno screenshot della piattaforma della banca.',
            'regex' => '/assicurabil/i',
        ],
        'Questionario Compass (QUIND)' => [
            'description' => 'Questionario di Compass sulla situazione economica del cliente (professione, entrate, abitazione, debiti, nucleo familiare), firmato.',
            'is_signed' => true, 'signer_model' => 'client', 'regex' => '/quind|questionario.*compass/i',
        ],
        'Consenso assicurato (assicurazione vita)' => [
            'description' => 'Modulo di consenso dell\'assicurato per l\'assicurazione caso morte abbinata alla cessione (es. MetLife), firmato.',
            'is_signed' => true, 'signer_model' => 'client', 'regex' => '/metlife|consenso.*assicurato/i',
        ],
        'Autocertificazione TFR disponibile' => [
            'description' => 'Dichiarazione del cliente sul TFR disponibile (presso il datore, presso il fondo) e sull\'eventuale anticipo in busta paga.',
            'is_signed' => true, 'signer_model' => 'client', 'regex' => '/autocertificazione.*tfr|tfr.*disponibile/i',
        ],
        'Modulistica TFR / fondo pensione' => [
            'description' => 'Mandato di incasso del TFR, richiesta di riscatto del fondo pensione e autorizzazione a trasmettere le dichiarazioni al fondo o al datore, firmati.',
            'is_signed' => true, 'signer_model' => 'client', 'codegroup' => 'MODULISTICA_TFR', 'regex' => '/mandato.*tfr|riscatto|fondo pensione/i',
        ],
        'Richiesta di delibera (email di invio)' => [
            'description' => 'Email con cui il plico si invia al finanziatore per la delibera: serve a conservare data, destinatari e allegato inviato.',
            'source' => 'stampa', 'regex' => '/richiesta.*delibera/i',
        ],
    ];

    /**
     * Profili di lettura: nome del tipo documento => `ai_kind`. Solo dove il profilo è ancora vuoto.
     *
     * @var array<string, string>
     */
    public const PROFILI = [
        'Busta paga' => 'busta_paga',
        'Busta paga 1' => 'busta_paga',
        'Busta paga 2' => 'busta_paga',
        'Busta paga 3' => 'busta_paga',
        'Busta paga corrente' => 'busta_paga',
        'Busta paga in corso' => 'busta_paga',
        'Busta paga precedente' => 'busta_paga',
        'Certificazione Unica (CU)' => 'cu',
        'Certificazione Unica (CU) 1' => 'cu',
        'Certificazione Unica (CU) 2' => 'cu',
        'Certificazione Unica (CU) corrente' => 'cu',
        'Certificazione Unica (CU) in corso' => 'cu',
        'Certificato di stipendio e quota cedibile' => 'certificato_stipendio',
        'Contratto di finanziamento' => 'finanziamento',
        'Informazioni europee di base sul credito (SECCI)' => 'finanziamento',
    ];

    public function run(): void
    {
        foreach (self::NUOVI as $name => $attributes) {
            if (DocumentType::query()->where('name', $name)->exists()) {
                continue;
            }

            DocumentType::query()->create($attributes + [
                'name' => $name,
                'slug' => Str::slug($name),
                'client_scope' => 'persona_fisica',
                'source' => DocumentType::SOURCE_CLIENTE,
                'is_signed' => false,
            ]);
        }

        foreach (self::PROFILI as $name => $kind) {
            DocumentType::query()->where('name', $name)->whereNull('ai_kind')->update(['ai_kind' => $kind]);
        }
    }
}
