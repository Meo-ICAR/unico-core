#!/usr/bin/env python3
"""Impagina un manuale HTML con lo stile comune delle app Unico.

Uso: python3 tools/manuals/make_page.py <file-frammento.html> <destinazione.html> "<Titolo>" "<Sottotitolo>"

Il frammento contiene solo il corpo (sezioni h2/h3, tabelle, paragrafi). Nel manuale tecnico va incluso il segnaposto
<!-- facts:start --><!-- facts:end -->, riempito poi da gen_facts.py. Ogni h2/h3 riceve un id (ancora per la consultazione).
"""
import html
import re
import sys
from pathlib import Path

STYLE = """:root { color-scheme: light; }
        body { margin: 0 auto; max-width: 54rem; padding: 2rem 1.25rem 4rem; color: #1c1917; background: #fff; font: 1.02rem/1.55 Georgia, "Times New Roman", serif; }
        h1, h2, h3 { font-family: "Segoe UI", system-ui, sans-serif; line-height: 1.25; }
        h1 { font-size: 1.85rem; margin-bottom: 0.25rem; }
        h2 { font-size: 1.3rem; margin-top: 2.2rem; border-bottom: 1px solid #e7e5e4; padding-bottom: 0.3rem; }
        h3 { font-size: 1.08rem; margin-top: 1.4rem; }
        .sottotitolo { margin-top: 0; color: #57534e; }
        table { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: 0.92rem; }
        th, td { border: 1px solid #d6d3d1; padding: 0.4rem 0.55rem; vertical-align: top; text-align: left; }
        th { background: #f5f5f4; font-family: "Segoe UI", system-ui, sans-serif; }
        code, pre { font-family: ui-monospace, "Cascadia Code", monospace; font-size: 0.86em; }
        pre { background: #fafaf9; border: 1px solid #e7e5e4; padding: 0.75rem 1rem; overflow-x: auto; }
        .gap { background: #fffbeb; border-left: 4px solid #d97706; padding: 0.75rem 1rem; margin: 1rem 0; }
        .nota { background: #f0f9ff; border-left: 4px solid #0284c7; padding: 0.75rem 1rem; margin: 1rem 0; }
        a { color: #9a3412; }
        @media print {
            body { max-width: none; padding: 0; }
            a { color: inherit; text-decoration: none; }
            h2, h3 { break-after: avoid; }
            table, pre, .gap, .nota { break-inside: avoid; }
        }"""


def slug(text: str) -> str:
    plain = html.unescape(re.sub(r'<[^>]+>', '', text)).lower()
    for a, b in (('à', 'a'), ('è', 'e'), ('é', 'e'), ('ì', 'i'), ('ò', 'o'), ('ù', 'u')):
        plain = plain.replace(a, b)
    return re.sub(r'[^a-z0-9]+', '-', plain).strip('-')[:60] or 'sezione'


def add_ids(text: str) -> str:
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


def main() -> None:
    fragment, target, title, subtitle = Path(sys.argv[1]).read_text(), Path(sys.argv[2]), sys.argv[3], sys.argv[4]
    toc = ''.join(f'<li><a href="#{m.group(1)}">{m.group(2)}</a></li>' for m in re.finditer(r'<h2 id="([^"]+)"[^>]*>(.*?)</h2>', add_ids(fragment), flags=re.S))
    if '<!-- facts:start -->' in fragment:
        toc += '<li><a href="#fatti-dal-codice">Fatti dal codice</a></li>'
    body = add_ids(fragment)
    page = f"""<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{html.escape(title)}</title>
    <style>
        {STYLE}
    </style>
</head>
<body>
    <h1>{html.escape(title.split(' — ')[0])}</h1>
    <p class="sottotitolo">{subtitle}</p>
    <nav aria-label="Indice"><ol>{toc}</ol></nav>
{body}
</body>
</html>
"""
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(page)
    print(f'{target}: {len(page)} byte')


if __name__ == '__main__':
    main()
