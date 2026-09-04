r"""
Rimuove `\color{black}` da text/latex chunks (esercizi + verifiche).

Pattern:
- `\color{black}` → rimosso
- `\color{black}{...}` → rimane `{...}` (content preserved)

In dark mode \color{black} forza testo nero su sfondo scuro → illeggibile.
Senza il \color, il testo usa default color (white in dark, black in light).

Scope: tutti i text/latex chunks in eser/ + verifiche/ contract.json.
Tikz scripts NON toccati (rendono in PDF dove \color black è corretto).
"""
import json, os, re, sys

ROOTS = ['storage/objects/institutes/106/private/77/eser',
         'storage/objects/institutes/106/private/77/verifiche']


def strip_color_black(s: str) -> tuple:
    """Returns (new_string, count_removed)."""
    if not isinstance(s, str) or '\\color{black}' not in s:
        return s, 0
    # Just remove the \color{black} command. Any following {...} group stays
    # as plain group (no color override). LaTeX/MathJax tolerates {x}.
    new_s = s.replace('\\color{black}', '')
    n = (len(s) - len(new_s)) // len('\\color{black}')
    return new_s, n


def walk_fix(node, changes):
    if isinstance(node, dict):
        t = node.get('type')
        # Only text + latex chunks (skip tikz scripts: \color black is OK in PDF)
        if t in ('text', 'latex') and isinstance(node.get('content'), str):
            new, n = strip_color_black(node['content'])
            if n:
                node['content'] = new
                changes.append(n)
        for v in node.values():
            if isinstance(v, (dict, list)):
                walk_fix(v, changes)
    elif isinstance(node, list):
        for v in node:
            if isinstance(v, (dict, list)):
                walk_fix(v, changes)


apply = '--apply' in sys.argv
total = {}
for root in ROOTS:
    if not os.path.isdir(root):
        continue
    for dp, _, files in os.walk(root):
        for fn in sorted(files):
            if not fn.endswith('.contract.json'):
                continue
            path = os.path.join(dp, fn)
            try:
                with open(path, 'r', encoding='utf-8') as f:
                    c = json.load(f)
            except Exception as e:
                print(f'SKIP {path}: {e}')
                continue
            changes = []
            walk_fix(c, changes)
            if changes:
                total[fn] = sum(changes)
                if apply:
                    with open(path, 'w', encoding='utf-8') as f:
                        json.dump(c, f, ensure_ascii=False, indent=4)

for fn, n in sorted(total.items(), key=lambda x: -x[1]):
    print(f'  {fn}: {n} removed')
print(f'\nTotal: {sum(total.values())} \\color{{black}} removed in {len(total)} files. {"APPLIED" if apply else "DRY-RUN"}')
