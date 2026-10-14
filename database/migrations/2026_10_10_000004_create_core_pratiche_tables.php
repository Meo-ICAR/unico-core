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
     * Pratiche e stati.
     */
    public function up(): void
    {
        Schema::create('pratica_stati', function (Blueprint $table) {
            $table->id()->comment('ID univoco dello stato.');
            $table->string('codice')->comment('Codice univoco identificativo dello stato.');
            $table->string('name')->comment('Nome descrittivo dello stato.');
            $table->integer('ordine')->default(0)->comment('Ordine di visualizzazione dello stato nel workflow.');
            $table->boolean('is_rejected')->default(false)->comment('Indica se lo stato rappresenta un esito di rifiuto.');
            $table->boolean('is_working')->default(true)->comment('Indica se lo stato rappresenta una pratica ancora in lavorazione.');
            $table->boolean('is_estingued')->default(false)->comment('Indica se lo stato rappresenta una pratica estinta.');
            $table->string('colore')->default('gray')->comment('Colore associato allo stato per la visualizzazione nell\'interfaccia.');
            $table->string('icona')->nullable()->comment('Icona associata allo stato per la visualizzazione nell\'interfaccia.');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->timestamp('deleted_at')->nullable()->comment('Data di eliminazione logica dello stato (Soft Delete).');
            $table->unique(['codice'], 'pratica_stati_codice_unique');
            $table->comment('Stati del workflow di una pratica di finanziamento.');
        });

        Schema::create('pratica_stati_transizioni', function (Blueprint $table) {
            $table->id()->comment('ID univoco della transizione');
            $table->foreignId('stato_da_id')->comment('Stato di partenza della transizione');
            $table->foreignId('stato_a_id')->comment('Stato di arrivo della transizione');
            $table->unique(['stato_da_id', 'stato_a_id'], 'unique_stato_transizione');
            $table->comment('Transizioni di stato ammesse tra gli stati del workflow di una pratica.');
        });

        Schema::create('pratiches', function (Blueprint $table) {
            $table->id()->comment('ID univoco pratica');
            $table->string('codice_pratica')->nullable()->comment('Codice identificativo della pratica');
            $table->string('nome_cliente', 191)->nullable()->comment('Nome del cliente');
            $table->string('cognome_cliente', 191)->nullable()->comment('Cognome del cliente');
            $table->string('codice_fiscale', 191)->nullable()->comment('Codice fiscale del cliente');
            $table->string('denominazione_agente', 191)->nullable()->comment('Ragione sociale dell\'agente/rappresentante');
            $table->string('partita_iva_agente', 20)->nullable()->comment('Partita IVA dell\'agente');
            $table->string('denominazione_banca', 191)->nullable()->comment('Banca erogatrice del finanziamento');
            $table->string('denominazione_prodotto', 191)->nullable()->comment('Nome/descrizione del prodotto finanziario');
            $table->date('data_inserimento_pratica')->nullable()->comment('Data di creazione della pratica');
            $table->decimal('rata', 10, 2)->nullable()->comment('Importo della rata mensile del finanziamento');
            $table->decimal('erogato', 10, 2)->nullable()->comment('Importo lordo effettivamente erogato');
            $table->integer('nrate')->nullable()->comment('Numero totale di rate del piano di ammortamento');
            $table->date('sended_at')->nullable()->comment('Data di invio della pratica in istruttoria alla banca');
            $table->date('approved_at')->nullable()->comment('Data di approvazione/delibera da parte della banca');
            $table->date('erogated_at')->nullable()->comment('Data di effettivo perfezionamento ed erogazione');
            $table->decimal('amount', 10, 2)->nullable()->comment('Importo del finanziamento richiesto in fase di istruttoria');
            $table->decimal('net', 10, 2)->nullable()->comment('Importo netto spettante');
            $table->boolean('is_notowned')->default(false)->comment('Flag indicante se la pratica appartiene a rete esterna (1 = Sì, 0 = No)');
            $table->timestamp('upload_at')->comment('Data e ora di caricamento a sistema');
            $table->date('rejected_at')->nullable()->comment('Data di rifiuto o annullamento della pratica');
            $table->string('abi', 20)->nullable()->comment('Codice ABI dell\'istituto bancario');
            $table->string('abi_name')->nullable()->comment('Denominazione dell\'istituto bancario da codice ABI');
            $table->foreignId('company_id')->nullable()->comment('Tenant');
            $table->foreignId('pratica_stato_id')->nullable()->comment('Stato attuale della pratica');
            $table->foreignId('tipoprodotto_id')->nullable()->comment('Categoria finanziaria');
            $table->foreignId('fornitori_id')->nullable()->comment('Agente/collaboratore');
            $table->foreignId('clienti_id')->nullable()->comment('Banca/istituto erogante');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultimo aggiornamento del record');
            $table->index(['cognome_cliente'], 'idx_cliente_cf');
            $table->comment('Registro delle pratiche di finanziamento/mutuo caricate');
        });

        Schema::create('pratica_clients', function (Blueprint $table) {
            $table->id()->comment('ID univoco del legame');
            $table->string('pratica_id', 36)->comment('pratiches.id');
            $table->foreignId('client_id')->comment('clients.id');
            $table->string('role', 20)->comment('richiedente | coobbligato | garante');
            $table->string('name')->nullable()->comment('Descrizione');
            $table->text('notes')->nullable()->comment('Note specifiche sul ruolo');
            $table->text('purpose_of_relationship')->nullable()->comment('Es: Acquisto prima casa');
            $table->text('funds_origin')->nullable()->comment('Origine dei fondi dichiarata dal cliente (antiriciclaggio)');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->unique(['pratica_id', 'client_id', 'role'], 'pratica_clients_pratica_id_client_id_role_unique');
            $table->comment('Richiedente, coobbligati e garanti di una pratica');
        });

        Schema::create('pratica_status_history', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->string('pratica_id', 36)->comment('ID della pratica (riferito a pratiches.id)');
            $table->string('status_from', 191)->nullable()->comment('Stato precedente (NULL se è il primo inserimento)');
            $table->string('status_to', 191)->comment('Nuovo stato impostato');
            $table->dateTime('changed_at')->comment('Data e ora esatta del cambio di stato');
            $table->string('source', 50)->default('manual')->comment('Origine del cambio: manual, api_banca, import_excel');
            $table->text('notes')->nullable()->comment('Note o messaggi di errore restituiti dal portale bancario');
            $table->foreignId('user_id')->nullable()->comment('mysql.users.id: chi ha cambiato lo stato o inserito l\'annotazione');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->comment('Storico dei cambi di stato delle pratiche per la pipeline di avanzamento.');
        });

        Schema::create('oam_pratiches', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('company_id')->nullable()->comment('ID Azienda (Tenant)');
            $table->char('period', 8)->nullable()->comment('Anno e mese di riferimento del report');
            $table->string('riga')->nullable()->comment('riga');
            $table->string('prodotto_creditizio')->nullable()->comment('PRODOTTO/O CREDITIZIO OGGETTO DELLA CONVENZIONE');
            $table->string('abi_name')->nullable()->comment('Nome della banca (codice ABI)');
            $table->string('submission_type')->nullable()->comment('Tipo di invio previsto dalla segnalazione OAM');
            $table->integer('intermediari_convenzionati')->default(0)->comment('N° intermediari convenzionati');
            $table->integer('intermediari_non_convenzionati')->default(0)->comment('N° intermediari NON convenzionati');
            $table->integer('pratiche_intermediate')->default(0)->comment('N° Pratiche intermediate per prodotto/servizio');
            $table->integer('pratiche_lavorazione')->default(0)->comment('N° Pratiche di finanziamento in lavorazione');
            $table->decimal('erogato_lordo', 15, 2)->default(0.00)->comment('Montante lordo / Importo erogato per prodotto');
            $table->decimal('erogato_lavorazione', 15, 2)->default(0.00)->comment('Valore delle pratiche di finanziamento in lavorazione');
            $table->decimal('provv_clientela', 15, 2)->default(0.00)->comment('TOTALE PROVVIGIONI RICONOSCIUTE DALLA CLIENTELA');
            $table->decimal('provv_istituto_comp', 15, 2)->default(0.00)->comment('TOTALE PROVVIGIONI RICONOSCIUTE DALL ISTITUTO EROGANTE');
            $table->decimal('premi_istituto_comp', 15, 2)->default(0.00)->comment('TOTALE PREMI RICONOSCIUTI DALL ISTITUTO EROGANTE');
            $table->decimal('payin_ass_banche', 15, 2)->default(0.00)->comment('(PAY-IN) PROVV. ASSICURATIVE - da banche/intermediari');
            $table->decimal('payin_ass_broker', 15, 2)->default(0.00)->comment('(PAY-IN) PROVV. ASSICURATIVE - da Broker');
            $table->decimal('payin_ass_broker_cap', 15, 2)->default(0.00)->comment('(PAY-IN) PROVV. ASSICURATIVE - da Broker Captive');
            $table->decimal('payout_rete_credito', 15, 2)->default(0.00)->comment('AMMONTARE PROVVIGIONI RETE - INTERMEDIAZIONE CREDITO');
            $table->decimal('payout_rete_ass_banche', 15, 2)->default(0.00)->comment('(PAY-OUT) PROVV. RETE ASSICURATIVA - da banche');
            $table->decimal('payout_rete_ass_broker', 15, 2)->default(0.00)->comment('(PAY-OUT) PROVV. RETE ASSICURATIVA - da Broker');
            $table->decimal('payout_rete_ass_broker_cap', 15, 2)->default(0.00)->comment('(PAY-OUT) PROVV. RETE ASSICURATIVA - da Broker Captive');
            $table->integer('num_rivalse')->default(0)->comment('N° RIVALSE AI SENSI DELL ART. 125 - SEXIES, DEL TUB');
            $table->decimal('importo_retrocesse', 15, 2)->default(0.00)->comment('AMMONTARE DELLE PROVVIGIONI RETROCESSE AL FINANZIATORE');
            $table->string('pratica')->nullable()->comment('Codice pratica');
            $table->string('istituto')->nullable()->comment('Mandataria fatturante');
            $table->string('agente')->nullable()->comment('Agente');
            $table->string('cliente')->nullable()->comment('Cliente');
            $table->string('tipo_prodotto')->nullable()->comment('Tipo finanziamento');
            $table->string('principal_type')->nullable()->comment('Tipo istituto broker / captive');
            $table->dateTime('sended_at')->nullable()->comment('Data invio');
            $table->dateTime('approved_at')->nullable()->comment('Data delibera');
            $table->dateTime('erogated_at')->nullable()->comment('Data erogazione');
            $table->dateTime('rejected_at')->nullable()->comment('Data respinto');
            $table->dateTime('storned_at')->nullable()->comment('Data storno');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->timestamp('deleted_at')->nullable()->comment('Data e ora di eliminazione logica (soft delete); nullo = record attivo');
            $table->index(['period'], 'oam_pratiches_period_index');
            $table->comment('Dati aggregati per il report OAM: Profilo Economico/Operativo Base');
        });

        Schema::create('oam_semestrales', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('company_id')->nullable()->comment('ID Azienda (Tenant)');
            $table->char('period', 8)->nullable()->comment('Anno e mese di riferimento del report');
            $table->string('numero_iscrizione_m510')->nullable()->comment('Numero iscrizione (M510)');
            $table->string('prodotto_creditizio')->nullable()->comment('PRODOTTO/O CREDITIZIO OGGETTO DELLA CONVENZIONE');
            $table->string('abi_name')->nullable()->comment('Nome della banca (codice ABI)');
            $table->string('submission_type')->nullable()->comment('Tipo di invio previsto dalla segnalazione OAM');
            $table->string('gestione')->nullable()->comment('Tipo di gestione della segnalazione semestrale');
            $table->integer('intermediari_convenzionati')->default(0)->comment('N° intermediari convenzionati');
            $table->integer('intermediari_non_convenzionati')->default(0)->comment('N° intermediari NON convenzionati');
            $table->integer('pratiche_intermediate')->default(0)->comment('N° Pratiche intermediate per prodotto/servizio');
            $table->integer('pratiche_lavorazione')->default(0)->comment('N° Pratiche di finanziamento in lavorazione');
            $table->decimal('erogato_lordo', 15, 2)->default(0.00)->comment('Montante lordo / Importo erogato per prodotto');
            $table->decimal('erogato_lavorazione', 15, 2)->default(0.00)->comment('Valore delle pratiche di finanziamento in lavorazione');
            $table->decimal('provv_clientela', 15, 2)->default(0.00)->comment('TOTALE PROVVIGIONI RICONOSCIUTE DALLA CLIENTELA');
            $table->decimal('provv_istituto_comp', 15, 2)->default(0.00)->comment('TOTALE PROVVIGIONI RICONOSCIUTE DALL ISTITUTO EROGANTE');
            $table->decimal('premi_istituto_comp', 15, 2)->default(0.00)->comment('TOTALE PREMI RICONOSCIUTI DALL ISTITUTO EROGANTE');
            $table->decimal('payin_ass_banche', 15, 2)->default(0.00)->comment('(PAY-IN) PROVV. ASSICURATIVE - da banche/intermediari');
            $table->decimal('payin_ass_broker', 15, 2)->default(0.00)->comment('(PAY-IN) PROVV. ASSICURATIVE - da Broker');
            $table->decimal('payin_ass_broker_cap', 15, 2)->default(0.00)->comment('(PAY-IN) PROVV. ASSICURATIVE - da Broker Captive');
            $table->decimal('payout_rete_credito', 15, 2)->default(0.00)->comment('AMMONTARE PROVVIGIONI RETE - INTERMEDIAZIONE CREDITO');
            $table->decimal('payout_rete_ass_banche', 15, 2)->default(0.00)->comment('(PAY-OUT) PROVV. RETE ASSICURATIVA - da banche');
            $table->decimal('payout_rete_ass_broker', 15, 2)->default(0.00)->comment('(PAY-OUT) PROVV. RETE ASSICURATIVA - da Broker');
            $table->decimal('payout_rete_ass_broker_cap', 15, 2)->default(0.00)->comment('(PAY-OUT) PROVV. RETE ASSICURATIVA - da Broker Captive');
            $table->integer('num_rivalse')->default(0)->comment('N° RIVALSE AI SENSI DELL ART. 125 - SEXIES, DEL TUB');
            $table->decimal('importo_retrocesse', 15, 2)->default(0.00)->comment('AMMONTARE DELLE PROVVIGIONI RETROCESSE AL FINANZIATORE');
            $table->boolean('is_convenzione')->default(true)->comment('Convenzione SI/NO');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->index(['period'], 'oam_semestrales_period_index');
            $table->comment('Dati aggregati per il report OAM: Profilo Economico/Operativo Base');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oam_semestrales');
        Schema::dropIfExists('oam_pratiches');
        Schema::dropIfExists('pratica_status_history');
        Schema::dropIfExists('pratica_clients');
        Schema::dropIfExists('pratiches');
        Schema::dropIfExists('pratica_stati_transizioni');
        Schema::dropIfExists('pratica_stati');
    }
};
