#!/usr/bin/env python
"""
Apply fixes to a verifica JSON contract file.
- Typos / OCR / doubled / missing space
- Insert \n\n separator between adjacent display latex chunks (per brief)
- Preserves trailing newline if original had one
- DRY-RUN by default; --apply to write.
"""
import json
import re
import sys
from pathlib import Path
from copy import deepcopy

# Typo patterns: (regex_str, replacement, label)
# Order matters; perché/poiché after balck etc.
TYPO_PATTERNS = [
    # Italian accent fixes
    (r'\bperchè\b', 'perché', 'perchè->perché'),
    (r'\bperche\b', 'perché', 'perche->perché'),
    (r'\bpoichè\b', 'poiché', 'poichè->poiché'),
    (r'\bpoiche\b', 'poiché', 'poiche->poiché'),
    (r'\bbenchè\b', 'benché', 'benchè->benché'),
    (r'\bbenche\b', 'benché', 'benche->benché'),
    (r'\bsicchè\b', 'sicché', 'sicchè->sicché'),
    (r'\bsicche\b', 'sicché', 'sicche->sicché'),
    (r'\bfinchè\b', 'finché', 'finchè->finché'),
    (r'\bfinche\b', 'finché', 'finche->finché'),
    (r'\baffinchè\b', 'affinché', 'affinchè->affinché'),
    (r'\baffinche\b', 'affinché', 'affinche->affinché'),
    (r'\bpercio\b', 'perciò', 'percio->perciò'),
    (r'\bgia\b', 'già', 'gia->già'),
    (r'\bpiu\b', 'più', 'piu->più'),
    (r'\bcosi\b', 'così', 'cosi->così'),
    (r'\bcioe\b', 'cioè', 'cioe->cioè'),
    (r'\bpuo\b', 'può', 'puo->può'),
    # specific typos
    (r'\bsisitema\b', 'sistema', 'sisitema'),
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
]

# OCR (more conservative — confirm context)
OCR_PATTERNS = [
    # II at start of sentence (after space or beginning) is OCR for Il
    # but II is also Roman numeral; safest: only when followed by lowercase word
    (r'\bII (\s*[a-zàèéìòù])', r'Il \1', 'II->Il (OCR)'),
    (r'\b11 (\s*[a-zàèéìòù])', r'Il \1', '11->Il (OCR)'),
    # 'for ' between Italian words → 'per '
    # Only between two Italian-looking lowercase words
    (r'\b([a-zàèéìòù]{2,}) for ([a-zàèéìòù]{2,})\b', r'\1 per \2', 'for->per (OCR)'),
]

# Doubled words
DOUBLED_WORD_LIST = ['il', 'la', 'lo', 'i', 'le', 'gli',
                     'che', 'di', 'a', 'da', 'in',
                     'nel', 'nella', 'con', 'per', 'su',
                     'tra', 'fra', 'e', 'o', 'è', 'si', 'non']

# Missing space after .,;:!? when followed by capital + lowercase
MISSING_SPACE_RE = re.compile(r'([a-zàèéìòù])([.,;:!?])([A-ZÀÈÉÌÒÙ][a-zàèéìòù])')

DISPLAY_RE = re.compile(r'\\begin\{(?:array|cases|align|aligned|eqnarray|equation)\*?\}')


def apply_text_fixes(text, log):
    if not isinstance(text, str):
        return text
    orig = text
    for pat, rep, label in TYPO_PATTERNS:
        new = re.sub(pat, rep, text, flags=re.IGNORECASE)
        if new != text:
            log.append(f'  TYPO {label}: applied')
            text = new
    for pat, rep, label in OCR_PATTERNS:
        new = re.sub(pat, rep, text)
        if new != text:
            log.append(f'  OCR {label}: applied')
            text = new
    # Doubled words
    for w in DOUBLED_WORD_LIST:
        pat = r'\b(' + re.escape(w) + r')(\s+)\1\b'
        new = re.sub(pat, r'\1', text, flags=re.IGNORECASE)
        if new != text:
            log.append(f'  DOUBLED {w}: applied')
            text = new
    # Missing space
    new = MISSING_SPACE_RE.sub(r'\1\2 \3', text)
    if new != text:
        log.append(f'  MISSING_SPACE: applied')
        text = new
    return text


def fix_chunks(chunks, log_prefix, log):
    """Apply text fixes, and insert \n\n separator between adjacent display latex."""
    if not isinstance(chunks, list):
        return chunks
    # First: apply text fixes inside each chunk
    new_chunks = []
    for i, c in enumerate(chunks):
        if isinstance(c, dict):
            if c.get('type') == 'text':
                sub_log = []
                new_content = apply_text_fixes(c.get('content', ''), sub_log)
                if sub_log:
                    log.append(f"{log_prefix}[{i}].text:")
                    log.extend(sub_log)
                c = {**c, 'content': new_content}
        new_chunks.append(c)

    # Second: insert \n\n separator between adjacent display latex chunks
    # walking with index
    result = []
    i = 0
    while i < len(new_chunks):
        result.append(new_chunks[i])
        if i + 1 < len(new_chunks):
            cur = new_chunks[i]
            nxt = new_chunks[i + 1]
            if (isinstance(cur, dict) and isinstance(nxt, dict)
                    and cur.get('type') == 'latex' and nxt.get('type') == 'latex'):
                cc = cur.get('content', '') or ''
                nc = nxt.get('content', '') or ''
                if DISPLAY_RE.search(cc) and DISPLAY_RE.search(nc):
                    result.append({'type': 'text', 'content': '\n\n'})
                    log.append(f"{log_prefix}[{i}/{i+1}] adjacent display: inserted \\n\\n separator")
        i += 1
    return result


def fix_item(item, log_prefix, log):
    if not isinstance(item, dict):
        return item
    new = dict(item)
    for k in ['question', 'solution', 'answer', 'justification', 'statement', 'text']:
        v = new.get(k)
        if isinstance(v, list):
            new[k] = fix_chunks(v, f"{log_prefix}.{k}", log)
        elif isinstance(v, str):
            sub_log = []
            new[k] = apply_text_fixes(v, sub_log)
            if sub_log:
                log.append(f"{log_prefix}.{k}:")
                log.extend(sub_log)
    if isinstance(new.get('options'), list):
        opts = []
        for j, opt in enumerate(new['options']):
            if isinstance(opt, dict):
                opt = dict(opt)
                for ok in ['content', 'text', 'label']:
                    ov = opt.get(ok)
                    if isinstance(ov, list):
                        opt[ok] = fix_chunks(ov, f"{log_prefix}.options[{j}].{ok}", log)
                    elif isinstance(ov, str):
                        sub_log = []
                        opt[ok] = apply_text_fixes(ov, sub_log)
                        if sub_log:
                            log.append(f"{log_prefix}.options[{j}].{ok}:")
                            log.extend(sub_log)
                opts.append(opt)
            elif isinstance(opt, str):
                sub_log = []
                opts.append(apply_text_fixes(opt, sub_log))
                if sub_log:
                    log.append(f"{log_prefix}.options[{j}]:")
                    log.extend(sub_log)
            else:
                opts.append(opt)
        new['options'] = opts
    return new


def main():
    if len(sys.argv) < 2:
        print('usage: fix_verifica.py <file> [--apply]')
        sys.exit(1)
    fp = sys.argv[1]
    apply = '--apply' in sys.argv

    with open(fp, 'rb') as f:
        original_bytes = f.read()
    # Detect line ending
    newline = '\r\n' if b'\r\n' in original_bytes[:4096] else '\n'
    original_text = original_bytes.decode('utf-8')
    # Normalize for json.loads
    d = json.loads(original_text)

    log = []
    new_d = deepcopy(d)
    if 'groups' in new_d:
        for gi, g in enumerate(new_d['groups']):
            intro = g.get('intro')
            if isinstance(intro, str):
                sub_log = []
                g['intro'] = apply_text_fixes(intro, sub_log)
                if sub_log:
                    log.append(f"g[{gi}].intro:")
                    log.extend(sub_log)
            elif isinstance(intro, list):
                g['intro'] = fix_chunks(intro, f"g[{gi}].intro", log)
            new_items = []
            for ii, it in enumerate(g.get('items', [])):
                new_items.append(fix_item(it, f"g[{gi}].i[{ii}]", log))
            g['items'] = new_items

    # Serialize: try to preserve formatting
    # Detect indent: peek into original (normalize CRLF for check)
    sample = original_text.replace('\r\n', '\n')[:400]
    indent = None
    if '\n    "' in sample:
        indent = 4
    elif '\n  "' in sample:
        indent = 2
    new_text = json.dumps(new_d, ensure_ascii=False, indent=indent)
    # Restore detected newline style
    if newline == '\r\n':
        new_text = new_text.replace('\n', '\r\n')
    # Preserve trailing newline if original had one
    if original_text.endswith(newline) and not new_text.endswith(newline):
        new_text += newline

    changed = (new_text != original_text)
    print(f'FILE: {fp}')
    print(f'newline: {repr(newline)}')
    print(f'indent: {indent}')
    print(f'changes_log_count: {len(log)}')
    for ln in log:
        print(ln)
    print(f'text_diff: {"YES" if changed else "NO"}')

    if apply and changed:
        # validate
        json.loads(new_text)
        with open(fp, 'wb') as f:
            f.write(new_text.encode('utf-8'))
        print('APPLIED')
    elif apply:
        print('NO CHANGES - skipped write')
    else:
        print('DRY RUN - use --apply to write')


if __name__ == '__main__':
    main()
