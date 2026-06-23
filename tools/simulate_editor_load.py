"""
Simula _extractRawWithTikz nuovo vs vecchio per content 59 (Radicali eser).
Mostra cosa appare in editor textarea dopo LOAD per il quesito + soluzione.

Note: simula la logica JS in Python. Non perfettamente identica ma sufficiente
per validare diff visivo.
"""
import json
import re

PATH = 'storage/objects/institutes/106/private/77/eser/3.0_MAT-Radicali-SCI2.contract.json'


def chunk_kind(chunk):
    """Classifica chunk: inline o block."""
    t = chunk.get('type', '')
    if t in ('text', 'latex', 'badge'):
        return 'inline'
    return 'block'  # tikz, geogebra, list, etc.


def chunk_raw(chunk):
    """Raw content del chunk (analogo a data-raw)."""
    t = chunk.get('type', '')
    if t in ('text', 'latex'):
        return chunk.get('content', '')
    if t == 'tikz':
        body = chunk.get('script', '')
        return f'<script type="text/tikz">{body}</script>'
    if t == 'geogebra':
        return '<geogebra block>'
    return chunk.get('content', '') or chunk.get('script', '')


def extract_old(chunks):
    """Vecchia logica: parts.join('\\n')."""
    parts = [chunk_raw(c) for c in chunks if chunk_raw(c)]
    return '\n'.join(parts).strip()


def extract_new(chunks):
    """Nuova logica: inline-inline = ' ' (skip se whitespace/punteggiatura);
    altri = '\\n'."""
    parts = [(chunk_raw(c), chunk_kind(c)) for c in chunks if chunk_raw(c)]
    out = ''
    for i, (raw, kind) in enumerate(parts):
        if i == 0:
            out = raw
            continue
        prev_raw, prev_kind = parts[i - 1]
        if prev_kind == 'inline' and kind == 'inline':
            if re.search(r'\s$', prev_raw) or re.match(r'[\s,.;:!?)\]]', raw):
                sep = ''
            else:
                sep = ' '
        else:
            sep = '\n'
        out += sep + raw
    return out.strip()


with open(PATH, 'r', encoding='utf-8') as f:
    c = json.load(f)

for gi, g in enumerate(c.get('groups', [])):
    print(f'\n=== GROUP {gi}: {g.get("title", "")} (intro={g.get("intro", "")!r}) ===')
    for ii, it in enumerate(g.get('items', [])):
        print(f'\n--- ITEM {ii} (id={it.get("id", "?")[:30]}) ---')
        for field in ('question', 'solution'):
            chunks = it.get(field, [])
            if not chunks:
                continue
            print(f'\n  FIELD: {field}  (chunks={len(chunks)})')
            old = extract_old(chunks)
            new = extract_new(chunks)
            print('  === OLD (join \\n) ===')
            for line in old.split('\n'):
                print(f'  | {line}')
            print('  === END OLD ===')
            print('  === NEW (smart) ===')
            for line in new.split('\n'):
                print(f'  | {line}')
            print('  === END NEW ===')
            old_lines = old.count('\n') + 1
            new_lines = new.count('\n') + 1
            print(f'  > linee OLD={old_lines}  NEW={new_lines}  diff={old_lines - new_lines}')
