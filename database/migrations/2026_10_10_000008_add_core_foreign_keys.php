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
     * Vincoli di integrità tra le tabelle core, aggiunti dopo la creazione di tutte le tabelle.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('parent_id')->references('id')->on('companies')->nullOnDelete();
        });

        Schema::table('company_modules', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('module_id')->references('id')->on('modules')->cascadeOnDelete();
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::table('company_roles', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
        });

        Schema::table('socialite_users', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('employee_type_permissions', function (Blueprint $table) {
            $table->foreign('employee_type_id')->references('id')->on('employee_types')->cascadeOnDelete();
            $table->foreign('resource_id')->references('id')->on('resources')->cascadeOnDelete();
        });

        Schema::table('employee_type_resource_presets', function (Blueprint $table) {
            $table->foreign('employee_type_id')->references('id')->on('employee_types')->cascadeOnDelete();
            $table->foreign('resource_id')->references('id')->on('resources')->cascadeOnDelete();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('coordinated_by_id')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('employer_id')->references('id')->on('clients')->nullOnDelete();
            $table->foreign('client_type_id')->references('id')->on('client_types')->nullOnDelete();
            $table->foreign('leadsource_id')->references('id')->on('lead_sources')->nullOnDelete();
            $table->foreign('blacklisted_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('legal_representative_id')->references('id')->on('clients')->nullOnDelete();
        });

        Schema::table('client_relations', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
            $table->foreign('client_type_id')->references('id')->on('client_types')->nullOnDelete();
        });

        Schema::table('client_mandates', function (Blueprint $table) {
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });

        Schema::table('clientis', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('customertype_id')->references('id')->on('client_types')->nullOnDelete();
        });

        Schema::table('fornitoris', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('fornitorirole_id')->references('id')->on('fornitoriroles')->nullOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });

        Schema::table('clienti_oam', function (Blueprint $table) {
            $table->foreign('oam_code_id')->references('id')->on('oam_codes')->nullOnDelete();
        });

        Schema::table('blacklist_clienti_fornitori', function (Blueprint $table) {
        });

        Schema::table('blacklist_clienti_employees', function (Blueprint $table) {
            $table->foreign('cliente_id')->references('id')->on('clientis')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });

        Schema::table('addresses', function (Blueprint $table) {
            $table->foreign('address_type_id')->references('id')->on('address_types')->nullOnDelete();
        });

        Schema::table('tipoprodotto_sub', function (Blueprint $table) {
            $table->foreign('tipoprodotto_id')->references('id')->on('tipoprodotto')->cascadeOnDelete();
        });

        Schema::table('tipoprodotto_sub_constraints', function (Blueprint $table) {
            $table->foreign('tipoprodotto_id')->references('id')->on('tipoprodotto')->nullOnDelete();
            $table->foreign('tipoprodotto_sub_id')->references('id')->on('tipoprodotto_sub')->nullOnDelete();
            $table->foreign('role_id')->references('id')->on('fornitoriroles')->nullOnDelete();
        });

        Schema::table('provvigioni_rules', function (Blueprint $table) {
            $table->foreign('tipoprodotto_id')->references('id')->on('tipoprodotto')->nullOnDelete();
            $table->foreign('tipoprodotto_sub_id')->references('id')->on('tipoprodotto_sub')->nullOnDelete();
            $table->foreign('kind_id')->references('id')->on('fornitoriroles')->nullOnDelete();
        });

        Schema::table('pratica_stati_transizioni', function (Blueprint $table) {
            $table->foreign('stato_da_id')->references('id')->on('pratica_stati')->cascadeOnDelete();
            $table->foreign('stato_a_id')->references('id')->on('pratica_stati')->cascadeOnDelete();
        });

        Schema::table('pratiches', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('pratica_stato_id')->references('id')->on('pratica_stati')->nullOnDelete();
            $table->foreign('tipoprodotto_id')->references('id')->on('tipoprodotto')->nullOnDelete();
            $table->foreign('fornitori_id')->references('id')->on('fornitoris')->nullOnDelete();
            $table->foreign('clienti_id')->references('id')->on('clientis')->nullOnDelete();
        });

        Schema::table('pratica_clients', function (Blueprint $table) {
            $table->foreign('pratica_id')->references('id')->on('pratiches')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });

        Schema::table('pratica_status_history', function (Blueprint $table) {
            $table->foreign('pratica_id')->references('id')->on('pratiches')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('oam_pratiches', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
        });

        Schema::table('oam_semestrales', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
        });

        Schema::table('document_types', function (Blueprint $table) {
            $table->foreign('renewed_by_id')->references('id')->on('document_types')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('document_type_id')->references('id')->on('document_types')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('renewed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('replaced_by_id')->references('id')->on('documents')->nullOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('verified_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('document_requirements', function (Blueprint $table) {
            $table->foreign('tipoprodotto_id')->references('id')->on('tipoprodotto')->nullOnDelete();
            $table->foreign('tipoprodotto_sub_id')->references('id')->on('tipoprodotto_sub')->nullOnDelete();
            $table->foreign('document_type_id')->references('id')->on('document_types')->cascadeOnDelete();
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
        });

        Schema::table('document_requests', function (Blueprint $table) {
            $table->foreign('document_type_id')->references('id')->on('document_types')->restrictOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('document_id')->references('id')->on('documents')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('document_analyses', function (Blueprint $table) {
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('signature_requests', function (Blueprint $table) {
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('signature_request_signers', function (Blueprint $table) {
            $table->foreign('signature_request_id')->references('id')->on('signature_requests')->cascadeOnDelete();
        });

        Schema::table('pdf_modules', function (Blueprint $table) {
            $table->foreign('document_type_id')->references('id')->on('document_types')->nullOnDelete();
        });

        Schema::table('pdf_module_fields', function (Blueprint $table) {
            $table->foreign('pdf_module_id')->references('id')->on('pdf_modules')->cascadeOnDelete();
        });

        Schema::table('document_reminders', function (Blueprint $table) {
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
        });

        Schema::table('document_schedules', function (Blueprint $table) {
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('tasks')->cascadeOnDelete();
        });

        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
        });

        Schema::table('incoming_emails', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('mail_account_id')->references('id')->on('mail_accounts')->nullOnDelete();
        });

        Schema::table('quality_reviews', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('reviewer_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('audits', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('quality_review_id')->references('id')->on('quality_reviews')->nullOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
        });

        Schema::table('audit_findings', function (Blueprint $table) {
            $table->foreign('audit_id')->references('id')->on('audits')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('remediation_id')->references('id')->on('remediations')->nullOnDelete();
        });

        Schema::table('audit_checklist_evaluations', function (Blueprint $table) {
            $table->foreign('audit_id')->references('id')->on('audits')->cascadeOnDelete();
            $table->foreign('verified_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('complaints', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::table('complaint_registry', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::table('suspicious_activity_reports', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::table('training_records', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['updated_by']);
            $table->dropForeign(['deleted_by']);
            $table->dropForeign(['parent_id']);
        });

        Schema::table('company_modules', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['module_id']);
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });

        Schema::table('company_roles', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });

        Schema::table('socialite_users', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('employee_type_permissions', function (Blueprint $table) {
            $table->dropForeign(['employee_type_id']);
            $table->dropForeign(['resource_id']);
        });

        Schema::table('employee_type_resource_presets', function (Blueprint $table) {
            $table->dropForeign(['employee_type_id']);
            $table->dropForeign(['resource_id']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['user_id']);
            $table->dropForeign(['branch_id']);
            $table->dropForeign(['coordinated_by_id']);
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropForeign(['deleted_by']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['employer_id']);
            $table->dropForeign(['client_type_id']);
            $table->dropForeign(['leadsource_id']);
            $table->dropForeign(['blacklisted_by']);
            $table->dropForeign(['user_id']);
            $table->dropForeign(['legal_representative_id']);
        });

        Schema::table('client_relations', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['client_id']);
            $table->dropForeign(['client_type_id']);
        });

        Schema::table('client_mandates', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
        });

        Schema::table('clientis', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['customertype_id']);
        });

        Schema::table('fornitoris', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['company_id']);
            $table->dropForeign(['fornitorirole_id']);
            $table->dropForeign(['branch_id']);
        });

        Schema::table('clienti_oam', function (Blueprint $table) {
            $table->dropForeign(['oam_code_id']);
        });

        Schema::table('blacklist_clienti_fornitori', function (Blueprint $table) {
        });

        Schema::table('blacklist_clienti_employees', function (Blueprint $table) {
            $table->dropForeign(['cliente_id']);
            $table->dropForeign(['employee_id']);
        });

        Schema::table('addresses', function (Blueprint $table) {
            $table->dropForeign(['address_type_id']);
        });

        Schema::table('tipoprodotto_sub', function (Blueprint $table) {
            $table->dropForeign(['tipoprodotto_id']);
        });

        Schema::table('tipoprodotto_sub_constraints', function (Blueprint $table) {
            $table->dropForeign(['tipoprodotto_id']);
            $table->dropForeign(['tipoprodotto_sub_id']);
            $table->dropForeign(['role_id']);
        });

        Schema::table('provvigioni_rules', function (Blueprint $table) {
            $table->dropForeign(['tipoprodotto_id']);
            $table->dropForeign(['tipoprodotto_sub_id']);
            $table->dropForeign(['kind_id']);
        });

        Schema::table('pratica_stati_transizioni', function (Blueprint $table) {
            $table->dropForeign(['stato_da_id']);
            $table->dropForeign(['stato_a_id']);
        });

        Schema::table('pratiches', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['pratica_stato_id']);
            $table->dropForeign(['tipoprodotto_id']);
            $table->dropForeign(['fornitori_id']);
            $table->dropForeign(['clienti_id']);
        });

        Schema::table('pratica_clients', function (Blueprint $table) {
            $table->dropForeign(['pratica_id']);
            $table->dropForeign(['client_id']);
        });

        Schema::table('pratica_status_history', function (Blueprint $table) {
            $table->dropForeign(['pratica_id']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('oam_pratiches', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });

        Schema::table('oam_semestrales', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });

        Schema::table('document_types', function (Blueprint $table) {
            $table->dropForeign(['renewed_by_id']);
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropForeign(['deleted_by']);
            $table->dropForeign(['company_id']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['document_type_id']);
            $table->dropForeign(['user_id']);
            $table->dropForeign(['renewed_by']);
            $table->dropForeign(['replaced_by_id']);
            $table->dropForeign(['uploaded_by']);
            $table->dropForeign(['verified_by']);
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropForeign(['deleted_by']);
        });

        Schema::table('document_requirements', function (Blueprint $table) {
            $table->dropForeign(['tipoprodotto_id']);
            $table->dropForeign(['tipoprodotto_sub_id']);
            $table->dropForeign(['document_type_id']);
            $table->dropForeign(['task_id']);
        });

        Schema::table('document_requests', function (Blueprint $table) {
            $table->dropForeign(['document_type_id']);
            $table->dropForeign(['company_id']);
            $table->dropForeign(['document_id']);
            $table->dropForeign(['created_by']);
        });

        Schema::table('document_analyses', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
            $table->dropForeign(['created_by']);
        });

        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
            $table->dropForeign(['requested_by']);
        });

        Schema::table('signature_request_signers', function (Blueprint $table) {
            $table->dropForeign(['signature_request_id']);
        });

        Schema::table('pdf_modules', function (Blueprint $table) {
            $table->dropForeign(['document_type_id']);
        });

        Schema::table('pdf_module_fields', function (Blueprint $table) {
            $table->dropForeign(['pdf_module_id']);
        });

        Schema::table('document_reminders', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
        });

        Schema::table('document_schedules', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
        });

        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });

        Schema::table('incoming_emails', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['mail_account_id']);
        });

        Schema::table('quality_reviews', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['reviewer_user_id']);
        });

        Schema::table('audits', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['quality_review_id']);
            $table->dropForeign(['organization_id']);
        });

        Schema::table('audit_findings', function (Blueprint $table) {
            $table->dropForeign(['audit_id']);
            $table->dropForeign(['company_id']);
            $table->dropForeign(['remediation_id']);
        });

        Schema::table('audit_checklist_evaluations', function (Blueprint $table) {
            $table->dropForeign(['audit_id']);
            $table->dropForeign(['verified_by']);
        });

        Schema::table('complaints', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });

        Schema::table('complaint_registry', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });

        Schema::table('suspicious_activity_reports', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });

        Schema::table('training_records', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['user_id']);
        });
    }
};
