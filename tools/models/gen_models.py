"""Genera i modelli Eloquent del core leggendo lo schema reale dal database di prova.

Uso: python3 gen_models.py <cartella-pacchetto> [database]

Legge colonne, chiavi esterne e vincoli JSON da information_schema (il database deve
contenere le tabelle core, es. dopo `vendor/bin/phpunit`). Non tocca i modelli scritti a mano
(HANDWRITTEN) né sovrascrive nulla fuori da src/Models.
"""
import re
import subprocess
import sys
from collections import OrderedDict, defaultdict
from pathlib import Path

PKG = Path(sys.argv[1])
DB = sys.argv[2] if len(sys.argv) > 2 else "unico_core_test"
OUT = PKG / "src" / "Models"

CLASSES = {
    "activity_log": "ActivityLog", "address_types": "AddressType", "addresses": "Address",
    "audit_checklist_evaluations": "AuditChecklistEvaluation", "audit_findings": "AuditFinding",
    "audits": "Audit", "blacklist_clienti_employees": "BlacklistClienteEmployee",
    "blacklist_clienti_fornitori": "BlacklistClienteFornitore", "branches": "Branch",
    "chat_messages": "ChatMessage", "client_mandates": "ClientMandate", "client_relations": "ClientRelation",
    "client_types": "ClientType", "clienti_oam": "ClienteOam", "clientis": "Cliente", "clients": "Client",
    "companies": "Company", "company_modules": "CompanyModule", "company_roles": "CompanyRole",
    "complaint_registry": "ComplaintRegistry", "complaints": "Complaint", "document_analyses": "DocumentAnalysis",
    "document_reminders": "DocumentReminder", "document_requests": "DocumentRequest",
    "document_requirements": "DocumentRequirement", "document_schedules": "DocumentSchedule",
    "document_types": "DocumentType", "documents": "Document", "email_templates": "EmailTemplate",
    "employee_type_permissions": "EmployeeTypePermission",
    "employee_type_resource_presets": "EmployeeTypeResourcePreset", "employee_types": "EmployeeType",
    "employees": "Employee", "fornitoriroles": "FornitoriRole", "fornitoris": "Fornitore",
    "incoming_emails": "IncomingEmail", "lead_sources": "LeadSource", "mail_accounts": "MailAccount",
    "media": "Media", "modules": "Module", "oam_codes": "OamCode", "oam_pratiches": "OamPratica",
    "oam_semestrales": "OamSemestrale", "onorabilita": "Onorabilita", "organizations": "Organization",
    "pdf_module_fields": "PdfModuleField", "pdf_modules": "PdfModule", "pratica_clients": "PraticaClient",
    "pratica_stati": "PraticaStato", "pratica_stati_transizioni": "PraticaStatoTransizione",
    "pratica_status_history": "PraticaStatusHistory", "pratiches": "Pratica",
    "provvigioni_rules": "ProvvigioniRule", "quality_reviews": "QualityReview", "remediations": "Remediation",
    "resources": "Resource", "signature_request_signers": "SignatureRequestSigner",
    "signature_requests": "SignatureRequest", "socialite_users": "SocialiteUser",
    "suspicious_activity_reports": "SuspiciousActivityReport", "tasks": "Task", "tipoprodotto": "TipoProdotto",
    "tipoprodotto_sub": "TipoProdottoSub", "tipoprodotto_sub_constraints": "TipoProdottoSubConstraint",
    "training_records": "TrainingRecord", "users": "User",
}
HANDWRITTEN = {"documents", "document_types", "document_reminders", "companies", "media", "users", "mail_accounts", "incoming_emails"}
USER_FKS = {"created_by": "creator", "updated_by": "updater", "deleted_by": "deleter", "verified_by": "verifier",
            "uploaded_by": "uploader", "renewed_by": "renewer", "requested_by": "requester",
            "reviewer_user_id": "reviewer", "user_id": "user"}
ENCRYPTED = {"mail_accounts": ["imap_password", "smtp_password", "access_token", "refresh_token"],
             "socialite_users": ["token", "refresh_token"]}
HIDDEN = {"users": ["password", "remember_token", "microsoft_token", "microsoft_refresh_token"],
          "mail_accounts": ["imap_password", "smtp_password", "access_token", "refresh_token"],
          "socialite_users": ["token", "refresh_token"], "signature_requests": ["token"]}


def q(sql):
    r = subprocess.run(["mysql", "-uroot", DB, "-B", "-N", "-e", sql], capture_output=True, text=True, check=True)
    return [l.split("\t") for l in r.stdout.splitlines()]


def camel(s):
    p = s.split("_")
    return p[0] + "".join(x.title() for x in p[1:])


cols = defaultdict(OrderedDict)
for t, c, dt, ct in q("select table_name, column_name, data_type, column_type from information_schema.columns "
                      f"where table_schema='{DB}' order by table_name, ordinal_position"):
    cols[t][c] = (dt, ct)
json_cols = defaultdict(set)
for t, c in q("select table_name, constraint_name from information_schema.check_constraints "
              f"where constraint_schema='{DB}' and check_clause like '%json_valid%'"):
    json_cols[t].add(c)
fks = defaultdict(list)
for t, c, rt in q("select table_name, column_name, referenced_table_name from information_schema.key_column_usage "
                  f"where table_schema='{DB}' and referenced_table_name is not null order by table_name, column_name"):
    fks[t].append((c, rt))
tcomments = dict(q("select table_name, table_comment from information_schema.tables "
                   f"where table_schema='{DB}'"))
missing = set(cols) - set(CLASSES)
assert not missing, f"tabelle senza classe: {missing}"


def rel_name(t, c, rt):
    if c in USER_FKS and rt == "users":
        return USER_FKS[c]
    if c.endswith("_id"):
        return camel(c[:-3])
    return camel(c) + "Record"


def phpq(s):
    return "'" + s.replace("\\", "\\\\").replace("'", "\\'") + "'"


def morph_names(t):
    names = []
    for c in cols[t]:
        if c.endswith("_type") and c[:-5] + "_id" in cols[t] and cols[t][c][0] == "varchar":
            names.append(c[:-5])
    return names


# relazioni inverse (hasMany) generate solo da FK `*_id` uniche tra due tabelle
inverse = defaultdict(list)
pairs = defaultdict(list)
for t, lst in fks.items():
    for c, rt in lst:
        pairs[(t, rt)].append(c)
for (t, rt), lst in pairs.items():
    if len(lst) == 1 and lst[0].endswith("_id") and lst[0] != "user_id" and rt not in HANDWRITTEN and t != rt:
        inverse[rt].append((camel(t), t, lst[0]))

written = []
for t, cls in CLASSES.items():
    if t in HANDWRITTEN:
        continue
    c = cols[t]
    softdel = "deleted_at" in c
    imports = {"Illuminate\\Database\\Eloquent\\Model", "Unico\\Core\\Concerns\\UsesCoreConnection"}
    traits = ["UsesCoreConnection"]
    if softdel:
        imports.add("Illuminate\\Database\\Eloquent\\SoftDeletes")
        traits.insert(0, "SoftDeletes")
    casts = []
    for name, (dt, ct) in c.items():
        if name in ("created_at", "updated_at", "deleted_at", "id"):
            continue
        if name in ENCRYPTED.get(t, []):
            casts.append((name, "encrypted"))
        elif name in json_cols[t]:
            casts.append((name, "array"))
        elif ct == "tinyint(1)":
            casts.append((name, "boolean"))
        elif dt == "date":
            casts.append((name, "date"))
        elif dt in ("datetime", "timestamp"):
            casts.append((name, "datetime"))
        elif dt == "decimal":
            casts.append((name, f"decimal:{re.search(r',(\d+)', ct).group(1)}"))
    rels = []
    for col, rt in fks[t]:
        if rt == t:
            name = "parent" if col == "parent_id" else rel_name(t, col, rt)
        else:
            name = rel_name(t, col, rt)
        target = "User" if rt == "users" else CLASSES[rt]
        if name in c:
            name += "Record"
        rels.append((name, f"$this->belongsTo({target}::class, {phpq(col)})", "BelongsTo"))
        if rt == t and col == "parent_id":
            rels.append(("children", f"$this->hasMany(self::class, {phpq(col)})", "HasMany"))
    for name, it, col in inverse[t]:
        rels.append((name, f"$this->hasMany({CLASSES[it]}::class, {phpq(col)})", "HasMany"))
    for p in morph_names(t):
        rels.append((camel(p), "$this->morphTo()", "MorphTo"))
    seen = set()
    rels = [r for r in rels if not (r[0] in seen or seen.add(r[0]))]
    for _, _, k in rels:
        imports.add(f"Illuminate\\Database\\Eloquent\\Relations\\{k}")
    both = "created_at" in c and "updated_at" in c
    body = []
    body.append(f"    protected $table = {phpq(t)};\n")
    body.append("    protected $guarded = ['id'];\n")
    if not both:
        body.append("    public $timestamps = false;\n" if "created_at" not in c and "updated_at" not in c else "")
    if HIDDEN.get(t):
        body.append("    protected $hidden = [" + ", ".join(phpq(h) for h in HIDDEN[t]) + "];\n")
    if casts:
        body.append("    protected function casts(): array\n    {\n        return [\n"
                    + "".join(f"            {phpq(n)} => {phpq(k)},\n" for n, k in casts)
                    + "        ];\n    }\n")
    for name, expr, kind in rels:
        body.append(f"    public function {name}(): {kind}\n    {{\n        return {expr};\n    }}\n")
    if t == "mail_accounts" or any("User" in e for _, e, _ in rels):
        pass
    uses = "".join(f"use {i};\n" for i in sorted(imports))
    doc = tcomments[t]
    text = (f"<?php\n\nnamespace Unico\\Core\\Models;\n\n{uses}\n/** {doc}. */\nclass {cls} extends Model\n{{\n"
            f"    use {', '.join(traits)};\n\n" + "\n".join(b for b in body if b) + "}\n")
    text = text.replace("{doc}", doc)
    text = re.sub(r"\.\. \*/", ". */", text).replace(".. */", ". */")
    text = text.replace(" . */", " */").replace("./ */", " */")
    (OUT / f"{cls}.php").write_text(text)
    written.append(cls)
print(len(written), "modelli scritti")
