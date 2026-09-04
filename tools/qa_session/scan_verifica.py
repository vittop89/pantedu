#!/usr/bin/env python
"""
Scan a verifica JSON contract file and report candidate text issues.
Read-only: only reports, never modifies.
"""
import json
import re
import sys
from pathlib import Path

# Typo patterns: (regex, replacement, label)
TYPO_PATTERNS = [
    (r'\bperch[eè](?![a-zA-ZÀ-ÿ])', 'perché', 'perchè->perché'),
    (r'\bpoich[eè](?![a-zA-ZÀ-ÿ])', 'poiché', 'poiche->poiché'),
    (r'\bbench[eè](?![a-zA-ZÀ-ÿ])', 'benché', 'benche->benché'),
    (r'\bsicch[eè](?![a-zA-ZÀ-ÿ])', 'sicché', 'sicche->sicché'),
    (r'\bfinch[eè](?![a-zA-ZÀ-ÿ])', 'finché', 'finche->finché'),
    (r'\baffinch[eè](?![a-zA-ZÀ-ÿ])', 'affinché', 'affinche->affinché'),
    (r'\bpercio\b', 'perciò', 'percio->perciò'),
    (r'\bgia\b', 'già', 'gia->già'),
    (r'\bpiu\b', 'più', 'piu->più'),
    (r'\bcosi\b', 'così', 'cosi->così'),
    (r'\bcioe\b', 'cioè', 'cioe->cioè'),
    (r'\bpuo\b', 'può', 'puo->può'),
    (r'\bsisitema\b', 'sistema', 'sisitema'),
    (r'\bblack\b', 'black', 'balck->black-check'),
    (r'\bbalck\b', 'black', 'balck->black'),
    (r'\bdenomiantore\b', 'denominatore', 'denomiantore'),
    (r'\bmenoi\b', 'meno', 'menoi'),
    (r'\bllibro\b', 'libro', 'llibro'),
    (r'\bcentiania\b', 'centinaia', 'centiania'),
    (r'\boggetoo\b', 'oggetto', 'oggetoo'),
    (r'\bqaundo\b', 'quando', 'qaundo'),
    (r'\bcalolo\b', 'calcolo', 'calolo'),
    (r'\bsempice\b', 'semplice', 'sempice'),
    (r'\bprecentuale\b', 'percentuale', 'precentuale'),
    (r'\bdiscrimante\b', 'discriminante', 'discrimante'),
    (r'\bzerii\b', 'zeri', 'zerii'),
    # OCR
    (r'\bII\s+', 'Il ', 'II->Il (OCR)'),
    (r'\b11\s+', 'Il ', '11->Il (OCR)'),
    (r'\bfor\s+', 'per ', 'for->per (OCR)'),
    (r'\sand\s', ' e ', 'and->e (OCR)'),
]

# Doubled words (case-insensitive)
DOUBLED_WORDS = ['il', 'la', 'lo', 'i', 'le', 'gli', 'che', 'di', 'a', 'da', 'in',
                 'nel', 'nella', 'con', 'per', 'su', 'tra', 'fra', 'e', 'o',
                 'il loro', 'la loro', 'di un', 'di una', 'è', 'si', 'non']

# Missing space after punctuation followed by capital
MISSING_SPACE_RE = re.compile(r'([a-zàèéìòù])([.,;:!?])([A-ZÀÈÉÌÒÙ][a-zàèéìòù])')

# Paragraph break triggers (a sentence-end followed by these words inside same chunk)
PARAGRAPH_TRIGGERS = ['Quindi', 'Pertanto', 'Dunque', 'In conclusione', 'Caso',
                      'Allora', 'Inoltre', 'Riassumendo', 'In definitiva',
                      'Calcoliamo', 'Sostituendo', 'Risolviamo', 'Studio',
                      'Imposto']


def scan_text(text, ctx):
    findings = []
    if not isinstance(text, str):
        return findings

    # Typos
    for pat, rep, label in TYPO_PATTERNS:
        for m in re.finditer(pat, text):
            findings.append({
                'kind': 'typo',
                'label': label,
                'match': m.group(0),
                'pos': m.start(),
                'context': text[max(0, m.start()-30):m.end()+30],
                'ctx': ctx,
            })

    # Doubled words
    for w in DOUBLED_WORDS:
        pat = r'\b(' + re.escape(w) + r')\s+\1\b'
        for m in re.finditer(pat, text, re.IGNORECASE):
            findings.append({
                'kind': 'doubled',
                'label': f'doubled: {w}',
                'match': m.group(0),
                'pos': m.start(),
                'context': text[max(0, m.start()-30):m.end()+30],
                'ctx': ctx,
            })

    # Missing space
    for m in MISSING_SPACE_RE.finditer(text):
        findings.append({
            'kind': 'missing_space',
            'label': 'missing space after punct',
            'match': m.group(0),
            'pos': m.start(),
            'context': text[max(0, m.start()-30):m.end()+30],
            'ctx': ctx,
        })

    # Paragraph triggers inside same chunk
    for trig in PARAGRAPH_TRIGGERS:
        # sentence end then space then trigger - candidate for \n\n
        pat = r'[.:!?]\s+(' + re.escape(trig) + r')\b'
        for m in re.finditer(pat, text):
            findings.append({
                'kind': 'paragraph_trigger',
                'label': f'inline paragraph trigger: {trig}',
                'match': m.group(0),
                'pos': m.start(),
                'context': text[max(0, m.start()-30):m.end()+50],
                'ctx': ctx,
            })

    # Bullet that should be paragraph-broken
    for m in re.finditer(r'(?<!^)(?<!\n)([\s]?)•\s', text):
        if m.start() > 0:
            before = text[max(0, m.start()-2):m.start()]
            if '\n' not in before:
                findings.append({
                    'kind': 'bullet_no_break',
                    'label': 'bullet not preceded by newline',
                    'match': m.group(0),
                    'pos': m.start(),
                    'context': text[max(0, m.start()-40):m.end()+40],
                    'ctx': ctx,
                })

    return findings


def scan_chunks(chunks, ctx_prefix):
    """Scan an array of chunks. Also detect adjacent display-math without text separator."""
    findings = []
    if not isinstance(chunks, list):
        return findings

    display_re = re.compile(r'\\begin\{(array|cases|align|aligned|eqnarray|equation)\*?\}')

    for i, c in enumerate(chunks):
        if not isinstance(c, dict):
            continue
        t = c.get('type')
        ctx = f"{ctx_prefix}[{i}]"
        if t == 'text':
            findings.extend(scan_text(c.get('content', ''), ctx))
        elif t == 'latex':
            # check adjacent display
            cur = c.get('content', '')
            if display_re.search(cur) and i + 1 < len(chunks):
                nxt = chunks[i + 1]
                if isinstance(nxt, dict) and nxt.get('type') == 'latex':
                    nxtc = nxt.get('content', '')
                    if display_re.search(nxtc):
                        findings.append({
                            'kind': 'adjacent_display',
                            'label': 'adjacent display latex (no separator)',
                            'match': cur[:60] + ' || ' + nxtc[:60],
                            'pos': i,
                            'context': '',
                            'ctx': ctx,
                        })
    return findings


def scan_item(item, ctx_prefix):
    findings = []
    if not isinstance(item, dict):
        return findings
    for k in ['question', 'solution', 'answer', 'justification', 'statement', 'text']:
        v = item.get(k)
        if isinstance(v, list):
            findings.extend(scan_chunks(v, f"{ctx_prefix}.{k}"))
        elif isinstance(v, str):
            findings.extend(scan_text(v, f"{ctx_prefix}.{k}"))
    # also options
    if isinstance(item.get('options'), list):
        for j, opt in enumerate(item['options']):
            if isinstance(opt, dict):
                for ok in ['content', 'text', 'label']:
                    ov = opt.get(ok)
                    if isinstance(ov, list):
                        findings.extend(scan_chunks(ov, f"{ctx_prefix}.options[{j}].{ok}"))
                    elif isinstance(ov, str):
                        findings.extend(scan_text(ov, f"{ctx_prefix}.options[{j}].{ok}"))
            elif isinstance(opt, str):
                findings.extend(scan_text(opt, f"{ctx_prefix}.options[{j}]"))
    return findings


def main():
    if len(sys.argv) < 2:
        print('usage: scan_verifica.py <file>')
        sys.exit(1)
    fp = sys.argv[1]
    d = json.load(open(fp, 'r', encoding='utf-8'))
    all_findings = []
    if 'groups' in d:
        for gi, g in enumerate(d['groups']):
            # group intro
            intro = g.get('intro')
            if isinstance(intro, str):
                all_findings.extend(scan_text(intro, f"g[{gi}].intro"))
            elif isinstance(intro, list):
                all_findings.extend(scan_chunks(intro, f"g[{gi}].intro"))
            for ii, it in enumerate(g.get('items', [])):
                all_findings.extend(scan_item(it, f"g[{gi}].i[{ii}]"))
    # Print sorted
    print(f'FILE: {fp}')
    print(f'TOTAL findings: {len(all_findings)}')
    by_kind = {}
    for f in all_findings:
        by_kind.setdefault(f['kind'], []).append(f)
    for kind, items in by_kind.items():
        print(f'\n=== {kind} ({len(items)}) ===')
        for f in items[:200]:
            print(f"  [{f['ctx']}] {f['label']} | match={f['match'][:80]!r}")
            if f.get('context'):
                ctx = f['context'].replace('\n', '\\n')
                print(f"    ctx: ...{ctx[:140]}...")


if __name__ == '__main__':
    main()
