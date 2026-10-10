"""Genera le migration del pacchetto unico-core a partire dai dump e da spec.py."""
import copy
import json
import re
import sys
from collections import OrderedDict
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from comments import GENERIC, SPECIFIC, TABLES  # noqa: E402
from spec import AREAS, GLOBAL_FK, MERGED_AWAY, STRING_MORPH_IDS, T, WEAK_REFS  # noqa: E402

MISSING = []

S = json.load(open(Path(__file__).parent / "schema2.json"))
OUT = Path(sys.argv[1])
MIG = OUT / "database" / "migrations"
MIG.mkdir(parents=True, exist_ok=True)

DATE_PREFIX = "2026_10_10_0000"


def php(s):
    return "'" + str(s).replace("\\", "\\\\").replace("'", "\\'") + "'"


def is_uuid_literal(d):
    return bool(re.fullmatch(r"'[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'", d or ""))


def merge(name, spec):
    cols = OrderedDict()
    uniques, indexes, src_fks = [], [], {}
    comments = []
    for i, src in enumerate(spec["src"]):
        app, tbl = src.split(".")
        x = S[app][tbl]
        ren = spec["rename"].get(src, {})
        if x["comment"]:
            comments.append(x["comment"])
        for c in x["cols"]:
            n = ren.get(c["name"], c["name"])
            if n in spec["drop"]:
                continue
            if n not in cols:
                c2 = copy.deepcopy(c)
                c2["name"] = n
                c2["origin"] = [app]
                c2["renamed_from"] = c["name"] if n != c["name"] else None
                if i > 0:
                    c2["nullable"] = True
                cols[n] = c2
            else:
                cols[n]["origin"].append(app)
                if not cols[n]["comment"] and c["comment"]:
                    cols[n]["comment"] = c["comment"]
        for k in x["keys"]:
            kc = [ren.get(c, c) for c in k["cols"]]
            if any(c in spec["drop"] for c in kc):
                continue
            if k["kind"] == "UNIQUE":
                uniques.append((k["name"], kc))
            elif k["kind"] == "INDEX" and i == 0:
                indexes.append((k["name"], kc))
            elif k["kind"] == "FK" and kc[0] not in src_fks:
                src_fks[kc[0]] = (k["ref"], k["ondelete"])
    uniques[:0] = spec["unique"]
    indexes += spec["index"]
    for a in spec["add"]:
        c2 = dict(a, origin=["nuovo"], renamed_from=None, auto=False)
        cols[a["name"]] = c2
    for n, p in spec["patch"].items():
        cols[n].update(p)
    for n, ty in spec["types"].items():
        cols[n]["base"], cols[n]["args"] = ty, ""
    return cols, uniques, indexes, src_fks, spec["comment"] or (comments[0] if comments else "")


def column_method(c):
    n, b, a, u = c["name"], c["base"], c["args"], c["unsigned"]
    if b == "varchar":
        return f"string({php(n)}, {a})" if a and a != "255" else f"string({php(n)})"
    if b == "char":
        return f"char({php(n)}, {a})"
    simple = {"text": "text", "tinytext": "tinyText", "mediumtext": "mediumText", "longtext": "longText",
              "date": "date", "datetime": "dateTime", "timestamp": "timestamp", "time": "time", "year": "year",
              "json": "json", "double": "double", "float": "float", "blob": "binary", "longblob": "binary"}
    if b in simple:
        return f"{simple[b]}({php(n)})"
    if b == "tinyint" and a == "1":
        return f"boolean({php(n)})"
    ints = {"tinyint": "tinyInteger", "smallint": "smallInteger", "mediumint": "mediumInteger",
            "int": "integer", "bigint": "bigInteger"}
    if b in ints:
        m = ints[b]
        return f"unsigned{m[0].upper()}{m[1:]}({php(n)})" if u else f"{m}({php(n)})"
    if b == "decimal":
        p, s = (a.split(",") + ["0"])[:2]
        return f"decimal({php(n)}, {p.strip()}, {s.strip()})"
    if b in ("enum", "set"):
        vals = [v.replace("''", "'") for v in re.findall(r"'((?:[^']|'')*)'", a)]
        return f"{b}({php(n)}, [" + ", ".join(php(v) for v in vals) + "])"
    raise ValueError(f"tipo non gestito {b} per {n}")


def default_clause(c, nodefault):
    d = c["default"]
    if d is None or d == "NULL" or c["name"] in nodefault or is_uuid_literal(d) or "$2y$" in (d or ""):
        return ""
    if d.upper().startswith("CURRENT_TIMESTAMP"):
        return "->useCurrent()"
    if c["base"] in ("timestamp", "datetime") and re.fullmatch(r"'\d{4}-\d{2}-\d{2}.*'", d):
        return "->useCurrent()"
    raw = d[1:-1].replace("''", "'") if d.startswith("'") else d
    if c["base"] == "tinyint" and c["args"] == "1":
        return f"->default({'true' if raw not in ('0', '') else 'false'})"
    if c["base"] in ("tinyint", "smallint", "mediumint", "int", "bigint", "decimal", "double", "float"):
        return f"->default({raw})"
    return f"->default({php(raw)})"


def find_comment(table, col, existing, ref=None):
    """Commento di una colonna: sorgente, poi SPECIFIC, GENERIC, infine dedotto dal nome."""
    c = existing or SPECIFIC.get(f"{table}.{col}") or GENERIC.get(col)
    if not c and col.endswith("_type"):
        c = "Alias del modello collegato (morph map)"
    if not c and col.endswith("_id"):
        c = f"Riferimento a {ref}" if ref else "ID del record collegato"
    if not c:
        MISSING.append(f"{table}.{col}")
    return c


def cm_of(table, col, existing, ref=None):
    c = find_comment(table, col, existing, ref)
    return f"->comment({php(c)})" if c else ""


def build(name, spec):
    cols, uniques, indexes, src_fks, tcomment = merge(name, spec)
    names = list(cols)
    morphs = {}
    for n in names:
        if n.endswith("_type") and cols[n]["base"] == "varchar":
            p = n[:-5]
            if p + "_id" in cols:
                morphs[p] = cols[n]["nullable"] or cols[p + "_id"]["nullable"]
    morph_cols = {p + s for p in morphs for s in ("_type", "_id")}
    lines, fks, notes, tail = [], [], [], []
    done = set()
    for n in names:
        if n in done:
            continue
        c = cols[n]
        cm = cm_of(name, n, c.get("comment"))
        if n in morph_cols:
            p = n[:-5] if n.endswith("_type") else n[:-3]
            if p in morphs and p + "_type" not in done:
                nl = "->nullable()" if morphs[p] else ""
                lines.append(f"$table->string({php(p + '_type')}){nl}"
                             f"{cm_of(name, p + '_type', cols[p + '_type'].get('comment'))};")
                id_col = (f"string({php(p + '_id')}, 36)" if (name, p) in STRING_MORPH_IDS
                          else f"unsignedBigInteger({php(p + '_id')})")
                lines.append(f"$table->{id_col}{nl}"
                             f"{cm_of(name, p + '_id', cols[p + '_id'].get('comment'))};")
                lines.append(f"$table->index([{php(p + '_type')}, {php(p + '_id')}]);")
                done |= {p + "_type", p + "_id"}
                if cols[p + "_id"]["base"] in ("char", "varchar"):
                    notes.append(f"`{p}_id` da {cols[p + '_id']['base']} a bigint (morph)")
            continue
        if n == "id":
            lines.append(f"$table->id(){cm};")
            if c["base"] != "bigint":
                notes.append(f"`id` da {c['base']}({c['args']}) a bigint auto-increment")
            done.add(n)
            continue
        if n in ("created_at", "updated_at") and {"created_at", "updated_at"} <= set(cols):
            tail.append(f"$table->timestamp('created_at')->nullable(){cm_of(name, 'created_at', cols['created_at'].get('comment'))};")
            tail.append(f"$table->timestamp('updated_at')->nullable(){cm_of(name, 'updated_at', cols['updated_at'].get('comment'))};")
            done |= {"created_at", "updated_at"}
            continue
        if n == "deleted_at":
            tail.append(f"$table->timestamp('deleted_at')->nullable(){cm};")
            done.add(n)
            continue
        ref = None
        if n not in WEAK_REFS:
            ref = spec["fk"].get(n) or (name if n == "parent_id" else None) or GLOBAL_FK.get(n)
            if not ref and n in src_fks and src_fks[n][0] in T:
                ref = src_fks[n][0]
        if ref and ref in T:
            cm = cm_of(name, n, c.get("comment"), ref)
            ondel = (src_fks.get(n, (None, None))[1] or "").upper()
            mode = {"CASCADE": "cascade", "SET NULL": "null", "RESTRICT": "restrict"}.get(
                ondel, "null" if c["nullable"] else "cascade")
            if mode == "null":
                c["nullable"] = True
            lines.append(f"$table->foreignId({php(n)}){'->nullable()' if c['nullable'] else ''}{cm};")
            fks.append((n, ref, mode))
            if c["base"] != "bigint":
                notes.append(f"`{n}` da {c['base']}({c['args']}) a bigint, FK → `{ref}`")
            done.add(n)
            continue
        if c["base"] == "char" and c["args"] == "36" and (n.endswith("_id") or n == "renewed_by"):
            c = dict(c, base="bigint", args="", unsigned=True)
            notes.append(f"`{n}` da char(36) a bigint (riferimento debole)")
        lines.append(f"$table->{column_method(c)}{'->nullable()' if c['nullable'] else ''}"
                     f"{default_clause(c, spec['nodefault'])}{cm};")
        done.add(n)
    lines += tail
    final_cols = set(done)
    fk_cols = {f[0] for f in fks}
    seen = set()
    for kname, kc in uniques:
        if not set(kc) <= final_cols or tuple(kc) in seen or kc == ["id"]:
            continue
        seen.add(tuple(kc))
        nm = f", {php(kname)}" if kname and len(kname) <= 64 else ""
        lines.append(f"$table->unique([{', '.join(php(x) for x in kc)}]{nm});")
    for kname, kc in indexes:
        if not set(kc) <= final_cols or tuple(kc) in seen:
            continue
        if len(kc) == 1 and kc[0] in fk_cols:
            continue
        if set(kc) <= morph_cols:
            continue
        if kname and kname.endswith("_foreign"):
            continue
        seen.add(tuple(kc))
        nm = f", {php(kname)}" if kname and len(kname) <= 64 else ""
        lines.append(f"$table->index([{', '.join(php(x) for x in kc)}]{nm});")
    tcomment = tcomment or TABLES.get(name)
    if not tcomment:
        MISSING.append(f"{name} (tabella)")
    if tcomment:
        lines.append(f"$table->comment({php(tcomment)});")
    return lines, fks, notes, cols


HEADER = """<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    /**
     * Connessione del database core, configurabile in config/unico-core.php.
     */
    public function getConnection()
    {
        return config('unico-core.connection');
    }
"""

all_fks, docs = {}, {}
for idx, (area, label) in enumerate(AREAS, start=1):
    tables = [n for n, s in T.items() if s["area"] == area]
    body = [HEADER, f"\n    /**\n     * {label}.\n     */\n    public function up(): void\n    {{\n"]
    for n in tables:
        lines, fks, notes, cols = build(n, T[n])
        all_fks[n] = fks
        docs[n] = (T[n], notes, cols, fks)
        body.append(f"        Schema::create({php(n)}, function (Blueprint $table) {{\n")
        body += [f"            {ln}\n" for ln in lines]
        body.append("        });\n\n")
    body[-1] = body[-1].rstrip("\n") + "\n"
    body.append("    }\n\n    public function down(): void\n    {\n")
    body += [f"        Schema::dropIfExists({php(n)});\n" for n in reversed(tables)]
    body.append("    }\n};\n")
    (MIG / f"{DATE_PREFIX}{idx:02d}_create_core_{area}_tables.php").write_text("".join(body))

fk_body = [HEADER, "\n    /**\n     * Vincoli di integrità tra le tabelle core, aggiunti dopo la creazione di tutte le tabelle.\n     */\n    public function up(): void\n    {\n"]
down = []
for n, fks in all_fks.items():
    if not fks:
        continue
    fk_body.append(f"        Schema::table({php(n)}, function (Blueprint $table) {{\n")
    for col, ref, mode in fks:
        m = {"cascade": "cascadeOnDelete", "null": "nullOnDelete", "restrict": "restrictOnDelete"}[mode]
        fk_body.append(f"            $table->foreign({php(col)})->references('id')->on({php(ref)})->{m}();\n")
    fk_body.append("        });\n\n")
    down.append(f"        Schema::table({php(n)}, function (Blueprint $table) {{\n"
                + "".join(f"            $table->dropForeign([{php(c)}]);\n" for c, _, _ in fks) + "        });\n\n")
fk_body[-1] = fk_body[-1].rstrip("\n") + "\n"
fk_body.append("    }\n\n    public function down(): void\n    {\n")
down[-1] = down[-1].rstrip("\n") + "\n"
fk_body += down
fk_body.append("    }\n};\n")
(MIG / f"{DATE_PREFIX}{len(AREAS) + 1:02d}_add_core_foreign_keys.php").write_text("".join(fk_body))

# ------------------------------------------------------------- mappa dello schema
md = ["# Mappa dello schema core\n",
      "Generata dai dump dei 5 database di sviluppo (unicoagent, unicobpm, unicoloan, unicooam, proforma).\n",
      "Per ogni tabella: sorgenti in ordine di priorità (la prima decide tipi e vincoli), "
      "colonne con l'app di provenienza, conversioni e chiavi esterne.\n"]
for area, label in AREAS:
    md.append(f"\n## {label}\n")
    for n, (spec, notes, cols, fks) in docs.items():
        if spec["area"] != area:
            continue
        md.append(f"\n### `{n}`\n")
        md.append("Sorgenti: " + ", ".join(f"`{s}`" for s in spec["src"]) + "\n")
        only = OrderedDict()
        for c in cols.values():
            key = "+".join(sorted(set(c["origin"])))
            only.setdefault(key, []).append(c["name"] + (f" (era `{c['renamed_from']}`)" if c["renamed_from"] else ""))
        md.append("\n| Provenienza | Colonne |\n|---|---|\n")
        for k, v in only.items():
            md.append(f"| {k} | {', '.join(v)} |\n")
        if spec["drop"]:
            md.append(f"\nColonne eliminate: {', '.join(f'`{d}`' for d in sorted(spec['drop']))}\n")
        if notes:
            md.append("\nConversioni: " + "; ".join(notes) + "\n")
        if fks:
            md.append("\nFK: " + ", ".join(f"`{c}` → `{r}`" for c, r, _ in fks) + "\n")
md.append("\n## Tabelle sorgente fuse in un'altra\n\n")
md += [f"- `{s}` → `{d}`\n" for s, d in MERGED_AWAY.items()]
(OUT / "docs").mkdir(exist_ok=True)
(OUT / "docs" / "schema-map.md").write_text("".join(md))

if MISSING:
    print("SENZA COMMENTO:", *MISSING, sep="\n  ")
    sys.exit(1)
print("tabelle:", len(T), "fk:", sum(len(v) for v in all_fks.values()))
