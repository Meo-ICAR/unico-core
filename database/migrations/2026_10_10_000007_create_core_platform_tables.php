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
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->unsignedTinyInteger('risk_score')->nullable();
            $table->string('risk_level', 16)->nullable();
            $table->boolean('retention_hold')->default(false);
            $table->char('integrity_hash', 64)->nullable();
            $table->string('request_id', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
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
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->string('thread_id');
            $table->string('role');
            $table->json('content')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
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
