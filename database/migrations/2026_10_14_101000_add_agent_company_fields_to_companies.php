<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campi di companies usati da unicoagent e assenti nel core condiviso.
 * Confrontati su: proforma_core, unicoagent, unicobpm, unicofinance,
 * unicoloan_core, unicooam_core rispetto a unicosuite.
 */
return new class extends Migration
{
    public function getConnection()
    {
        return config('unico-core.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->table('companies', function (Blueprint $table) use ($schema) {
            if (! $schema->hasColumn('companies', 'type')) {
                $table->string('type', 20)->default('FINANCE')->after('name')
                    ->comment('Settore operativo (unicoagent): FINANCE, CALL CENTER, HOTEL');
            }
            if (! $schema->hasColumn('companies', 'address')) {
                $table->string('address')->nullable()->after('name')
                    ->comment('Indirizzo della sede');
            }
            if (! $schema->hasColumn('companies', 'logo')) {
                $table->string('logo')->nullable()->after('name')
                    ->comment('Percorso o URL del logo aziendale');
            }
            if (! $schema->hasColumn('companies', 'whatsapp_number')) {
                $table->string('whatsapp_number', 30)->nullable()->after('email')
                    ->comment('Numero WhatsApp della company (canale agent)');
            }
            if (! $schema->hasColumn('companies', 'customer_care_phone')) {
                $table->string('customer_care_phone', 40)->nullable()->after('email')
                    ->comment('Telefono customer care');
            }
            if (! $schema->hasColumn('companies', 'customer_care_email')) {
                $table->string('customer_care_email')->nullable()->after('email')
                    ->comment('Email customer care');
            }
            if (! $schema->hasColumn('companies', 'istruttoria_email')) {
                $table->string('istruttoria_email')->nullable()->after('email')
                    ->comment('Email istruttoria (riceve pratiche e allegati)');
            }
            if (! $schema->hasColumn('companies', 'dpo_email')) {
                $table->string('dpo_email')->nullable()->after('email')
                    ->comment('Email del DPO, se nominato');
            }
            if (! $schema->hasColumn('companies', 'url_preventivatore')) {
                $table->string('url_preventivatore')->nullable()->after('call_last_activation')
                    ->comment('URL CRM preventivatore');
            }
            if (! $schema->hasColumn('companies', 'preventivatore_passkey')) {
                $table->text('preventivatore_passkey')->nullable()->after('call_last_activation')
                    ->comment('Passkey del preventivatore (cifrata)');
            }
            if (! $schema->hasColumn('companies', 'url_istruttoria')) {
                $table->string('url_istruttoria')->nullable()->after('call_last_activation')
                    ->comment('URL CRM istruttoria');
            }
            if (! $schema->hasColumn('companies', 'istruttoria_passkey')) {
                $table->text('istruttoria_passkey')->nullable()->after('call_last_activation')
                    ->comment('Passkey dell\'istruttoria (cifrata)');
            }
            if (! $schema->hasColumn('companies', 'crm_driver')) {
                $table->string('crm_driver', 30)->nullable()->after('call_last_activation')
                    ->comment('Driver CRM istruttoria (mediafacile, unicoloan, generic, email)');
            }
            if (! $schema->hasColumn('companies', 'crm_config')) {
                $table->text('crm_config')->nullable()->after('call_last_activation')
                    ->comment('Configurazione CRM aggiuntiva (JSON cifrato)');
            }
            if (! $schema->hasColumn('companies', 'retention_perfected')) {
                $table->text('retention_perfected')->nullable()->after('aibackground')
                    ->comment('Testo retention per pratiche perfezionate (privacy)');
            }
            if (! $schema->hasColumn('companies', 'is_trial')) {
                $table->boolean('is_trial')->default(false)->after('aibackground')
                    ->comment('Company in periodo di prova');
            }
            if (! $schema->hasColumn('companies', 'trial_activated_at')) {
                $table->date('trial_activated_at')->nullable()->after('aibackground')
                    ->comment('Data di attivazione della prova');
            }
            if (! $schema->hasColumn('companies', 'trialend_at')) {
                $table->date('trialend_at')->nullable()->after('aibackground')
                    ->comment('Fine del periodo di prova');
            }
            if (! $schema->hasColumn('companies', 'activated_at')) {
                $table->date('activated_at')->nullable()->after('aibackground')
                    ->comment('Data di attivazione del contratto');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $columns = [
            'type', 'address', 'logo', 'whatsapp_number', 'customer_care_phone', 'customer_care_email',
            'istruttoria_email', 'dpo_email', 'url_preventivatore', 'preventivatore_passkey',
            'url_istruttoria', 'istruttoria_passkey', 'crm_driver', 'crm_config', 'retention_perfected',
            'is_trial', 'trial_activated_at', 'trialend_at', 'activated_at',
        ];

        $existing = array_values(array_filter($columns, fn (string $column) => $schema->hasColumn('companies', $column)));

        if ($existing === []) {
            return;
        }

        $schema->table('companies', function (Blueprint $table) use ($existing) {
            $table->dropColumn($existing);
        });
    }
};
