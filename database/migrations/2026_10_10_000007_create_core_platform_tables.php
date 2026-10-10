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
     * Log attività e chat AI.
     */
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->string('log_name')->nullable()->comment('Nome del registro');
            $table->text('description')->comment('Descrizione dell\'evento');
            $table->string('subject_type')->nullable()->comment('Alias del modello su cui è avvenuta l\'azione (morph map)');
            $table->unsignedBigInteger('subject_id')->nullable()->comment('ID del record su cui è avvenuta l\'azione');
            $table->index(['subject_type', 'subject_id']);
            $table->string('event')->nullable()->comment('Tipo di evento (created, updated, deleted, ...)');
            $table->string('causer_type')->nullable()->comment('Alias del modello che ha compiuto l\'azione (morph map)');
            $table->unsignedBigInteger('causer_id')->nullable()->comment('ID di chi ha compiuto l\'azione');
            $table->index(['causer_type', 'causer_id']);
            $table->json('attribute_changes')->nullable()->comment('Valori prima e dopo la modifica (JSON)');
            $table->json('properties')->nullable()->comment('Proprietà aggiuntive dell\'evento (JSON)');
            $table->unsignedTinyInteger('risk_score')->nullable()->comment('Punteggio di rischio dell\'evento');
            $table->string('risk_level', 16)->nullable()->comment('Livello di rischio dell\'evento');
            $table->boolean('retention_hold')->default(false)->comment('Blocco della cancellazione periodica');
            $table->char('integrity_hash', 64)->nullable()->comment('Impronta per verificare che il record non sia stato alterato');
            $table->string('request_id', 100)->nullable()->comment('ID della richiesta HTTP che ha generato l\'evento');
            $table->string('ip_address', 45)->nullable()->comment('Indirizzo IP di chi ha compiuto l\'azione');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->index(['log_name'], 'activity_log_log_name_index');
            $table->index(['risk_score'], 'activity_log_risk_score_index');
            $table->index(['risk_level'], 'activity_log_risk_level_index');
            $table->index(['retention_hold'], 'activity_log_retention_hold_index');
            $table->index(['integrity_hash'], 'activity_log_integrity_hash_index');
            $table->index(['log_name', 'created_at'], 'activity_log_log_created_index');
            $table->index(['event', 'created_at'], 'activity_log_event_created_index');
            $table->index(['subject_type', 'subject_id', 'created_at'], 'activity_log_subject_created_index');
            $table->index(['causer_type', 'causer_id', 'created_at'], 'activity_log_causer_created_index');
            $table->index(['risk_level', 'created_at'], 'activity_log_risk_created_index');
            $table->index(['request_id', 'created_at'], 'activity_log_request_created_index');
            $table->index(['ip_address', 'created_at'], 'activity_log_ip_created_index');
            $table->comment('Registro delle attività: chi ha fatto cosa su quale record, con rischio e impronta di integrità');
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id()->comment('ID intero autoincrementante');
            $table->foreignId('company_id')->nullable()->comment('Azienda (tenant) proprietaria del record');
            $table->foreignId('user_id')->nullable()->comment('Utente collegato al record');
            $table->string('thread_id')->comment('Conversazione a cui appartiene il messaggio');
            $table->string('role')->comment('Autore del messaggio (user, assistant)');
            $table->json('content')->nullable()->comment('Testo del messaggio');
            $table->json('meta')->nullable()->comment('Dati aggiuntivi del messaggio (JSON)');
            $table->timestamp('created_at')->nullable()->comment('Data e ora di creazione del record');
            $table->timestamp('updated_at')->nullable()->comment('Data e ora dell\'ultima modifica del record');
            $table->index(['thread_id', 'id'], 'chat_messages_thread_id_id_index');
            $table->index(['thread_id'], 'chat_messages_thread_id_index');
            $table->comment('Messaggi delle conversazioni con l\'assistente AI (NeuronAI)');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('activity_log');
    }
};
