r"""
Fix pgfplots: collassare blank lines dentro \begin{axis}[...] options.
PGFPlots interpreta \par (blank line) come fine argomento → "Runaway argument".

Anche fix: ; spuri dopo \def\name{value} possono causare warning ma compilano ok.
Per sicurezza vengono rimossi anche quelli.
"""
import json, os, re, sys

ROOTS = ['storage/objects/institutes/106/private/77/eser',
         'storage/objects/institutes/106/private/77/verifiche']


def fix_script(s: str) -> tuple:
    if not s:
        return s, 0
    n = 0
    # 1) Collapse blank lines inside \begin{axis}[...] options
    def collapse_options(match):
        nonlocal n
        body = match.group(1)
        new_body = re.sub(r'\n\s*\n+', '\n', body)
        if new_body != body:
            n += 1
        return '\\begin{axis}[' + new_body + ']'
    s = re.sub(r'\\begin\{axis\}\[([\s\S]*?)\]', collapse_options, s)
    # 2) Strip ; dopo \def\name{value} (cosmetic, but safer)
    new_s, n2 = re.subn(r'(\\def\\\w+\{[^}]*\});', r'\1', s)
    if n2:
        n += n2
        s = new_s
    new_s, n3 = re.subn(r'(\\pgfmathsetmacro\{\\\w+\}\{[^}]*\});', r'\1', s)
    if n3:
        n += n3
        s = new_s
    return s, n


def walk_fix(node, changes):
    if isinstance(node, dict):
        if node.get('type') == 'tikz':
            script = node.get('script', '') or ''
            new, n = fix_script(script)
            if n:
                node['script'] = new
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
            except:
                continue
            changes = []
            walk_fix(c, changes)
            if changes:
                total[fn] = sum(changes)
                if apply:
                    with open(path, 'w', encoding='utf-8') as f:
                        json.dump(c, f, ensure_ascii=False, indent=4)

for fn, n in sorted(total.items(), key=lambda x: -x[1]):
    print(f'  {fn}: {n} fixes')
print(f'\nTotal: {sum(total.values())} in {len(total)} files. {"APPLIED" if apply else "DRY-RUN"}')
