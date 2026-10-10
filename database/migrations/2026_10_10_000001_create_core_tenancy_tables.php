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
     * Aziende, utenti, permessi, dipendenti.
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Ragione Sociale dell\'azienda');
            $table->string('vat_number', 50)->nullable()->comment('Partita IVA');
            $table->string('vat_name', 50)->nullable()->comment('Intestazione Partita IVA');
            $table->string('oam', 50)->nullable()->comment('Numero di iscrizione OAM (Organismo Agenti e Mediatori)');
            $table->date('oam_at')->nullable()->comment('Data di iscrizione all\'albo OAM');
            $table->string('oam_name')->nullable()->comment('Denominazione utilizzata per l\'iscrizione OAM');
            $table->string('numero_iscrizione_rui')->nullable()->comment('Numero di iscrizione al RUI (Registro Unico Intermediari)');
            $table->string('ivass', 30)->nullable()->comment('Codice o numero di iscrizione IVASS');
            $table->date('ivass_at')->nullable()->comment('Data di iscrizione all\'IVASS');
            $table->string('ivass_name')->nullable()->comment('Denominazione utilizzata per l\'iscrizione IVASS');
            $table->enum('ivass_section', ['A', 'B', 'C', 'D', 'E'])->nullable()->comment('Sezione di appartenenza del registro IVASS');
            $table->string('sponsor')->nullable()->comment('Azienda o ente sponsor associato');
            $table->enum('company_type', ['mediatore', 'call center', 'hotel', 'sw house'])->nullable()->comment('Tipologia di azienda (classificazione interna)');
            $table->text('page_header')->nullable()->comment('Codice HTML per l\'intestazione personalizzata delle pagine/documenti');
            $table->text('page_footer')->nullable()->comment('Codice HTML per il pié di pagina personalizzato delle pagine/documenti');
            $table->string('crm')->nullable()->comment('URL o identificativo CRM');
            $table->timestamp('crm_last_activation')->nullable();
            $table->string('callcenter')->nullable()->comment('URL o identificativo Callcenter');
            $table->timestamp('call_last_activation')->nullable();
            $table->foreignId('updated_by')->nullable()->comment('ID utente che ha aggiornato');
            $table->foreignId('deleted_by')->nullable()->comment('ID utente che ha cancellato');
            $table->string('email')->nullable()->comment('Email principale azienda');
            $table->string('email_cc')->nullable();
            $table->string('email_bcc')->nullable();
            $table->string('emailsubject')->nullable()->default('Proforma compensi provvigionali')->comment('Oggetto email predefinito per proforma');
            $table->text('compenso_descrizione')->nullable()->comment('Descrizione predefinita compenso');
            $table->longText('aibackground')->nullable();
            $table->string('db_host')->nullable();
            $table->string('db_port')->nullable()->default('3306');
            $table->string('db_database')->nullable();
            $table->string('db_username')->nullable();
            $table->text('db_password')->nullable();
            $table->string('drive_folder_id')->nullable()->comment('Cartella Google Drive della company');
            $table->timestamps();
            $table->softDeletes();
            $table->comment('Tenant: società di mediazione, call center, hotel, software house');
        });

        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('code')->comment('Codice mnemonico univoco del modulo (es. UNICOBPM)');
            $table->string('name')->comment('Nome commerciale del modulo');
            $table->string('description')->nullable()->comment('Descrizione della funzione del modulo');
            $table->boolean('is_active')->default(true)->comment('Modulo attualmente commercializzabile');
            $table->timestamps();
            $table->unique(['code'], 'modules_code_unique');
            $table->comment('Elenco dei moduli/prodotti disponibili nella suite');
        });

        Schema::create('company_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->comment('Azienda che ha acquistato il modulo');
            $table->foreignId('module_id');
            $table->string('plan_type')->nullable()->comment('Tipo di installazione/piano: BASE, MEDIUM, FULL');
            $table->date('trial_ends_at')->nullable()->comment('Data di termine del periodo di prova');
            $table->decimal('one_time_cost', 10, 2)->nullable()->comment('Costo una tantum di attivazione');
            $table->decimal('monthly_cost', 10, 2)->nullable()->comment('Costo ricorrente mensile');
            $table->string('billing_frequency')->nullable()->comment('Periodicità di fatturazione: mensile, trimestrale, semestrale, annuale');
            $table->date('last_invoice_at')->nullable()->comment('Data dell\'ultima fattura emessa');
            $table->date('last_payment_at')->nullable()->comment('Data dell\'ultimo incasso ricevuto');
            $table->timestamps();
            $table->unique(['company_id', 'module_id'], 'company_modules_company_id_module_id_unique');
            $table->comment('Moduli acquistati da ciascuna company, con piano e dati di fatturazione');
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id()->comment('ID autoincrementale univoco della filiale');
            $table->foreignId('company_id')->comment('ID della company principale (FK su companies)');
            $table->string('name')->comment('Nome della filiale (es. Sede Milano, Ufficio Roma)');
            $table->string('address')->nullable()->comment('Via/Piazza della sede');
            $table->string('street_number', 20)->nullable()->comment('Numero civico della sede');
            $table->string('city', 100)->nullable()->comment('Città della sede');
            $table->string('zip_code', 10)->nullable()->comment('CAP della sede');
            $table->string('province', 100)->nullable()->comment('Provincia della sede (nome esteso)');
            $table->string('region', 100)->nullable()->comment('Regione della sede');
            $table->morphs('branchable');
            $table->boolean('is_main_office')->default(false)->comment('Indica se è la Sede Legale/Operativa principale (1 = Sì, 0 = No)');
            $table->boolean('is_active')->default(true)->comment('Indica se la filiale è attiva (1 = Sì, 0 = No)');
            $table->string('manager_first_name', 100)->nullable()->comment('Nome del responsabile della filiale');
            $table->string('manager_last_name', 100)->nullable()->comment('Cognome del responsabile della filiale');
            $table->string('manager_tax_code', 16)->nullable()->comment('Codice Fiscale del responsabile della filiale');
            $table->date('founded_at')->nullable()->comment('Data apertura della filiale');
            $table->date('dismissed_at')->nullable()->comment('Data chiusura della filiale');
            $table->timestamps();
            $table->softDeletes();
            $table->comment('Filiali o sedi collegate alle aziende o polimorficamente ad altri modelli (Hotel, Call Center, ecc.)');
        });

        Schema::create('company_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->comment('ID di collegamento alla tabella companies (formato UUID/Char)');
            $table->string('name')->nullable()->comment('Nome o descrizione descrittiva dell\'ispezione');
            $table->string('funzione')->nullable()->comment('Funzione aziendale');
            $table->boolean('is_external')->default(false)->comment('Figura esterna');
            $table->date('dal')->nullable()->comment('Data di inizio del periodo di ispezione');
            $table->date('al')->nullable()->comment('Data di fine del periodo di ispezione');
            $table->string('execution_method')->nullable()->comment('Metodo di esecuzione: tramite documenti, non specificato o sul posto');
            $table->string('expertName')->nullable()->comment('Nome e cognome dell\'incaricato che esegue il controllo');
            $table->integer('n')->nullable()->comment('Numero identificativo interno o contatore progressivo');
            $table->timestamps();
            $table->comment('Funzioni aziendali e Audit previsti nel periodo');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role')->nullable()->default('user');
            $table->string('remember_token', 100)->nullable();
            $table->nullableMorphs('profile');
            $table->char('cf', 16)->nullable();
            $table->string('microsoft_token', 100)->nullable();
            $table->string('microsoft_refresh_token', 100)->nullable();
            $table->string('azure_id', 191)->nullable();
            $table->string('microsoft_id', 191)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['email'], 'users_email_unique');
            $table->unique(['azure_id'], 'users_azure_id_unique');
            $table->comment('Registro degli utenti del sistema');
        });

        Schema::create('socialite_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('provider');
            $table->string('provider_id');
            $table->string('email')->nullable();
            $table->string('avatar')->nullable();
            $table->boolean('is_personal')->default(false);
            $table->text('token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_id'], 'socialite_users_provider_provider_id_unique');
        });

        Schema::create('resources', function (Blueprint $table) {
            $table->id()->comment('ID univoco della risorsa.');
            $table->string('app_name')->comment('Applicazione di appartenenza della risorsa (es. CRM, PORTALE_OAM, FINANCE).');
            $table->string('key')->comment('Identificatore univoco della risorsa all\'interno dell\'app (es. employees).');
            $table->string('name')->comment('Nome leggibile della risorsa (es. Dipendenti).');
            $table->string('group')->nullable()->comment('Gruppo di menu a cui appartiene la risorsa (es. Anagrafiche).');
            $table->enum('min_plan', ['BASE', 'MEDIUM', 'FULL'])->default('BASE')->comment('Piano minimo richiesto per accedere alla risorsa.');
            $table->timestamps();
            $table->unique(['app_name', 'key'], 'res_app_key_unique');
            $table->comment('Risorse/funzionalità applicative disponibili, raggruppabili per app e piano.');
        });

        Schema::create('employee_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('icon')->nullable();
            $table->string('companytype')->nullable();
            $table->boolean('is_external')->nullable()->default(false);
            $table->timestamps();
        });

        Schema::create('employee_type_permissions', function (Blueprint $table) {
            $table->id()->comment('ID univoco del permesso.');
            $table->foreignId('employee_type_id')->comment('Tipologia di dipendente a cui è assegnato il permesso.');
            $table->foreignId('resource_id')->comment('Risorsa/funzionalità applicativa a cui si riferisce il permesso.');
            $table->enum('action', ['viewAny', 'view', 'create', 'update', 'delete'])->default('viewAny')->comment('Azione consentita sulla risorsa.');
            $table->timestamps();
            $table->unique(['employee_type_id', 'resource_id', 'action'], 'emp_type_perm_unique');
            $table->comment('Permessi assegnati a ciascuna tipologia di dipendente sulle risorse applicative.');
        });

        Schema::create('employee_type_resource_presets', function (Blueprint $table) {
            $table->id()->comment('ID univoco del preset.');
            $table->foreignId('employee_type_id')->comment('Ruolo (EmployeeType) a cui è assegnato il preset.');
            $table->foreignId('resource_id')->comment('Risorsa/procedura applicativa sbloccata per intero da questo preset.');
            $table->timestamps();
            $table->unique(['employee_type_id', 'resource_id'], 'emp_type_resource_preset_unique');
            $table->comment('Preset di accesso totale (tutte le azioni CRUD) per ruolo/risorsa, affiancati alla matrice granulare employee_type_permissions.');
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id()->comment('ID univoco interno del dipendente');
            $table->foreignId('company_id')->nullable()->comment('ID Tenant proprietario (FK su companies)');
            $table->foreignId('user_id')->nullable();
            $table->foreignId('branch_id')->nullable();
            $table->string('name')->nullable()->comment('Nome e Cognome del dipendente');
            $table->boolean('is_active')->default(true)->comment('Attivo');
            $table->string('role_title', 100)->nullable()->comment('Qualifica o titolo professionale');
            $table->string('cf')->nullable()->comment('Codice Fiscale del dipendente');
            $table->string('email')->nullable()->comment('Email aziendale/operatore');
            $table->string('pec')->nullable()->comment('PEC personale o aziendale dedicata');
            $table->string('phone')->nullable()->comment('Recapito telefonico / interno aziendale');
            $table->string('department', 100)->nullable()->comment('Dipartimento o area di appartenenza');
            $table->string('oam', 100)->nullable()->comment('Numero Iscrizione OAM');
            $table->date('oam_at')->nullable()->comment('Data iscrizione o rinnovo OAM');
            $table->string('oam_name', 100)->nullable()->comment('Denominazione o sezione specifica OAM');
            $table->string('numero_iscrizione_rui', 50)->nullable()->comment('Numero RUI (Registro Unico Intermediari Assicurativi)');
            $table->date('oam_dismissed_at')->nullable()->comment('Data di cessazione o revoca iscrizione OAM');
            $table->string('ivass', 100)->nullable()->comment('Numero Iscrizione IVASS');
            $table->date('hiring_date')->nullable()->comment('Data di assunzione o inizio collaborazione');
            $table->date('termination_date')->nullable()->comment('Data di cessazione del rapporto lavorativo');
            $table->foreignId('coordinated_by_id')->nullable()->comment('ID del responsabile/coordinatore diretto');
            $table->string('employee_types')->default('dipendente')->comment('Tipologia contrattuale');
            $table->json('employee_roles');
            $table->string('supervisor_type')->default('no')->comment('Livello di supervisione');
            $table->string('privacy_role')->nullable()->comment('Ruolo designato ai fini privacy');
            $table->text('purpose')->nullable()->comment('Finalità del trattamento dati affidato');
            $table->text('data_subjects')->nullable()->comment('Categorie di interessati gestiti');
            $table->text('data_categories')->nullable()->comment('Categorie di dati trattati');
            $table->string('retention_period')->nullable()->comment('Tempi di conservazione dei dati gestiti');
            $table->string('extra_eu_transfer')->nullable()->comment('Eventuale trasferimento dati fuori dall\'UE');
            $table->text('security_measures')->nullable()->comment('Misure di sicurezza tecniche e organizzative');
            $table->string('privacy_data')->nullable()->comment('Note accessorie o riferimenti a nomine esterne');
            $table->boolean('is_structure')->default(false)->comment('Indica se è un utente di struttura/backoffice');
            $table->boolean('is_external')->default(false);
            $table->boolean('is_ghost')->default(false)->comment('Utenza tecnica di sistema');
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->foreignId('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->comment('Anagrafica dipendenti e collaboratori commerciali con ruoli privacy, abilitazioni OAM/IVASS e gerarchie');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
        Schema::dropIfExists('employee_type_resource_presets');
        Schema::dropIfExists('employee_type_permissions');
        Schema::dropIfExists('employee_types');
        Schema::dropIfExists('resources');
        Schema::dropIfExists('socialite_users');
        Schema::dropIfExists('users');
        Schema::dropIfExists('company_roles');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('company_modules');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('companies');
    }
};
