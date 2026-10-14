<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Siti web e landing page, collegati in modo polimorfico a company, fornitore, client o cliente (banca).
 * Migration scritta a mano (le altre sono generate da tools/schema-merge): sta dopo quella dei vincoli perché ha una chiave esterna.
 */
return new class extends Migration
{
    /**
     * Connessione del database core, configurabile in config/unico-core.php.
     */
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table) {
            $table->id()->comment('ID univoco del sito');
            $table->foreignId('company_id')->nullable()->comment('Azienda (tenant) proprietaria del sito')
                ->constrained('companies')->nullOnDelete();
            $table->string('name')->comment('Nome del sito');
            $table->string('type')->nullable()->comment('Tipologia sito (es. vetrina, e-commerce, landing)');
            $table->string('clienti_id', 36)->nullable()->comment('Mandante di riferimento (banca/ente): id o UUID di un database esterno, senza vincolo');
            $table->boolean('is_active')->default(true)->comment('Stato di attivazione del sito (1 = attivo, 0 = inattivo)');
            $table->string('domain')->comment('Dominio o sottodominio principale (es. www.races.it)');
            $table->boolean('is_typical')->default(true)->comment('Sito usato per l\'attività tipica aziendale (1 = sì, 0 = no)');
            $table->date('privacy_date')->nullable()->comment('Data dell\'ultimo aggiornamento della privacy policy');
            $table->date('transparency_date')->nullable()->comment('Data dell\'ultimo aggiornamento della trasparenza');
            $table->date('privacy_prior_date')->nullable()->comment('Data del precedente aggiornamento della privacy policy');
            $table->date('transparency_prior_date')->nullable()->comment('Data del precedente aggiornamento della trasparenza');
            $table->string('url_privacy')->nullable()->comment('URL della pagina privacy policy');
            $table->string('url_cookies')->nullable()->comment('URL della pagina cookie policy');
            $table->boolean('is_footercompilant')->default(false)->comment('Il footer contiene i dati legali ed è conforme (1 = sì)');
            $table->string('url_transparency')->nullable()->comment('URL della pagina di trasparenza / dati societari');
            $table->boolean('is_iso27001_certified')->default(false)->comment('Il sito risiede su infrastruttura certificata ISO 27001 (1 = sì)');
            $table->string('websiteable_type')->comment('Alias del modello proprietario del sito (morph map)');
            $table->string('websiteable_id', 36)->comment('ID del proprietario: intero (core) o UUID/codice se letto da un database esterno');
            $table->index(['websiteable_type', 'websiteable_id']);
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->timestamp('deleted_at')->nullable()->comment('Data e ora di eliminazione logica');
            $table->index('is_active', 'websites_is_active_index');
            $table->index('domain', 'websites_domain_index');
            $table->comment('Registro dei siti web e delle landing page, con il tracciamento della conformità legale');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('websites');
    }
};
