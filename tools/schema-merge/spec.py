"""Specifica di fusione delle tabelle condivise nel database core.

Ogni tabella target elenca le sorgenti in ordine di priorità: la prima sorgente
decide tipo, nullabilità e default delle colonne che ha; le colonne che arrivano
solo da sorgenti successive vengono aggiunte come nullable.
"""

# Aree -> file di migration (ordine di creazione)
AREAS = [
    ("tenancy", "Aziende, utenti, permessi, dipendenti"),
    ("registry", "Anagrafiche: clienti, mandanti, fornitori"),
    ("products", "Prodotti e regole provvigionali"),
    ("pratiche", "Pratiche e stati"),
    ("documents", "Documenti, media, comunicazioni"),
    ("compliance", "Audit, reclami, antiriciclaggio, formazione"),
    ("platform", "Log attività e chat AI"),
]

T = {}


def t(name, area, src, rename=None, drop=(), add=(), fk=None, types=None, nodefault=(), comment=None):
    T[name] = dict(area=area, src=src, rename=rename or {}, drop=set(drop), add=list(add),
                   fk=fk or {}, types=types or {}, nodefault=set(nodefault), comment=comment)


# ---------------------------------------------------------------- tenancy
t("companies", "tenancy", ["loan.companies", "oam.companies", "agent.companies", "proforma.companies", "bpm.companies"],
  rename={"agent.companies": {"type": "company_type"}, "proforma.companies": {"piva": "vat_number"}},
  types={"db_password": "text"},
  comment="Tenant: società di mediazione, call center, hotel, software house")
t("modules", "tenancy", ["loan.modules", "oam.modules"])
t("company_modules", "tenancy", ["loan.company_modules", "oam.company_modules"])
t("branches", "tenancy", ["oam.branches", "loan.branches"])
t("company_roles", "tenancy", ["oam.company_roles", "loan.company_roles"])
t("users", "tenancy", ["oam.users", "loan.users", "bpm.users", "proforma.users", "agent.users"],
  nodefault={"password"})
t("socialite_users", "tenancy", ["loan.socialite_users", "oam.socialite_users", "proforma.socialite_users", "bpm.socialite_users"])
t("resources", "tenancy", ["bpm.resources", "loan.resources", "oam.resources"])
t("employee_types", "tenancy", ["bpm.employee_types", "loan.employee_types", "oam.employee_types"])
t("employee_type_permissions", "tenancy", ["bpm.employee_type_permissions", "loan.employee_type_permissions", "oam.employee_type_permissions"],
  drop=["resource"])
t("employee_type_resource_presets", "tenancy", ["bpm.employee_type_resource_presets"])
t("employees", "tenancy", ["oam.employees", "loan.employees"])

# ---------------------------------------------------------------- registry
t("client_types", "registry", ["oam.client_types", "loan.client_types", "proforma.client_types"])
t("lead_sources", "registry", ["oam.lead_sources", "loan.lead_sources"])
t("clients", "registry", ["proforma.clients"],
  fk={"leadsource_id": "lead_sources"},
  comment="Clienti finali: persone fisiche e giuridiche, con dati KYC e consensi privacy")
t("client_relations", "registry", ["oam.client_relations", "proforma.client_relations"])
t("client_mandates", "registry", ["oam.client_mandates", "proforma.client_mandates"])
t("fornitoriroles", "registry", ["proforma.fornitoriroles"])
t("clientis", "registry", ["agent.clientis", "proforma.clientis"])
t("fornitoris", "registry", ["agent.fornitoris", "proforma.fornitoris"],
  drop=["tenant_company_id", "company_branch_id"])
t("oam_codes", "registry", ["oam.oam_codes", "loan.oam_codes"])
t("clienti_oam", "registry", ["oam.clienti_oam", "loan.clienti_oam"])
t("blacklist_clienti_fornitori", "registry", ["oam.blacklist_clienti_fornitori", "proforma.blacklist_clienti_fornitori"])
t("blacklist_clienti_employees", "registry", ["proforma.blacklist_clienti_employees"])
t("onorabilita", "registry", ["proforma.onorabilita", "oam.onorabilita"])
t("address_types", "registry", ["proforma.address_types"])
t("addresses", "registry", ["proforma.addresses"])
t("organizations", "registry", ["oam.organizations", "loan.organizations"])

# ---------------------------------------------------------------- products
t("tipoprodotto", "products", ["proforma.tipoprodotto", "oam.tipo_prodottos", "loan.tipo_prodottos"])
t("tipoprodotto_sub", "products", ["proforma.tipoprodotto_sub"])
t("tipoprodotto_sub_constraints", "products", ["proforma.tipoprodotto_sub_constraints", "oam.tipoprodotto_sub_constraints", "loan.tipoprodotto_sub_constraints"],
  fk={"role_id": "fornitoriroles"})
t("provvigioni_rules", "products", ["oam.provvigioni_rules", "loan.provvigioni_rules"],
  fk={"kind_id": "fornitoriroles"})

# ---------------------------------------------------------------- pratiche
t("pratica_stati", "pratiche", ["loan.pratica_stati", "oam.pratica_stati", "proforma.pratiches_statos"],
  rename={"proforma.pratiches_statos": {"stato_pratica": "codice", "isrejected": "is_rejected",
                                        "isworking": "is_working", "isestingued": "is_estingued"}})
t("pratica_stati_transizioni", "pratiche", ["loan.pratica_stati_transizioni", "oam.pratica_stati_transizioni"],
  fk={"stato_da_id": "pratica_stati", "stato_a_id": "pratica_stati"})
t("pratiches", "pratiche", ["proforma.pratiches"],
  drop=["stato_pratica", "tipo_prodotto"],
  add=[dict(name="company_id", base="bigint", args="", unsigned=True, nullable=True, default=None, comment="Tenant"),
       dict(name="pratica_stato_id", base="bigint", args="", unsigned=True, nullable=True, default=None, comment="Stato attuale della pratica"),
       dict(name="tipoprodotto_id", base="bigint", args="", unsigned=True, nullable=True, default=None, comment="Categoria finanziaria"),
       dict(name="fornitori_id", base="bigint", args="", unsigned=True, nullable=True, default=None, comment="Agente/collaboratore"),
       dict(name="clienti_id", base="bigint", args="", unsigned=True, nullable=True, default=None, comment="Banca/istituto erogante")],
  nodefault={"upload_at"})
t("pratica_clients", "pratiche", ["proforma.pratica_clients", "proforma.client_pratiches"],
  rename={"proforma.client_pratiches": {"pratiche_id": "pratica_id"}},
  comment="Richiedente, coobbligati e garanti di una pratica")
t("pratica_status_history", "pratiche", ["proforma.pratica_status_history"])
t("pratica_document_requests", "pratiche", ["proforma.pratica_document_requests"])
t("pratica_requisiti", "pratiche", ["loan.pratica_requisiti", "oam.pratica_requisiti"])
t("pratica_requisiti_operativi", "pratiche", ["loan.pratica_requisiti_operativi", "oam.pratica_requisiti_operativi"])
t("requisito_tipo_finanziamento", "pratiche", ["loan.requisito_tipo_finanziamento", "oam.requisito_tipo_finanziamento"])
t("oam_pratiches", "pratiche", ["oam.oam_pratiches", "loan.oam_pratiches"])
t("oam_semestrales", "pratiche", ["oam.oam_semestrales", "loan.oam_semestrales"])

# ---------------------------------------------------------------- documents
t("document_types", "documents", ["loan.document_types", "oam.document_types"],
  fk={"renewed_by_id": "document_types"})
t("documents", "documents", ["loan.documents", "oam.documents"],
  fk={"renewed_by": "users", "renewed_by_id": "documents", "user_id": "users"})
t("document_reminders", "documents", ["loan.document_reminders", "oam.document_reminders"])
t("document_schedules", "documents", ["loan.document_schedules", "oam.document_schedules"])
t("tasks", "documents", ["loan.tasks", "oam.tasks"])
t("task_document_types", "documents", ["loan.task_document_types", "oam.task_document_types"])
t("media", "documents", ["oam.media", "loan.media", "bpm.media"])
t("email_templates", "documents", ["oam.email_templates", "loan.email_templates"])
t("mail_accounts", "documents", ["oam.mail_accounts", "loan.mail_accounts"])

# ---------------------------------------------------------------- compliance
t("remediations", "compliance", ["oam.remediations", "loan.remediations"])
t("quality_reviews", "compliance", ["oam.quality_reviews"])
t("audits", "compliance", ["oam.audits", "loan.audits"])
t("audit_findings", "compliance", ["oam.audit_findings", "loan.audit_findings"])
t("audit_checklist_evaluations", "compliance", ["oam.audit_checklist_evaluations", "loan.audit_checklist_evaluations"])
t("complaints", "compliance", ["oam.complaints", "loan.complaints"])
t("complaint_registry", "compliance", ["oam.complaint_registry", "loan.complaint_registry"],
  fk={"agent_id": "fornitoris", "bank_id": "clientis"})
t("suspicious_activity_reports", "compliance", ["oam.suspicious_activity_reports", "loan.suspicious_activity_reports"])
t("training_records", "compliance", ["oam.training_records", "loan.training_records"])

# ---------------------------------------------------------------- platform
t("activity_log", "platform", ["bpm.activity_log", "oam.activity_log", "loan.activity_log"])
t("chat_messages", "platform", ["oam.chat_messages", "loan.chat_messages", "bpm.chat_messages"])

# Tabelle sorgente che spariscono perché fuse in un target con nome diverso
MERGED_AWAY = {
    "proforma.pratiches_statos": "pratica_stati",
    "proforma.client_pratiches": "pratica_clients",
    "loan.tipo_prodottos": "tipoprodotto",
    "oam.tipo_prodottos": "tipoprodotto",
}

# Chiavi esterne riconosciute dal nome della colonna, valide per tutte le tabelle core
GLOBAL_FK = {
    "company_id": "companies",
    "user_id": "users", "created_by": "users", "updated_by": "users", "deleted_by": "users",
    "uploaded_by": "users", "verified_by": "users", "requested_by": "users", "blacklisted_by": "users",
    "reviewer_user_id": "users",
    "document_id": "documents", "document_type_id": "document_types",
    "clienti_id": "clientis", "cliente_id": "clientis",
    "fornitori_id": "fornitoris", "fornitore_id": "fornitoris",
    "client_id": "clients", "employer_id": "clients", "legal_representative_id": "clients",
    "client_type_id": "client_types", "customertype_id": "client_types",
    "client_mandate_id": "client_mandates",
    "branch_id": "branches", "employee_id": "employees", "coordinated_by_id": "employees",
    "tipoprodotto_id": "tipoprodotto", "tipoprodotto_sub_id": "tipoprodotto_sub",
    "pratica_id": "pratiches", "pratica_stato_id": "pratica_stati", "pratica_requisito_id": "pratica_requisiti",
    "module_id": "modules", "resource_id": "resources", "employee_type_id": "employee_types",
    "oam_code_id": "oam_codes", "fornitorirole_id": "fornitoriroles",
    "audit_id": "audits", "remediation_id": "remediations", "quality_review_id": "quality_reviews",
    "organization_id": "organizations", "address_type_id": "address_types",
}

# Colonne *_id che restano riferimenti deboli (tabelle fuori dal core o significato non univoco)
WEAK_REFS = {"audit_checklist_item_id", "external_processor_id", "data_subject_request_id",
             "coordinated_id", "coordinated_type"}
