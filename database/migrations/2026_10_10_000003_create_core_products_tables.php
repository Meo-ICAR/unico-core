<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Connessione del database core, configurabile in config/unico-core.php.
     */
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    /**
     * Prodotti e regole provvigionali.
     */
    public function up(): void
    {
        Schema::create('tipoprodotto', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->string('name', 50)->nullable()->comment('Tipologia di prodotto finanziario');
            $table->string('tipo_prodotto', 50)->nullable()->comment('Nome del tipo di prodotto');
            $table->string('code', 20)->nullable()->comment('Codice mnemonico del prodotto');
            $table->boolean('is_external')->nullable()->default(false)->comment('Prodotto di una banca o società esterna');
            $table->boolean('is_active')->nullable()->comment('Prodotto attivo');
            $table->boolean('is_oneclient')->nullable()->default(true)->comment('Il prodotto prevede un solo cliente per pratica');
            $table->string('oam', 20)->nullable()->comment('Codice della categoria di prodotto nelle segnalazioni OAM');
            $table->enum('tipo_provvigioni', ['Lordo', 'Erogato', 'Netto'])->nullable()->comment('Base di calcolo delle provvigioni: lordo, erogato o netto');
            $table->boolean('for_person')->default(true)->comment('Prodotto per persona fisica');
            $table->boolean('for_company')->default(true)->comment('Prodotto per persona giuridica');
            $table->boolean('requires_mandate')->default(true)->comment('Serve il mandato (non per es. utenze)');
            $table->boolean('is_third_party')->default(false)->comment('Finanziamento / impegno di terzi (non e\' una pratica di lavorazione)');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->unique(['tipo_prodotto'], 'uq_tipoprodotto_tipo_prodotto');
            $table->comment('Tipi di prodotto finanziario (mutuo, cessione del quinto, prestito, ...)');
        });

        Schema::create('tipoprodotto_sub', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('tipoprodotto_id')->comment('Tipo di prodotto di cui è un sottotipo');
            $table->string('name', 50)->comment('Tipologia di prodotto finanziario');
            $table->string('code', 20)->nullable()->comment('Codice mnemonico del sottoprodotto');
            $table->tinyText('vincoli')->nullable()->comment('Vincoli e condizioni da rispettare');
            $table->boolean('is_active')->nullable()->default(true)->comment('Sottoprodotto attivo');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->comment('Sottotipi di prodotto finanziario, con i relativi vincoli');
        });

        Schema::create('tipoprodotto_sub_constraints', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('tipoprodotto_id')->nullable()->comment('ID Prodotto (collegato a tipoprodotto). Se NULL, si applica a tutti i prodotti.');
            $table->foreignId('tipoprodotto_sub_id')->nullable()->comment('ID Sottoprodotto (collegato a tipoprodotto_sub). Se NULL, si applica a tutti i sottoprodotti del prodotto selezionato.');
            $table->string('clienti_id', 36)->nullable()->comment('UUID della Banca/Istituto erogante (collegato alla tabella clienti). Se NULL, si applica a tutte le banche/clienti.');
            $table->foreignId('role_id')->nullable()->comment('ID Ruolo/Livello dell\'agente (es. Senior, Junior). Se NULL, si applica a tutta la rete.');
            $table->integer('min_age')->nullable()->comment('Età minima del richiedente alla firma.');
            $table->integer('max_age_at_maturity')->nullable()->comment('Età massima consentita alla scadenza del finanziamento.');
            $table->decimal('min_amount', 12, 2)->nullable()->comment('Importo minimo erogabile.');
            $table->decimal('max_amount', 12, 2)->nullable()->comment('Importo massimo erogabile.');
            $table->integer('min_duration_months')->nullable()->comment('Durata minima in mesi.');
            $table->integer('max_duration_months')->nullable()->comment('Durata massima in mesi.');
            $table->integer('min_employment_months')->nullable()->comment('Anzianità lavorativa minima richiesta.');
            $table->decimal('max_debt_to_income_ratio', 5, 2)->nullable()->comment('Rapporto rata/reddito massimo % (es. 33.33).');
            $table->decimal('max_ltv_percentage', 5, 2)->nullable()->comment('LTV massimo consentito per mutui %.');
            $table->json('allowed_employment_types')->nullable()->comment('Tipi di impiego accettati JSON (es. ["indeterminato", "pensionato"]).');
            $table->json('additional_rules_json')->nullable()->comment('Ulteriori vincoli dinamici in formato chiave-valore. Es: {"requires_co_signer": true, "blocked_ateco_codes": ["96.09"]}');
            $table->text('additional_notes')->nullable()->comment('Eventuali note descrittive testuali dei vincoli per l\'operatore.');
            $table->tinyInteger('is_active')->nullable()->default(1)->comment('Vincolo attivo');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->timestamp('deleted_at')->nullable()->comment('Data di eliminazione logica del vincolo (Soft Delete).');
            $table->index(['tipoprodotto_id', 'tipoprodotto_sub_id', 'clienti_id'], 'idx_commission_hierarchy');
            $table->comment('Matrice delle regole provvigionali. Gestisce la gerarchia a cascata (Prodotto -> Sottoprodotto -> Cliente UUID) con eliminazione logica.');
        });

        Schema::create('provvigioni_rules', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('tipoprodotto_id')->nullable()->comment('Prodotto a cui si applica il vincolo. Se NULL, si applica a tutti i prodotti della banca (DB Esterno).');
            $table->foreignId('tipoprodotto_sub_id')->nullable()->comment('Sottoprodotto specifico (es. CQS Pensionati). Se NULL, si applica a tutto il macro-prodotto.');
            $table->string('clienti_id', 36)->nullable()->comment('L\'istituto di credito che impone il vincolo (DB Esterno).');
            $table->foreignId('kind_id')->nullable()->comment('ID Ruolo/Livello dell\'agente (es. Senior, Junior). Se NULL, si applica a tutta la rete.');
            $table->string('fornitori_id', 36)->nullable()->comment('Agente');
            $table->boolean('coordinamento')->default(false)->comment('Provv di coordinamento');
            $table->boolean('iscliente')->default(false)->comment('Provv di mediazione da cliente');
            $table->string('tipo_provvigioni', 30)->default('lordo')->comment('Tipo di calcolo: lordo, erogato, netto, fixed (importo fisso)');
            $table->decimal('value', 10, 4)->nullable()->default(0.0000)->comment('Valore della provvigione. Può essere una percentuale (es. 2.5000 per 2.5%) o un importo fisso in euro.');
            $table->date('valid_from')->nullable()->comment('Inizio validità temporale di questa specifica tariffa.');
            $table->date('valid_to')->nullable()->comment('Fine validità temporale di questa specifica tariffa.');
            $table->text('notes')->nullable()->comment('Note interne o chiarimenti sulla specifica regola provvigionale.');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->index(['valid_from', 'valid_to'], 'idx_commission_validity');
            $table->comment('Matrice delle regole provvigionali. Gestisce la gerarchia a cascata (Prodotto -> Sottoprodotto -> Banca).');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provvigioni_rules');
        Schema::dropIfExists('tipoprodotto_sub_constraints');
        Schema::dropIfExists('tipoprodotto_sub');
        Schema::dropIfExists('tipoprodotto');
    }
};
