<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dati di pagamento e fiscali dell'agente (`fornitoris`), che si leggono dalle sue fatture e dalle sue comunicazioni:
 * - `iban`, `iban_holder`: dove pagare i compensi e a chi è intestato il conto;
 * - `iban_updated_at`: ultima variazione dell'IBAN;
 * - `iban_verified_at`, `iban_verified_by`: quando e da chi (utente) l'IBAN è stato verificato, per esempio con una richiamata al numero
 *   già in anagrafica e un documento della banca. Un IBAN cambiato torna «non verificato» finché qualcuno non lo conferma: è il caso
 *   tipico di frode via email, e non va usato per i pagamenti prima della verifica;
 * - `tax_regime` (ordinario, forfettario, altro) e `withholding_percent` (ritenuta d'acconto): spiegano perché il netto a pagare di una
 *   fattura è diverso dal totale;
 * - `invoice_data_at`: l'ultima volta che questi dati sono stati letti da una fattura.
 * Gli indirizzi (`indirizzo`, `cap`, `comune`, `prov`), `piva`, `cf`, `tel`, `pec` e `nomefattura` esistevano già.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        if (! Schema::hasTable('fornitoris')) {
            return;
        }

        Schema::table('fornitoris', function (Blueprint $table) {
            if (! Schema::hasColumn('fornitoris', 'iban')) {
                $table->string('iban', 34)->nullable()->index()->comment('IBAN su cui si pagano i compensi (senza spazi)');
            }

            if (! Schema::hasColumn('fornitoris', 'iban_holder')) {
                $table->string('iban_holder')->nullable()->comment('Intestatario del conto');
            }

            if (! Schema::hasColumn('fornitoris', 'iban_updated_at')) {
                $table->timestamp('iban_updated_at')->nullable()->comment('Ultima variazione dell\'IBAN');
            }

            if (! Schema::hasColumn('fornitoris', 'iban_verified_at')) {
                $table->timestamp('iban_verified_at')->nullable()->comment('Quando l\'IBAN è stato verificato (nullo = non verificato, da non usare per i pagamenti)');
            }

            if (! Schema::hasColumn('fornitoris', 'iban_verified_by')) {
                $table->unsignedBigInteger('iban_verified_by')->nullable()->comment('Utente che ha verificato l\'IBAN');
            }

            if (! Schema::hasColumn('fornitoris', 'tax_regime')) {
                $table->string('tax_regime', 20)->nullable()->comment('ordinario | forfettario | altro');
            }

            if (! Schema::hasColumn('fornitoris', 'withholding_percent')) {
                $table->decimal('withholding_percent', 5, 2)->nullable()->comment('Ritenuta d\'acconto in fattura, in percentuale');
            }

            if (! Schema::hasColumn('fornitoris', 'invoice_data_at')) {
                $table->timestamp('invoice_data_at')->nullable()->comment('Ultima lettura di questi dati da una fattura');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('fornitoris')) {
            return;
        }

        $columns = array_values(array_filter(
            ['iban', 'iban_holder', 'iban_updated_at', 'iban_verified_at', 'iban_verified_by', 'tax_regime', 'withholding_percent', 'invoice_data_at'],
            fn (string $column) => Schema::hasColumn('fornitoris', $column),
        ));

        if ($columns !== []) {
            Schema::table('fornitoris', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
