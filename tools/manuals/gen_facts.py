#!/usr/bin/env python3
"""Rigenera l'appendice «Fatti dal codice» del manuale tecnico di un'app Unico.

Uso: python3 tools/manuals/gen_facts.py /var/www/html/<app>

Legge il codice dell'app (rotte, comandi, modelli, pannelli, risorse, widget, servizi, chiavi .env usate dalla configurazione)
e riscrive il blocco tra <!-- facts:start --> e <!-- facts:end --> di resources/manuals/manuale-tecnico.html. Il resto del
manuale (la parte narrativa) è scritto a mano e non viene toccato.
"""
import html
import json
import re
import subprocess
import sys
from datetime import date
from pathlib import Path

START, END = '<!-- facts:start -->', '<!-- facts:end -->'


def artisan(app: Path, *args: str) -> str:
    result = subprocess.run(['php', 'artisan', *args], cwd=app, capture_output=True, text=True, timeout=240)
    return result.stdout


def esc(value) -> str:
    return html.escape(str(value if value is not None else ''))


def table(headers, rows) -> str:
    if not rows:
        return '<p><em>Nessuno.</em></p>'
    head = ''.join(f'<th>{esc(h)}</th>' for h in headers)
    body = ''.join('<tr>' + ''.join(f'<td>{cell}</td>' for cell in row) + '</tr>' for row in rows)
    return f'<table><thead><tr>{head}</tr></thead><tbody>{body}</tbody></table>'


def code(value) -> str:
    return f'<code>{esc(value)}</code>'


def routes(app: Path) -> str:
    try:
        data = json.loads(artisan(app, 'route:list', '--json', '--except-vendor') or '[]')
    except json.JSONDecodeError:
        data = []
    rows = []
    for r in data:
        uri = r.get('uri', '')
        if re.match(r'^(admin|agenti|livewire|filament|storage|_)', uri) or uri == 'up':
            continue
        rows.append([code(r.get('method', '').replace('|HEAD', '')), code('/' + uri.lstrip('/')), code(r.get('name') or ''), code((r.get('action') or '').replace('App\\Http\\Controllers\\', ''))])
    return table(['Metodo', 'Percorso', 'Nome', 'Azione'], rows)


def commands(app: Path) -> str:
    rows = []
    for f in sorted((app / 'app/Console/Commands').glob('*.php')) if (app / 'app/Console/Commands').exists() else []:
        text = f.read_text(errors='ignore')
        sig = re.search(r"\$signature\s*=\s*'([^']+)'", text) or re.search(r'\$signature\s*=\s*"([^"]+)"', text)
        desc = re.search(r"\$description\s*=\s*'((?:[^'\\]|\\.)*)'", text)
        if sig:
            rows.append([code(sig.group(1).split()[0]), esc((desc.group(1) if desc else '').replace("\\'", "'")), code(f.name)])
    return table(['Comando', 'Descrizione', 'File'], rows)


def schedule(app: Path) -> str:
    out = artisan(app, 'schedule:list', '--no-ansi').strip()
    lines = [l for l in out.splitlines() if l.strip() and not l.strip().startswith('INFO')]
    return f'<pre>{esc(chr(10).join(lines))}</pre>' if lines else '<p><em>Nessuna attività pianificata.</em></p>'


def listing(app: Path, folder: str, suffix: str = '.php', strip: str = '') -> list[str]:
    base = app / folder
    if not base.exists():
        return []
    return sorted(str(p.relative_to(base)).removesuffix(suffix) for p in base.rglob('*' + suffix) if p.is_file())


def filament(app: Path) -> str:
    parts = []
    for panel_dir in sorted((app / 'app/Filament').glob('*')) if (app / 'app/Filament').exists() else []:
        if panel_dir.is_dir():
            n = {k: len(list((panel_dir / k).rglob('*.php'))) if (panel_dir / k).exists() else 0 for k in ('Resources', 'Pages', 'Widgets', 'Actions')}
            parts.append([code(panel_dir.name), n['Resources'], n['Pages'], n['Widgets'], n['Actions']])
    return table(['Cartella app/Filament', 'File in Resources', 'Pages', 'Widgets', 'Actions'], parts)


def resources(app: Path) -> str:
    rows = []
    for f in sorted((app / 'app/Filament').rglob('*Resource.php')):
        text = f.read_text(errors='ignore')
        slug = re.search(r"\$slug\s*=\s*'([^']+)'", text)
        label = re.search(r"\$(?:navigationLabel|pluralModelLabel)\s*=\s*'([^']+)'", text)
        model = re.search(r'\$model\s*=\s*([\w\\]+)::class', text)
        rows.append([code(str(f.relative_to(app / 'app/Filament')).removesuffix('.php')), esc(label.group(1) if label else ''), code(slug.group(1) if slug else ''), code(model.group(1).split('\\')[-1] if model else '')])
    return table(['Risorsa', 'Voce di menu', 'Slug', 'Modello'], rows)


def widgets_pages(app: Path) -> str:
    rows = []
    for kind in ('Widgets', 'Pages'):
        for f in sorted((app / 'app/Filament').rglob(f'{kind}/*.php')):
            text = f.read_text(errors='ignore')
            heading = re.search(r"\$(?:heading|title|navigationLabel)\s*=\s*'([^']+)'", text)
            rows.append([esc(kind[:-1]), code(str(f.relative_to(app / 'app/Filament')).removesuffix('.php')), esc(heading.group(1) if heading else '')])
    return table(['Tipo', 'Classe', 'Titolo'], rows)


def env_keys(app: Path) -> str:
    found: dict[str, set[str]] = {}
    for folder in ('config', 'app', 'routes', 'bootstrap'):
        base = app / folder
        for f in base.rglob('*.php') if base.exists() else []:
            for name in re.findall(r"env\(\s*'([A-Z0-9_]+)'", f.read_text(errors='ignore')):
                found.setdefault(name, set()).add(str(f.relative_to(app)))
    rows = [[code(k), ', '.join(code(x) for x in sorted(v)[:3])] for k, v in sorted(found.items())]
    return table(['Variabile (.env)', 'Letta in'], rows)


def packages(app: Path) -> str:
    composer = json.loads((app / 'composer.json').read_text())
    lock = {}
    if (app / 'composer.lock').exists():
        for p in json.loads((app / 'composer.lock').read_text()).get('packages', []):
            lock[p['name']] = p['version']
    rows = [[code(name), code(constraint), code(lock.get(name, ''))] for name, constraint in sorted(composer.get('require', {}).items())]
    return table(['Pacchetto', 'Vincolo', 'Installato'], rows)


def tests(app: Path) -> str:
    base = app / 'tests'
    rows = [[code(d.name), len(list(d.rglob('*Test.php')))] for d in sorted(base.glob('*')) if d.is_dir()]
    return table(['Suite', 'File di test'], rows)


def build(app: Path) -> str:
    models = listing(app, 'app/Models')
    services = listing(app, 'app/Services')
    migrations = len(list((app / 'database/migrations').glob('*.php'))) if (app / 'database/migrations').exists() else 0
    parts = [
        START,
        '<h2 id="fatti-dal-codice">Fatti dal codice</h2>',
        f'<p>Sezione generata da <code>unico-core/tools/manuals/gen_facts.py</code> il {date.today().strftime("%d/%m/%Y")}: rispecchia il codice, non va modificata a mano (si rigenera con <code>python3 tools/manuals/gen_facts.py {esc(app)}</code>).</p>',
        '<h3 id="fatti-pacchetti">Pacchetti</h3>', packages(app),
        '<h3 id="fatti-rotte">Rotte dell\'app (web e API)</h3>', routes(app),
        '<h3 id="fatti-comandi">Comandi Artisan dell\'app</h3>', commands(app),
        '<h3 id="fatti-schedule">Attività pianificate</h3>', schedule(app),
        f'<h3 id="fatti-modelli">Modelli ({len(models)})</h3><p>{", ".join(code(m) for m in models) or "<em>Nessuno.</em>"}</p>',
        f'<h3 id="fatti-servizi">Servizi ({len(services)})</h3><p>{", ".join(code(s) for s in services) or "<em>Nessuno.</em>"}</p>',
        '<h3 id="fatti-filament">Filament</h3>', filament(app), resources(app), widgets_pages(app),
        f'<h3 id="fatti-database">Database</h3><p>{migrations} migration nell\'app (le tabelle condivise sono nelle migration di unico-core).</p>',
        '<h3 id="fatti-env">Variabili .env usate dal codice</h3><p>Solo i nomi: i valori stanno nel <code>.env</code> e non vanno mai scritti qui.</p>', env_keys(app),
        '<h3 id="fatti-test">Test</h3>', tests(app),
        END,
    ]
    return '\n'.join(parts)


def slug(text: str) -> str:
    plain = html.unescape(re.sub(r'<[^>]+>', '', text)).lower()
    plain = re.sub(r'[^a-z0-9]+', '-', plain.replace('à', 'a').replace('è', 'e').replace('é', 'e').replace('ì', 'i').replace('ò', 'o').replace('ù', 'u'))
    return plain.strip('-')[:60] or 'sezione'


def add_ids(text: str) -> str:
    """Ogni h2/h3 ha un id (ancora per la consultazione e per i rimandi)."""
    seen = set(re.findall(r'id="([^"]+)"', text))

    def fix(m):
        tag, attrs, inner = m.group(1), m.group(2), m.group(3)
        if 'id=' in attrs:
            return m.group(0)
        base = slug(inner)
        candidate, n = base, 2
        while candidate in seen:
            candidate, n = f'{base}-{n}', n + 1
        seen.add(candidate)
        return f'<{tag}{attrs} id="{candidate}">{inner}</{tag}>'

    return re.sub(r'<(h[23])([^>]*)>(.*?)</\1>', fix, text, flags=re.S)


def is_library(app: Path) -> bool:
    return not (app / 'artisan').exists()


def commands_library(app: Path) -> str:
    rows = []
    for f in sorted((app / 'src/Console').rglob('*.php')) if (app / 'src/Console').exists() else []:
        text = f.read_text(errors='ignore')
        sig = re.search(r"\$signature\s*=\s*'([^']+)'", text)
        desc = re.search(r"\$description\s*=\s*'((?:[^'\\]|\\.)*)'", text)
        if sig:
            rows.append([code(sig.group(1).split()[0]), esc((desc.group(1) if desc else '').replace("\\'", "'")), code(f.name)])
    return table(['Comando', 'Descrizione', 'File'], rows)


def build_library(app: Path) -> str:
    models = listing(app, 'src/Models')
    modules = [[code(d.name), len(list(d.rglob('*.php')))] for d in sorted((app / 'src').glob('*')) if d.is_dir()]
    migrations = len(list((app / 'database/migrations').glob('*.php')))
    keys = sorted(set(re.findall(r"env\(\s*'([A-Z0-9_]+)'", (app / 'config/unico-core.php').read_text(errors='ignore')))) if (app / 'config/unico-core.php').exists() else []
    parts = [
        START,
        '<h2 id="fatti-dal-codice">Fatti dal codice</h2>',
        f'<p>Sezione generata da <code>tools/manuals/gen_facts.py</code> il {date.today().strftime("%d/%m/%Y")}: rispecchia il codice, non va modificata a mano.</p>',
        '<h3 id="fatti-pacchetti">Pacchetti</h3>', packages(app),
        '<h3 id="fatti-moduli">Moduli (cartelle di src)</h3>', table(['Cartella', 'File PHP'], modules),
        '<h3 id="fatti-comandi">Comandi Artisan del pacchetto</h3>', commands_library(app),
        f'<h3 id="fatti-modelli">Modelli ({len(models)})</h3><p>{", ".join(code(m) for m in models)}</p>',
        f'<h3 id="fatti-database">Database</h3><p>{migrations} migration in <code>database/migrations</code>, sulla connessione <code>core</code> (tabella <code>migrations_core</code>).</p>',
        '<h3 id="fatti-env">Variabili .env di config/unico-core.php</h3>', table(['Variabile'], [[code(k)] for k in keys]),
        '<h3 id="fatti-test">Test</h3>', tests(app),
        END,
    ]
    return '\n'.join(parts)


def main() -> None:
    app = Path(sys.argv[1]).resolve()
    library = is_library(app)
    target = app / ('docs/manuale-tecnico.html' if library else 'resources/manuals/manuale-tecnico.html')
    if not target.exists():
        sys.exit(f'Manca {target}: crea prima il manuale con la parte narrativa e i segnaposto {START} {END}.')
    text = target.read_text()
    if START not in text or END not in text:
        sys.exit(f'Nel manuale mancano i segnaposto {START} … {END}.')
    new = re.sub(re.escape(START) + r'.*?' + re.escape(END), lambda _: (build_library(app) if library else build(app)), text, flags=re.S)
    target.write_text(add_ids(new))
    print(f'{target}: appendice rigenerata.')


if __name__ == '__main__':
    main()
