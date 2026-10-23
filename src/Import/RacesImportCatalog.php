<?php

namespace Unico\Core\Import;

/**
 * Mappa dei dump storici Races (proforma_races, unicooam_races).
 *
 * Colonne rinominate (la sorgente non ha il nome della destinazione):
 * - document_types: is_endMonth→is_end_month, is_AiAbstract→is_ai_abstract,
 *   is_AiCheck→is_ai_check, AiPattern→ai_pattern, expire_days_before→notify_days_before (json)
 * - documents: is_endMonth→is_end_month. Non si copiano extracted_text (lo ricalcola l'analisi)
 *   né is_signed (la destinazione tiene signed_at / signed_by)
 * - mail_accounts: incoming_protocol→protocol, incoming_host→imap_host, incoming_port→imap_port,
 *   incoming_username→imap_username, incoming_password→imap_password, incoming_encryption→imap_encryption
 * - client_pratiches.pratiche_id→pratica_clients.pratica_id
 * - fornitoris.company_branch_id→branch_id solo se branch_id è vuoto
 * - pratiches_statos.stato_pratica→pratica_stati.name/codice; isrejected→is_rejected,
 *   isworking→is_working, isestingued→is_estingued
 * - pratiches.tipo_prodotto→tipoprodotto_id, stato_pratica→pratica_stato_id,
 *   partita_iva_agente→fornitori_id, denominazione_banca→clienti_id, id→codice_pratica
 * - provvigioni.id_pratica resta il codice; pratica_id (bigint) si valorizza solo se la pratica
 *   esiste nello stesso database di destinazione
 *
 * document_types: i tipi già presenti (stesso codice, slug o nome) non si aggiornano, si associano soltanto; quelli nuovi si inseriscono
 * (`insert_only`), così i documenti importati mantengono il tipo.
 *
 * proformas.invoiceable_* e provvigioni.proforma_id (keep_if_empty): li compila l'app (abbinamento alle fatture, proforma storici); un valore vuoto nella
 * sorgente non li azzera nell'aggiornamento orario.
 *
 * proformas e prima_nota_entries: la destinazione ha già altre righe con gli stessi id, quindi si inseriscono con id nuovo e il vecchio id va in `legacy_id`
 * (rilanciare l'import le ritrova, non le duplica).
 *
 * Gli id UUID di clientis, fornitoris e documents diventano legacy_id: la destinazione ha id intero.
 * Le company dei dump sono vuote: ogni company_id operativo punta alla company Races (nome / partita IVA).
 * Gli id numerici si ricordano per sorgente (proforma e oam possono riusare lo stesso intero);
 * gli UUID si cercano anche nell'altra sorgente.
 */
class RacesImportCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function tables(): array
    {
        $core = 'core';
        $proforma = 'proforma_src';
        $oam = 'oam_src';

        return [
            self::row($proforma, 'address_types', $core, company: false, preserve: true, match: [['id'], ['name']]),
            self::row($proforma, 'client_types', $core, company: false, preserve: true, match: [['id'], ['name']]),
            self::row($proforma, 'fornitoriroles', $core, company: false, preserve: true, match: [['id'], ['name']]),
            self::row($oam, 'employee_types', $core, company: false, preserve: true, match: [['id'], ['name']]),
            self::row($oam, 'modules', $core, company: false, preserve: true, match: [['id'], ['name']]),
            self::row($oam, 'resources', $core, company: false, preserve: true, match: [['id'], ['name']]),
            self::row($oam, 'organizations', $core, company: false, preserve: true, match: [['id'], ['name']]),
            self::row($proforma, 'users', $core, preserve: true, match: [['email'], ['id']], defaults: ['role' => 'employee', 'profile_type' => 'employee'], preserveOnUpdate: ['password', 'remember_token', 'microsoft_token', 'microsoft_refresh_token', 'role', 'profile_type', 'profile_id']),
            self::row($oam, 'users', $core, preserve: true, match: [['email'], ['id']], morph: ['profile_type', 'profile_id'], preserveOnUpdate: ['password', 'remember_token', 'role', 'profile_type', 'profile_id']),
            self::row($oam, 'company_roles', $core, preserve: true, match: [['id'], ['name']]),
            self::row($oam, 'company_modules', $core, preserve: true, match: [['id']]),
            self::row($oam, 'branches', $core, preserve: true, match: [['id'], ['name']], morph: ['branchable_type', 'branchable_id']),
            self::row($oam, 'employees', $core, preserve: true, match: [['id'], ['email'], ['cf']], fk: ['user_id' => 'users', 'branch_id' => 'branches']),
            self::row($proforma, 'socialite_users', $core, company: false, preserve: true, match: [['user_id', 'provider'], ['id']], fk: ['user_id' => 'users']),
            self::row($oam, 'socialite_users', $core, company: false, preserve: true, match: [['user_id', 'provider'], ['id']], fk: ['user_id' => 'users']),
            self::row($proforma, 'clientis', $core, legacy: true, match: [['legacy_id'], ['piva'], ['name']], fk: ['customertype_id' => 'client_types']),
            self::row($proforma, 'fornitoris', $core, legacy: true, match: [['legacy_id'], ['piva'], ['cf'], ['name']], fk: ['fornitorirole_id' => 'fornitoriroles', 'branch_id' => 'branches', 'user_id' => 'users'], prepare: 'prepareFornitore'),
            self::row($proforma, 'clients', $core, preserve: true, match: [['tax_code'], ['vat_number'], ['email'], ['id']], fk: ['user_id' => 'users', 'client_type_id' => 'client_types', 'blacklisted_by' => 'users'], selfFk: ['employer_id', 'legal_representative_id', 'leadsource_id']),
            self::row($proforma, 'addresses', $core, preserve: true, match: [['id']], fk: ['address_type_id' => 'address_types'], morph: ['addressable_type', 'addressable_id']),
            self::row($oam, 'clienti_oam', $core, preserve: true, match: [['clienti_id', 'oam_code_id'], ['id']], fk: ['clienti_id' => 'clientis']),
            self::row($proforma, 'tipoprodotto', $core, company: false, preserve: true, match: [['tipo_prodotto'], ['code'], ['id']]),
            self::row($proforma, 'tipoprodotto_sub', $core, company: false, preserve: true, match: [['id'], ['tipoprodotto_id', 'name']], fk: ['tipoprodotto_id' => 'tipoprodotto']),
            self::row($proforma, 'tipoprodotto_sub_constraints', $core, company: false, preserve: true, match: [['id']], fk: ['clienti_id' => 'clientis', 'role_id' => 'fornitoriroles']),
            self::row($oam, 'tipoprodotto_sub_constraints', $core, company: false, preserve: true, match: [['id']], fk: ['clienti_id' => 'clientis']),
            ['source' => $proforma, 'source_table' => 'pratiches_statos', 'dest' => $core, 'dest_table' => 'pratica_stati', 'handler' => 'importPraticaStati', 'company' => false],
            ['source' => $proforma, 'source_table' => 'pratiches', 'dest' => $core, 'dest_table' => 'pratiches', 'handler' => 'importPratiches', 'company' => true],
            self::row($proforma, 'pratica_status_history', $core, company: false, preserve: true, match: [['id']], fk: ['pratica_id' => 'pratiches', 'user_id' => 'users']),
            self::row($proforma, 'client_pratiches', $core, 'pratica_clients', company: false, preserve: true, match: [['id']], renames: ['pratiche_id' => 'pratica_id'], fk: ['pratica_id' => 'pratiches', 'client_id' => 'clients']),
            // I tipi già presenti non si toccano; si aggiungono solo quelli nuovi. Servono anche per collegare i documenti.
            self::row($oam, 'document_types', $core, company: false, match: [['code'], ['slug'], ['name']], prepare: 'prepareDocumentType', insertOnly: true),
            self::row($oam, 'tasks', $core, company: false, preserve: true, match: [['id'], ['name']]),
            self::row($oam, 'documents', $core, legacy: true, match: [['legacy_id']], fk: [
                'document_type_id' => 'document_types',
                'user_id' => 'users',
                'renewed_by' => 'users',
                'uploaded_by' => 'users',
                'verified_by' => 'users',
                'created_by' => 'users',
                'updated_by' => 'users',
                'deleted_by' => 'users',
                'replaced_by_id' => 'documents',
            ], morph: ['documentable_type', 'documentable_id'], prepare: 'prepareDocument', selfFk: ['replaced_by_id']),
            self::row($oam, 'media', $core, company: false, preserve: true, match: [['id'], ['uuid']], morph: ['model_type', 'model_id'], numericMorph: true),
            self::row($oam, 'document_reminders', $core, preserve: true, match: [['id']], fk: ['document_id' => 'documents']),
            self::row($oam, 'document_schedules', $core, preserve: true, match: [['id']], fk: ['document_id' => 'documents'], morph: ['documentable_type', 'documentable_id']),
            self::row($oam, 'email_templates', $core, company: false, preserve: true, match: [['code'], ['id']]),
            self::row($oam, 'mail_accounts', $core, preserve: true, match: [['email_address'], ['id']], morph: ['mailable_type', 'mailable_id'], prepare: 'prepareMailAccount', preserveOnUpdate: ['imap_password', 'smtp_password', 'access_token', 'refresh_token']),
            self::row($oam, 'websites', $core, preserve: true, match: [['id'], ['domain']], fk: ['clienti_id' => 'clientis'], morph: ['websiteable_type', 'websiteable_id']),
            self::row($oam, 'oam_codes', $core, company: false, preserve: true, match: [['id'], ['code']]),
            self::row($oam, 'provvigioni_rules', $core, company: false, preserve: true, match: [['id']], fk: ['clienti_id' => 'clientis', 'fornitori_id' => 'fornitoris']),
            self::row($oam, 'complaints', $core, preserve: true, match: [['id']]),
            self::row($oam, 'complaint_registry', $core, preserve: true, match: [['id']], fk: ['agent_id' => 'employees']),
            self::row($oam, 'employee_type_permissions', $core, company: false, preserve: true, match: [['id']], fk: ['employee_type_id' => 'employee_types', 'resource_id' => 'resources']),
            self::row($proforma, 'blacklist_clienti_employees', $core, match: [['cliente_id', 'employee_id']], fk: ['cliente_id' => 'clientis', 'employee_id' => 'employees']),

            self::row($proforma, 'provvigioni_statos', 'proforma', company: false, pk: 'stato', match: [['stato']]),
            self::row($proforma, 'compensos', 'proforma', company: false, pk: 'status_compenso', match: [['status_compenso']]),
            self::row($proforma, 'coges', 'proforma', company: false, preserve: true, match: [['id']], skipIfDestHasRows: true),
            self::row($proforma, 'enasarcos', 'proforma', company: false, preserve: true, match: [['id']], skipIfDestHasRows: true),
            self::row($proforma, 'firrs', 'proforma', company: false, preserve: true, match: [['id']], skipIfDestHasRows: true),
            self::row($proforma, 'fatturas', 'proforma', company: false, preserve: true, match: [['id']], fk: ['clienti_id' => 'clientis']),
            self::row($proforma, 'invoiceins', 'proforma', company: false, preserve: true, match: [['id']]),
            self::row($proforma, 'invoices', 'proforma', company: false, preserve: true, match: [['invoice_number', 'nr_documento'], ['id']], fk: ['clienti_id' => 'clientis']),
            self::row($proforma, 'prima_nota_configs', 'proforma', company: false, preserve: true, match: [['name'], ['id']]),
            self::row($proforma, 'purchase_invoices', 'proforma', preserve: true, match: [['number'], ['id']], morph: ['invoiceable_type', 'invoiceable_id']),
            self::row($proforma, 'sales_invoices', 'proforma', preserve: true, match: [['number'], ['id']], morph: ['invoiceable_type', 'invoiceable_id']),
            self::row($proforma, 'proformas', 'proforma', company: false, legacy: true, match: [['legacy_id']], fk: ['fornitori_id' => 'fornitoris'], fkBy: ['fornitori_id' => ['column' => 'tipo', 'tables' => ['Istituto' => 'clientis']]], morph: ['invoiceable_type', 'invoiceable_id'], keepIfEmpty: ['invoiceable_type', 'invoiceable_id']),
            self::row($proforma, 'prima_nota_entries', 'proforma', company: false, legacy: true, match: [['legacy_id']], localFk: ['prima_nota_config_id' => 'prima_nota_configs'], morph: ['record_type', 'record_id']),
            self::row($proforma, 'provvigioni', 'proforma', company: false, preserve: true, match: [['id']], fk: ['pratica_id' => 'pratiches', 'id_pratica' => 'pratiches'], localFk: ['pratica_id' => 'pratiches', 'proforma_id' => 'proformas', 'fattura_id' => 'fatturas', 'stato' => 'provvigioni_statos'], prepare: 'prepareProvvigione', keepIfEmpty: ['proforma_id']),
            self::row($proforma, 'vcoge', 'proforma', company: false, preserve: true, match: [['id']], skipIfDestHasRows: true),
            self::row($proforma, 'venasarcotot', 'proforma', company: false, preserve: true, match: [['id']], skipIfDestHasRows: true),
            self::row($proforma, 'venasarcotrimestre', 'proforma', company: false, preserve: true, match: [['id']], skipIfDestHasRows: true),
            self::row($oam, 'onorabilita', 'proforma', company: false, pk: 'nominativo', match: [['nominativo', 'data_ultima_onorabilita'], ['nominativo']]),

            self::row($oam, 'remediations', 'oam', company: false, preserve: true, match: [['code'], ['id']]),
            self::row($oam, 'quality_reviews', 'oam', preserve: true, match: [['id']]),
            self::row($oam, 'oam_semestrales', 'oam', preserve: true, match: [['id']]),
            self::row($oam, 'oam_pratiches', 'oam', preserve: true, match: [['id']]),
            self::row($oam, 'audits', 'oam', preserve: true, match: [['protocol_number'], ['id']], localFk: ['organization_id' => 'organizations', 'quality_review_id' => 'quality_reviews'], morph: ['auditable_type', 'auditable_id']),
            self::row($oam, 'audit_findings', 'oam', preserve: true, match: [['id']], localFk: ['audit_id' => 'audits', 'remediation_id' => 'remediations']),
            self::row($oam, 'audit_checklist_evaluations', 'oam', company: false, preserve: true, match: [['id']], localFk: ['audit_id' => 'audits']),
            self::row($oam, 'training_records', 'oam', preserve: true, match: [['id']], morph: ['trainable_type', 'trainable_id'], prepare: 'prepareTrainingRecord'),
            self::row($oam, 'suspicious_activity_reports', 'oam', preserve: true, match: [['id']], morph: ['reportable_type', 'reportable_id']),
            self::row($proforma, 'client_mandates', 'oam', preserve: true, match: [['id']], fk: ['client_id' => 'clients']),
            self::row($proforma, 'client_relations', 'oam', preserve: true, match: [['id']], fk: ['client_id' => 'clients', 'client_type_id' => 'client_types']),
            self::row($proforma, 'blacklist_clienti_fornitori', 'oam', match: [['cliente_id', 'fornitore_id']], fk: ['cliente_id' => 'clientis', 'fornitore_id' => 'fornitoris']),
        ];
    }

    /**
     * Tabelle dei dump che questo import non copia.
     *
     * @return list<array{table: string, why: string}>
     */
    public static function skipped(): array
    {
        $views = [
            'vwbancastipulated', 'vwcoge', 'vwcogestorno', 'vwenasarco', 'vwenasarcoprovv', 'vwenasarcotot',
            'vwenasarcotrimestre', 'vwproformaagente', 'vwproformaistituto', 'vwprovvdoppie', 'vwstipulated_at', 'vwdocumenti',
        ];
        $framework = ['cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs', 'migrations', 'password_reset_tokens', 'sessions'];
        $rows = [];
        foreach ($views as $table) {
            $rows[] = ['table' => $table, 'why' => 'vista: non si importano le viste'];
        }
        foreach ($framework as $table) {
            $rows[] = ['table' => $table, 'why' => 'tabella di framework'];
        }

        return [
            ...$rows,
            ['table' => 'chat_messages', 'why' => 'non è anagrafica; il dump è vuoto e la chat dell\'assistente non si tocca'],
            ['table' => 'lead_sources', 'why' => 'non è core (resta di unicoloan) e il dump è vuoto'],
            ['table' => 'tmpfornitoriemail', 'why' => 'nessuna tabella di destinazione'],
            ['table' => 'fornitoris_clientis', 'why' => 'nessuna tabella di destinazione'],
            ['table' => 'task_document_types', 'why' => 'nessuna tabella di destinazione nel core né in unicooam_core'],
            ['table' => 'pratica_document_requests', 'why' => 'schema diverso da document_requests e dump vuoto'],
            ['table' => 'tipo_prodottos', 'why' => 'dump vuoto e colonne non sovrapponibili a tipoprodotto'],
            ['table' => 'activity_log', 'why' => 'dump vuoto e schema di audit diverso'],
            ['table' => 'companies', 'why' => 'dump vuoto: la company Races si aggancia per nome e partita IVA'],
            ['table' => 'processes', 'why' => 'unicobpm non riceve dati: nel dump non ci sono processi né istanze'],
            ['table' => 'financing_quotes', 'why' => 'unicofinance non ha tabelle corrispondenti nei dump Races'],
        ];
    }

    /**
     * @param  list<list<string>>  $match
     * @param  array<string, string>  $renames
     * @param  array<string, string>  $fk
     * @param  array<string, string>  $localFk
     * @param  list<string>  $selfFk
     * @param  array<string, mixed>  $defaults
     * @param  list<string>  $preserveOnUpdate
     * @return array<string, mixed>
     */
    private static function row(
        string $source,
        string $from,
        string $dest,
        ?string $to = null,
        bool $company = true,
        bool $preserve = false,
        bool $legacy = false,
        array $match = [['id']],
        array $renames = [],
        array $fk = [],
        array $localFk = [],
        array $selfFk = [],
        ?array $morph = null,
        bool $numericMorph = false,
        ?string $prepare = null,
        string $pk = 'id',
        array $defaults = [],
        array $preserveOnUpdate = [],
        bool $matchOnly = false,
        bool $insertOnly = false,
        bool $skipIfDestHasRows = false,
        array $fkBy = [],
        array $keepIfEmpty = [],
    ): array {
        return [
            'source' => $source,
            'source_table' => $from,
            'dest' => $dest,
            'dest_table' => $to ?? $from,
            'company' => $company,
            'preserve_id' => $preserve,
            'legacy' => $legacy ? 'legacy_id' : null,
            'match' => $match,
            'renames' => $renames,
            'fk' => $fk,
            'local_fk' => $localFk,
            'self_fk' => $selfFk,
            'morph' => $morph,
            'numeric_morph' => $numericMorph,
            'prepare' => $prepare,
            'pk' => $pk,
            'defaults' => $defaults,
            'preserve_on_update' => $preserveOnUpdate,
            'match_only' => $matchOnly,
            'insert_only' => $insertOnly,
            'skip_if_dest_has_rows' => $skipIfDestHasRows,
            'fk_by' => $fkBy,
            'keep_if_empty' => $keepIfEmpty,
        ];
    }
}
