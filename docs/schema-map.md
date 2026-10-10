# Mappa dello schema core
Generata dai dump dei 5 database di sviluppo (unicoagent, unicobpm, unicoloan, unicooam, proforma).
Per ogni tabella: sorgenti in ordine di priorità (la prima decide tipi e vincoli), colonne con l'app di provenienza, conversioni e chiavi esterne.

## Aziende, utenti, permessi, dipendenti

### `companies`
Sorgenti: `loan.companies`, `oam.companies`, `proforma.companies`, `bpm.companies`

| Provenienza | Colonne |
|---|---|
| bpm+loan+oam+proforma | id, created_at, updated_at |
| loan+oam+proforma | name, vat_number |
| loan+oam | vat_name, oam, oam_at, oam_name, numero_iscrizione_rui, ivass, ivass_at, ivass_name, ivass_section, sponsor, company_type, page_header, page_footer |
| proforma | crm, crm_last_activation, callcenter, call_last_activation, updated_by, deleted_by, deleted_at, email, email_cc, email_bcc, emailsubject, compenso_descrizione, aibackground, db_host, db_port, db_database, db_username, db_password |
| nuovo | drive_folder_id |

Conversioni: `id` da char(36) a bigint auto-increment

FK: `updated_by` → `users`, `deleted_by` → `users`

### `modules`
Sorgenti: `loan.modules`, `oam.modules`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, code, name, description, is_active, created_at, updated_at |

### `company_modules`
Sorgenti: `loan.company_modules`, `oam.company_modules`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, company_id, module_id, plan_type, trial_ends_at, one_time_cost, monthly_cost, billing_frequency, last_invoice_at, last_payment_at, created_at, updated_at |

Conversioni: `company_id` da char(36) a bigint, FK → `companies`

FK: `company_id` → `companies`, `module_id` → `modules`

### `branches`
Sorgenti: `oam.branches`, `loan.branches`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, company_id, name, address, street_number, city, zip_code, province, region, branchable_type, branchable_id, is_main_office, is_active, manager_first_name, manager_last_name, manager_tax_code, founded_at, dismissed_at, created_at, updated_at, deleted_at |

Conversioni: `company_id` da char(36) a bigint, FK → `companies`; `branchable_id` da char a bigint (morph)

FK: `company_id` → `companies`

### `company_roles`
Sorgenti: `oam.company_roles`, `loan.company_roles`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, company_id, name, funzione, is_external, dal, al, execution_method, expertName, n, created_at, updated_at |

Conversioni: `company_id` da char(36) a bigint, FK → `companies`

FK: `company_id` → `companies`

### `users`
Sorgenti: `oam.users`, `loan.users`, `bpm.users`, `proforma.users`

| Provenienza | Colonne |
|---|---|
| bpm+loan+oam+proforma | id, name, email, email_verified_at, password, remember_token, created_at, updated_at |
| bpm+loan+oam | role, profile_type, profile_id |
| proforma | cf, microsoft_token, microsoft_refresh_token, azure_id, microsoft_id, deleted_at |

### `socialite_users`
Sorgenti: `loan.socialite_users`, `oam.socialite_users`, `proforma.socialite_users`, `bpm.socialite_users`

| Provenienza | Colonne |
|---|---|
| bpm+loan+oam+proforma | id, user_id, provider, provider_id, created_at, updated_at |
| loan+oam | email, avatar, is_personal |
| proforma | token, refresh_token, expires_at |

FK: `user_id` → `users`

### `resources`
Sorgenti: `bpm.resources`, `loan.resources`, `oam.resources`

| Provenienza | Colonne |
|---|---|
| bpm+loan+oam | id, app_name, key, name, group, min_plan, created_at, updated_at |

### `employee_types`
Sorgenti: `bpm.employee_types`, `loan.employee_types`, `oam.employee_types`

| Provenienza | Colonne |
|---|---|
| bpm+loan+oam | id, name, icon, companytype, is_external, created_at, updated_at |

Conversioni: `id` da int() a bigint auto-increment

### `employee_type_permissions`
Sorgenti: `bpm.employee_type_permissions`, `loan.employee_type_permissions`, `oam.employee_type_permissions`

| Provenienza | Colonne |
|---|---|
| bpm+loan+oam | id, employee_type_id, action, created_at, updated_at |
| bpm | resource_id |

Colonne eliminate: `resource`

Conversioni: `employee_type_id` da int() a bigint, FK → `employee_types`

FK: `employee_type_id` → `employee_types`, `resource_id` → `resources`

### `employee_type_resource_presets`
Sorgenti: `bpm.employee_type_resource_presets`

| Provenienza | Colonne |
|---|---|
| bpm | id, employee_type_id, resource_id, created_at, updated_at |

Conversioni: `employee_type_id` da int() a bigint, FK → `employee_types`

FK: `employee_type_id` → `employee_types`, `resource_id` → `resources`

### `employees`
Sorgenti: `oam.employees`, `loan.employees`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, company_id, user_id, branch_id, name, is_active, role_title, cf, email, pec, phone, department, oam, oam_at, oam_name, numero_iscrizione_rui, oam_dismissed_at, ivass, hiring_date, termination_date, coordinated_by_id, employee_types, supervisor_type, privacy_role, purpose, data_subjects, data_categories, retention_period, extra_eu_transfer, security_measures, privacy_data, is_structure, is_ghost, created_by, updated_by, deleted_by, created_at, updated_at, deleted_at |
| oam | employee_roles, is_external |

Conversioni: `company_id` da char(36) a bigint, FK → `companies`

FK: `company_id` → `companies`, `user_id` → `users`, `branch_id` → `branches`, `coordinated_by_id` → `employees`, `created_by` → `users`, `updated_by` → `users`, `deleted_by` → `users`

## Anagrafiche: clienti, mandanti, fornitori

### `client_types`
Sorgenti: `oam.client_types`, `loan.client_types`, `proforma.client_types`

| Provenienza | Colonne |
|---|---|
| loan+oam+proforma | id, name, is_person, is_company, privacy_role, purpose, data_subjects, data_categories, retention_period, extra_eu_transfer, security_measures, privacy_data, created_at, updated_at |

Conversioni: `id` da int() a bigint auto-increment

### `lead_sources`
Sorgenti: `oam.lead_sources`, `loan.lead_sources`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, name, type, description, utm_source, utm_campaign, is_active, created_at, updated_at |

### `clients`
Sorgenti: `proforma.clients`

| Provenienza | Colonne |
|---|---|
| proforma | id, company_id, is_person, name, first_name, tax_code, vat_number, email, phone, iban, employer_id, website, is_pep, client_type_id, is_sanctioned, is_remote_interaction, general_consent_at, privacy_policy_read_at, consent_special_categories_at, consent_sic_at, consent_marketing_at, consent_profiling_at, status, is_company, is_lead, leadsource_id, acquired_at, contoCOGE, created_at, updated_at, privacy_consent, is_client, subfornitori, is_requiredApprovation, is_approved, is_anonymous, blacklist_at, blacklisted_by, salary, salary_quote, is_art108, is_consultant_gdpr, privacy_contact_email, dpo_email, is_iso27001_certified, is_dummy, employee_roles, user_id, birth_date, birth_place, sex, citizenship, legal_representative_id, pec, ateco_code, cciaa_registration, legal_form, sdi_code, activity_status, company_started_at, share_capital, employees, turnover, net_worth, balance_year, registry_updated_at |

Conversioni: `company_id` da char(36) a bigint, FK → `companies`; `blacklisted_by` da varchar(255) a bigint, FK → `users`

FK: `company_id` → `companies`, `employer_id` → `clients`, `client_type_id` → `client_types`, `leadsource_id` → `lead_sources`, `blacklisted_by` → `users`, `user_id` → `users`, `legal_representative_id` → `clients`

### `client_relations`
Sorgenti: `oam.client_relations`, `proforma.client_relations`

| Provenienza | Colonne |
|---|---|
| oam+proforma | id, company_id, client_id, client_type_id, shares_percentage, is_titolare, data_inizio_ruolo, data_fine_ruolo, created_at, updated_at |

Conversioni: `company_id` da char(36) a bigint, FK → `companies`; `client_id` da int() a bigint, FK → `clients`; `client_type_id` da int() a bigint, FK → `client_types`

FK: `company_id` → `companies`, `client_id` → `clients`, `client_type_id` → `client_types`

### `client_mandates`
Sorgenti: `oam.client_mandates`, `proforma.client_mandates`

| Provenienza | Colonne |
|---|---|
| oam+proforma | id, client_id, numero_mandato, data_firma_mandato, data_scadenza_mandato, importo_richiesto_mandato, scopo_finanziamento, data_consegna_trasparenza, stato, deleted_at, created_at, updated_at |
| oam | ruolo, name, notes, purpose_of_relationship, funds_origin, oam_delivered, role_risk_level |

FK: `client_id` → `clients`

### `fornitoriroles`
Sorgenti: `proforma.fornitoriroles`

| Provenienza | Colonne |
|---|---|
| proforma | id, name, code, level, description, created_at, updated_at |

Conversioni: `id` da int() a bigint auto-increment

### `clientis`
Sorgenti: `proforma.clientis`

| Provenienza | Colonne |
|---|---|
| proforma | id, name, nome, piva, stipulated_at, abi, abi_name, dismissed_at, type, oam, oam_name, oam_at, numero_iscrizione_rui, ivass, ivass_at, ivass_name, ivass_section, mandate_number, start_date, end_date, is_exclusive, status, notes, principal_type, submission_type, cf, website, is_reported, privacy_contact_email, dpo_email, coge, codice, email, regione, citta, company_id, customertype_id, is_active, is_dummy, deleted_at, created_at, updated_at |

Conversioni: `id` da char(36) a bigint auto-increment; `company_id` da char(36) a bigint, FK → `companies`

FK: `company_id` → `companies`, `customertype_id` → `client_types`

### `fornitoris`
Sorgenti: `proforma.fornitoris`

| Provenienza | Colonne |
|---|---|
| proforma | id, name, nome, stipulated_at, pec, description, email_private, supervisor_type, oam, oam_at, oam_name, numero_iscrizione_rui, ivass, ivass_at, dismissed_at, ivass_name, ivass_section, type, is_active, is_art108, coordinated_type, coordinated_id, user_id, oam_dismissed_at, welcome_bonus, campagna, available_at, budget, codice, coge, natoil, indirizzo, comune, cap, prov, tel, coordinatore, piva, cf, nomecoge, nomefattura, email, anticipo, enasarco, anticipo_residuo, contributo, contributo_description, anticipo_description, issubfornitore, operatore, iscollaboratore, isdipendente, regione, citta, deleted_at, created_at, updated_at, company_id, contributoperiodicita, contributodalmese, fornitorirole_id, branch_id, employee_roles |

Colonne eliminate: `company_branch_id`

Conversioni: `id` da char(36) a bigint auto-increment; `company_id` da char(36) a bigint, FK → `companies`; `fornitorirole_id` da int() a bigint, FK → `fornitoriroles`

FK: `user_id` → `users`, `company_id` → `companies`, `fornitorirole_id` → `fornitoriroles`, `branch_id` → `branches`

### `oam_codes`
Sorgenti: `oam.oam_codes`, `loan.oam_codes`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, code, name, description, tipo_prodotto, is_dummy, is_active, created_at, updated_at |
| oam | submission_type |

### `clienti_oam`
Sorgenti: `oam.clienti_oam`, `loan.clienti_oam`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, clienti_id, oam_code_id, dal, al, created_at, updated_at |

Conversioni: `clienti_id` da char(36) a bigint, FK → `clientis`

FK: `clienti_id` → `clientis`, `oam_code_id` → `oam_codes`

### `blacklist_clienti_fornitori`
Sorgenti: `oam.blacklist_clienti_fornitori`, `proforma.blacklist_clienti_fornitori`

| Provenienza | Colonne |
|---|---|
| oam+proforma | id, cliente_id, fornitore_id, motivo, data_inizio, data_fine, created_at, updated_at |

Conversioni: `cliente_id` da char(36) a bigint, FK → `clientis`; `fornitore_id` da char(36) a bigint, FK → `fornitoris`

FK: `cliente_id` → `clientis`, `fornitore_id` → `fornitoris`

### `blacklist_clienti_employees`
Sorgenti: `proforma.blacklist_clienti_employees`

| Provenienza | Colonne |
|---|---|
| proforma | id, cliente_id, employee_id, motivo, data_inizio, data_fine, created_at, updated_at |

Conversioni: `id` da char(36) a bigint auto-increment; `cliente_id` da char(36) a bigint, FK → `clientis`

FK: `cliente_id` → `clientis`, `employee_id` → `employees`

### `onorabilita`
Sorgenti: `proforma.onorabilita`, `oam.onorabilita`

| Provenienza | Colonne |
|---|---|
| proforma | id |
| oam+proforma | nominativo, data_ultimo_carico_pendente, data_ultima_onorabilita |

Conversioni: `id` da int() a bigint auto-increment

### `address_types`
Sorgenti: `proforma.address_types`

| Provenienza | Colonne |
|---|---|
| proforma | id, name |

### `addresses`
Sorgenti: `proforma.addresses`

| Provenienza | Colonne |
|---|---|
| proforma | id, addressable_type, addressable_id, name, numero, street, city, zip_code, address_type_id, created_at, updated_at |

Conversioni: `addressable_id` da varchar a bigint (morph)

FK: `address_type_id` → `address_types`

### `organizations`
Sorgenti: `oam.organizations`, `loan.organizations`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, acronym, name, description, reference_law, website, pec_email, is_active, created_at, updated_at, deleted_at |

## Prodotti e regole provvigionali

### `tipoprodotto`
Sorgenti: `proforma.tipoprodotto`, `oam.tipo_prodottos`, `loan.tipo_prodottos`

| Provenienza | Colonne |
|---|---|
| loan+oam+proforma | id, updated_at, created_at |
| oam+proforma | name |
| proforma | tipo_prodotto, code, is_external, is_active, is_oneclient, oam, tipo_provvigioni, for_person, for_company, requires_mandate, is_third_party |

Conversioni: `id` da int() a bigint auto-increment

### `tipoprodotto_sub`
Sorgenti: `proforma.tipoprodotto_sub`

| Provenienza | Colonne |
|---|---|
| proforma | id, tipoprodotto_id, name, code, vincoli, is_active, created_at, updated_at |

Conversioni: `id` da int() a bigint auto-increment; `tipoprodotto_id` da int() a bigint, FK → `tipoprodotto`

FK: `tipoprodotto_id` → `tipoprodotto`

### `tipoprodotto_sub_constraints`
Sorgenti: `proforma.tipoprodotto_sub_constraints`, `oam.tipoprodotto_sub_constraints`, `loan.tipoprodotto_sub_constraints`

| Provenienza | Colonne |
|---|---|
| loan+oam+proforma | id, tipoprodotto_id, tipoprodotto_sub_id, clienti_id, min_age, max_age_at_maturity, min_amount, max_amount, min_duration_months, max_duration_months, min_employment_months, max_debt_to_income_ratio, max_ltv_percentage, allowed_employment_types, additional_rules_json, additional_notes, created_at, updated_at |
| proforma | role_id, is_active |
| loan+oam | deleted_at |

Conversioni: `tipoprodotto_id` da int() a bigint, FK → `tipoprodotto`; `tipoprodotto_sub_id` da int() a bigint, FK → `tipoprodotto_sub`; `clienti_id` da char(36) a bigint, FK → `clientis`; `role_id` da int() a bigint, FK → `fornitoriroles`

FK: `tipoprodotto_id` → `tipoprodotto`, `tipoprodotto_sub_id` → `tipoprodotto_sub`, `clienti_id` → `clientis`, `role_id` → `fornitoriroles`

### `provvigioni_rules`
Sorgenti: `oam.provvigioni_rules`, `loan.provvigioni_rules`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, tipoprodotto_id, tipoprodotto_sub_id, clienti_id, kind_id, fornitori_id, coordinamento, iscliente, tipo_provvigioni, value, valid_from, valid_to, notes, created_at, updated_at |

Conversioni: `clienti_id` da char(36) a bigint, FK → `clientis`; `fornitori_id` da char(36) a bigint, FK → `fornitoris`

FK: `tipoprodotto_id` → `tipoprodotto`, `tipoprodotto_sub_id` → `tipoprodotto_sub`, `clienti_id` → `clientis`, `kind_id` → `fornitoriroles`, `fornitori_id` → `fornitoris`

## Pratiche e stati

### `pratica_stati`
Sorgenti: `loan.pratica_stati`, `oam.pratica_stati`, `proforma.pratiches_statos`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, name, ordine, colore, icona, created_at, updated_at, deleted_at |
| loan+oam+proforma | codice, is_rejected, is_working, is_estingued |

### `pratica_stati_transizioni`
Sorgenti: `loan.pratica_stati_transizioni`, `oam.pratica_stati_transizioni`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, stato_da_id, stato_a_id |

FK: `stato_da_id` → `pratica_stati`, `stato_a_id` → `pratica_stati`

### `pratiches`
Sorgenti: `proforma.pratiches`

| Provenienza | Colonne |
|---|---|
| proforma | id, codice_pratica, nome_cliente, cognome_cliente, codice_fiscale, denominazione_agente, partita_iva_agente, denominazione_banca, denominazione_prodotto, data_inserimento_pratica, created_at, updated_at, rata, erogato, nrate, sended_at, approved_at, erogated_at, amount, net, is_notowned, upload_at, rejected_at, abi, abi_name |
| nuovo | company_id, pratica_stato_id, tipoprodotto_id, fornitori_id, clienti_id |

Colonne eliminate: `stato_pratica`, `tipo_prodotto`

Conversioni: `id` da varchar(255) a bigint auto-increment

FK: `company_id` → `companies`, `pratica_stato_id` → `pratica_stati`, `tipoprodotto_id` → `tipoprodotto`, `fornitori_id` → `fornitoris`, `clienti_id` → `clientis`

### `pratica_clients`
Sorgenti: `proforma.pratica_clients`, `proforma.client_pratiches`

| Provenienza | Colonne |
|---|---|
| proforma | id, pratica_id, client_id, role, created_at, updated_at, name, notes, purpose_of_relationship, funds_origin |

Conversioni: `pratica_id` da char(36) a bigint, FK → `pratiches`; `client_id` da int() a bigint, FK → `clients`

FK: `pratica_id` → `pratiches`, `client_id` → `clients`

### `pratica_status_history`
Sorgenti: `proforma.pratica_status_history`

| Provenienza | Colonne |
|---|---|
| proforma | id, pratica_id, status_from, status_to, changed_at, source, notes, user_id, created_at, updated_at |

Conversioni: `pratica_id` da varchar(255) a bigint, FK → `pratiches`

FK: `pratica_id` → `pratiches`, `user_id` → `users`

### `oam_pratiches`
Sorgenti: `oam.oam_pratiches`, `loan.oam_pratiches`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, company_id, period, riga, prodotto_creditizio, abi_name, submission_type, intermediari_convenzionati, intermediari_non_convenzionati, pratiche_intermediate, pratiche_lavorazione, erogato_lordo, erogato_lavorazione, provv_clientela, provv_istituto_comp, premi_istituto_comp, payin_ass_banche, payin_ass_broker, payin_ass_broker_cap, payout_rete_credito, payout_rete_ass_banche, payout_rete_ass_broker, payout_rete_ass_broker_cap, num_rivalse, importo_retrocesse, pratica, istituto, agente, cliente, tipo_prodotto, principal_type, sended_at, approved_at, erogated_at, rejected_at, storned_at, created_at, updated_at, deleted_at |

Conversioni: `company_id` da char(36) a bigint, FK → `companies`

FK: `company_id` → `companies`

### `oam_semestrales`
Sorgenti: `oam.oam_semestrales`, `loan.oam_semestrales`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, company_id, period, numero_iscrizione_m510, prodotto_creditizio, abi_name, submission_type, intermediari_convenzionati, intermediari_non_convenzionati, pratiche_intermediate, pratiche_lavorazione, erogato_lordo, erogato_lavorazione, provv_clientela, provv_istituto_comp, premi_istituto_comp, payin_ass_banche, payin_ass_broker, payin_ass_broker_cap, payout_rete_credito, payout_rete_ass_banche, payout_rete_ass_broker, payout_rete_ass_broker_cap, num_rivalse, importo_retrocesse, created_at, updated_at, is_convenzione |
| oam | gestione |

Conversioni: `company_id` da char(36) a bigint, FK → `companies`

FK: `company_id` → `companies`

## Documenti, media, comunicazioni

### `document_types`
Sorgenti: `loan.document_types`, `oam.document_types`, `loan.pratica_requisiti`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, name, description, code, codegroup, slug, regex_pattern, priority, phase, is_person, is_company, is_employee, is_agent, is_principal, is_client, is_practice, trigger_field, is_signed, is_monitored, renewed_by_id, document_url, training_hours, training_organization, duration, duration_unit, nature, doctype, cellposition, emitted_by, is_sensible, is_template, is_stored, regex, is_end_month (era `is_endMonth`), is_ai_abstract (era `is_AiAbstract`), is_ai_check (era `is_AiCheck`), ai_pattern (era `AiPattern`), min_confidence, allow_auto_verification, notify_days_before, retention_years, created_by, updated_by, deleted_by, created_at, updated_at, deleted_at, is_versioned, document_typable, trigger_state, trigger_value, exclude_field, exclude_state, exclude_value |
| nuovo | company_id, ai_kind, checks |

Colonne eliminate: `expire_days_before`

FK: `renewed_by_id` → `document_types`, `created_by` → `users`, `updated_by` → `users`, `deleted_by` → `users`, `company_id` → `companies`

### `documents`
Sorgenti: `loan.documents`, `oam.documents`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, company_id, documentable_type, documentable_id, document_type_id, name, training_hours, training_organization, docnumber, spatie_collection, document_url, status, last_sent_at, reminders_count, sync_status, source_app, app_id, app_drive_id, app_etag, extracted_text, metadata, ai_abstract, ai_confidence_score, is_monitored, is_template, doctype, cellposition, is_signed, is_unique, is_end_month (era `is_endMonth`), emitted_by, emitted_at, expires_at, delivered_at, signed_at, description, internal_notes, rejection_note, user_id, renewed_by, uploaded_by, verified_by, verified_at, created_by, updated_by, deleted_by, file_hash, created_at, updated_at, deleted_at |
| loan | replaced_by_id (era `renewed_by_id`) |
| nuovo | channel, channel_ref |

Conversioni: `id` da char(36) a bigint auto-increment; `company_id` da char(36) a bigint, FK → `companies`; `documentable_id` da char a bigint (morph); `renewed_by` da char(36) a bigint, FK → `users`; `replaced_by_id` da char(36) a bigint, FK → `documents`

FK: `company_id` → `companies`, `document_type_id` → `document_types`, `user_id` → `users`, `renewed_by` → `users`, `replaced_by_id` → `documents`, `uploaded_by` → `users`, `verified_by` → `users`, `created_by` → `users`, `updated_by` → `users`, `deleted_by` → `users`

### `document_requirements`
Sorgenti: `loan.requisito_tipo_finanziamento`, `loan.task_document_types`

| Provenienza | Colonne |
|---|---|
| loan | id, tipoprodotto_id, tipoprodotto_sub_id, document_type_id (era `pratica_requisito_id`), is_required (era `obbligatorio`), sort_order (era `ordine`), task_id, created_at, updated_at |

Colonne eliminate: `slug`

FK: `tipoprodotto_id` → `tipoprodotto`, `tipoprodotto_sub_id` → `tipoprodotto_sub`, `document_type_id` → `document_types`, `task_id` → `tasks`

### `document_requests`
Sorgenti: `proforma.pratica_document_requests`, `loan.pratica_requisiti_operativi`, `agent.pratica_documents`

| Provenienza | Colonne |
|---|---|
| agent+loan+proforma | id, requestable_id (era `pratica_id`), status, created_at, updated_at |
| agent+proforma | name |
| proforma | description, requested_by_role (era `requested_by`), assigned_to_type, share_with_client, due_date, rejected_reason, agent_notified_at, client_notified_at |
| loan+proforma | requested_at |
| agent+loan | document_type_id (era `pratica_requisito_id`), notes (era `note`) |
| loan | completed_at (era `data_completamento`) |
| agent | sort_order, received_at, reviewed_at |
| nuovo | requestable_type, company_id, document_id, is_required, created_by |

Colonne eliminate: `code`, `file_path`, `requirement`

Conversioni: `requestable_id` da varchar a bigint (morph)

FK: `document_type_id` → `document_types`, `company_id` → `companies`, `document_id` → `documents`, `created_by` → `users`

### `document_analyses`
Sorgenti: 

| Provenienza | Colonne |
|---|---|
| nuovo | id, document_id, kind, checks, result, outcome, confidence, operator_type, operator_label, ai_model, ai_input_tokens, ai_output_tokens, ai_cost, error, created_by, created_at, updated_at |

FK: `document_id` → `documents`, `created_by` → `users`

### `signature_requests`
Sorgenti: `loan.signature_requests`

| Provenienza | Colonne |
|---|---|
| loan | id, document_id, provider, provider_ref, status, sent_at, signed_at, expires_at, last_synced_at, last_event_at, failure_reason, requested_by, token, created_at, updated_at |

Conversioni: `document_id` da char(36) a bigint, FK → `documents`

FK: `document_id` → `documents`, `requested_by` → `users`

### `signature_request_signers`
Sorgenti: `loan.signature_request_signers`

| Provenienza | Colonne |
|---|---|
| loan | id, signature_request_id, position, slot, role, name, first_name, last_name, tax_code, email, phone, signer_type, signer_ref, status, signed_at, provider_signer_ref, created_at, updated_at |

FK: `signature_request_id` → `signature_requests`

### `pdf_modules`
Sorgenti: `loan.pdf_modules`

| Provenienza | Colonne |
|---|---|
| loan | id, name, file_path, document_type_id, version, tipi_prodotto, client_scope, is_active, signature_slots, created_at, updated_at |

FK: `document_type_id` → `document_types`

### `pdf_module_fields`
Sorgenti: `loan.pdf_module_fields`

| Provenienza | Colonne |
|---|---|
| loan | id, pdf_module_id, pdf_field_name, pdf_field_type, source_key, formatter, checkbox_on_value, checkbox_when, created_at, updated_at |

FK: `pdf_module_id` → `pdf_modules`

### `document_reminders`
Sorgenti: `loan.document_reminders`, `oam.document_reminders`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, document_id, days_before, recipient_email, status, error_message, sent_at, created_at, updated_at |

Conversioni: `document_id` da char(36) a bigint, FK → `documents`

FK: `document_id` → `documents`

### `document_schedules`
Sorgenti: `loan.document_schedules`, `oam.document_schedules`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, document_id, documentable_type, documentable_id, entity_name, documentable_group_key, document_name, document_type_name, expires_at, days_until_expiry, status, last_sent_at, reminders_count, created_at, updated_at |

Conversioni: `document_id` da char(36) a bigint, FK → `documents`; `documentable_id` da char a bigint (morph)

FK: `document_id` → `documents`

### `tasks`
Sorgenti: `loan.tasks`, `oam.tasks`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, parent_id, name, description, app_identifier, taskable, trigger_field, trigger_state, trigger_value, exclude_field, exclude_state, exclude_value, is_active, created_at, updated_at |
| loan | trigger_subfield, trigger_subvalue |

FK: `parent_id` → `tasks`

### `media`
Sorgenti: `oam.media`, `loan.media`, `bpm.media`

| Provenienza | Colonne |
|---|---|
| bpm+loan+oam | id, model_type, model_id, uuid, collection_name, name, file_name, mime_type, disk, conversions_disk, size, manipulations, custom_properties, generated_conversions, responsive_images, order_column, created_at, updated_at |

Conversioni: `model_id` da varchar a bigint (morph)

### `email_templates`
Sorgenti: `oam.email_templates`, `loan.email_templates`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, code, name, subject, body, placeholders, is_active, app_identifier, created_at, updated_at |
| oam | emailable, email_field, trigger_field, trigger_state, trigger_value |

### `mail_accounts`
Sorgenti: `oam.mail_accounts`, `loan.mail_accounts`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, name, email_address, email_reply_to, is_pec, protocol (era `incoming_protocol`), imap_host (era `incoming_host`), imap_port (era `incoming_port`), imap_username (era `incoming_username`), imap_password (era `incoming_password`), imap_encryption (era `incoming_encryption`), smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, is_active, mailable_type, mailable_id, created_at, updated_at, deleted_at |
| nuovo | company_id, auth_type, provider, access_token, refresh_token, token_expires_at, last_synced_at |

Conversioni: `mailable_id` da varchar a bigint (morph)

FK: `company_id` → `companies`

### `incoming_emails`
Sorgenti: 

| Provenienza | Colonne |
|---|---|
| nuovo | id, company_id, mail_account_id, message_id, in_reply_to, references, thread_id, from_email, from_name, to, cc, subject, body_text, body_html, received_at, is_read, classification, related_type, related_id, drive_folder_id, created_at, updated_at, deleted_at |

FK: `company_id` → `companies`, `mail_account_id` → `mail_accounts`

## Audit, reclami, antiriciclaggio, formazione

### `remediations`
Sorgenti: `oam.remediations`, `loan.remediations`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, remediation_type, name, code, description, timeframe_hours, timeframe_desc, created_at, updated_at, deleted_at |

### `quality_reviews`
Sorgenti: `oam.quality_reviews`

| Provenienza | Colonne |
|---|---|
| oam | id, company_id, name, reviewer_user_id, period_from, period_to, sample_size, filters, notes, created_at, updated_at, deleted_at |

Conversioni: `company_id` da char(36) a bigint, FK → `companies`

FK: `company_id` → `companies`, `reviewer_user_id` → `users`

### `audits`
Sorgenti: `oam.audits`, `loan.audits`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, company_id, name, auditable_type, auditable_id, auditor_name, organization_id, scheduled_at, executed_at, status, protocol_number, origin_type, execution_method, scope, outcome, severity, summary, auditor_notes, remediation_plan, followup_date, created_at, updated_at, deleted_at, rilievi_codes, collaboratore, drive_folder_id |
| oam | quality_review_id, followup_checked, followup_notes |

Conversioni: `company_id` da char(36) a bigint, FK → `companies`; `auditable_id` da char a bigint (morph)

FK: `company_id` → `companies`, `quality_review_id` → `quality_reviews`, `organization_id` → `organizations`

### `audit_findings`
Sorgenti: `oam.audit_findings`, `loan.audit_findings`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, audit_id, company_id, title, description, severity, status, requires_investigation, investigation_notes, investigation_deadline, requires_corrective_action, corrective_action_description, remediation_id, corrective_action_deadline, resolved_at, resolution_notes, created_at, updated_at, deleted_at |

Conversioni: `company_id` da char(36) a bigint, FK → `companies`

FK: `audit_id` → `audits`, `company_id` → `companies`, `remediation_id` → `remediations`

### `audit_checklist_evaluations`
Sorgenti: `oam.audit_checklist_evaluations`, `loan.audit_checklist_evaluations`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, audit_id, audit_checklist_item_id, external_processor_id, internal_documentation, vendor_evidence_notes, gap_status, gap_notes, is_vendor_scope, is_optional, verified_at, next_review_at, verified_by, deleted_at, created_at, updated_at |

Conversioni: `verified_by` da varchar(255) a bigint, FK → `users`

FK: `audit_id` → `audits`, `verified_by` → `users`

### `complaints`
Sorgenti: `oam.complaints`, `loan.complaints`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, company_id, complaintable_type, complaintable_id, received_at, subject, description, status, resolved_at, created_at, updated_at, deleted_at |

Conversioni: `company_id` da char(36) a bigint, FK → `companies`

FK: `company_id` → `companies`

### `complaint_registry`
Sorgenti: `oam.complaint_registry`, `loan.complaint_registry`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, company_id, protocol_number, received_at, reception_channel, receiving_email, complainant_type, complainant_id, complainant_name, complainant_email, macro_category, category, subject_type, subject_id, agent_id, bank_id, description, financial_impact, status, deadline_at, is_extended, resolved_at, resolution_notes, escalated_to, created_at, updated_at, deleted_at, data_subject_request_id, event_sequence, event_at, event_phase, mandating_company, master_agency, sub_supplier, caller_number, agcom_roc_compliance, event_channel_label, event_direction, event_counterparty, complainant_phone, complainant_fiscal_code, operational_action, dnc_blacklist_status, sla_deadline_note, log_freeze_retention, evidence_attachment, phase_status, assigned_to |

Conversioni: `company_id` da char(36) a bigint, FK → `companies`; `complainant_id` da char a bigint (morph); `subject_id` da char a bigint (morph); `agent_id` da char(36) a bigint, FK → `fornitoris`; `bank_id` da char(36) a bigint, FK → `clientis`

FK: `company_id` → `companies`, `agent_id` → `fornitoris`, `bank_id` → `clientis`

### `suspicious_activity_reports`
Sorgenti: `oam.suspicious_activity_reports`, `loan.suspicious_activity_reports`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, company_id, client_id, reportable_type, reportable_id, reported_at, anomalies_codes, description, status, created_at, updated_at, deleted_at |

Conversioni: `company_id` da char(36) a bigint, FK → `companies`; `client_id` da char(36) a bigint, FK → `clients`; `reportable_id` da char a bigint (morph)

FK: `company_id` → `companies`, `client_id` → `clients`

### `training_records`
Sorgenti: `oam.training_records`, `loan.training_records`

| Provenienza | Colonne |
|---|---|
| loan+oam | id, company_id, trainable_type, trainable_id, regulatory_framework, name, description, provider, trainer, delivery_mode, training_date, expiry_date, hours, outcome, score, certificate_issued, certificate_number, notes, created_at, updated_at, deleted_at |

Conversioni: `company_id` da char(36) a bigint, FK → `companies`; `trainable_id` da char a bigint (morph)

FK: `company_id` → `companies`

## Log attività e chat AI

### `activity_log`
Sorgenti: `bpm.activity_log`, `oam.activity_log`, `loan.activity_log`

| Provenienza | Colonne |
|---|---|
| bpm+loan+oam | id, log_name, description, subject_type, subject_id, event, causer_type, causer_id, attribute_changes, properties, created_at, updated_at |
| bpm | risk_score, risk_level, retention_hold, integrity_hash, request_id, ip_address |

### `chat_messages`
Sorgenti: `oam.chat_messages`, `loan.chat_messages`, `bpm.chat_messages`

| Provenienza | Colonne |
|---|---|
| bpm+loan+oam | id, user_id, thread_id, role, content, meta, created_at, updated_at |
| loan+oam | company_id |

Conversioni: `company_id` da char(36) a bigint, FK → `companies`

FK: `company_id` → `companies`, `user_id` → `users`

## Tabelle sorgente fuse in un'altra

- `proforma.pratiches_statos` → `pratica_stati`
- `proforma.client_pratiches` → `pratica_clients`
- `loan.tipo_prodottos` → `tipoprodotto`
- `loan.pratica_requisiti` → `document_types`
- `loan.requisito_tipo_finanziamento` → `document_requirements`
- `loan.task_document_types` → `document_requirements`
- `loan.pratica_requisiti_operativi` → `document_requests`
- `proforma.pratica_document_requests` → `document_requests`
- `agent.pratica_documents` → `document_requests (solo colonne; unicoagent resta separata)`
- `oam.tipo_prodottos` → `tipoprodotto`
