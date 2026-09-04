r"""
Replace HTML entities `&lt;` `&gt;` `&amp;` in text/latex chunk content.

Background: legacy content (migrated from PHP HTML) ha `&lt;` invece di `<`
in regioni math `\(...\)`. PHP renderer per text chunks fa htmlspecialchars
del content → `&lt;` diventa `&amp;lt;` → browser mostra `&lt;` letterale
→ MathJax non decodifica HTML entity → renderizza come testo grezzo.

Fix: replace `&lt;` → `<`, `&gt;` → `>`, `&amp;` → `&` in chunks.
"""
import json, os, sys

ROOTS = ['storage/objects/institutes/106/private/77/eser',
         'storage/objects/institutes/106/private/77/verifiche']

ENTITIES = [
    ('&lt;', '<'),
    ('&gt;', '>'),
    ('&amp;', '&'),
]


def fix_content(s: str) -> tuple:
    if not isinstance(s, str):
        return s, 0
    n = 0
    for ent, char in ENTITIES:
        if ent in s:
            n += s.count(ent)
            s = s.replace(ent, char)
    return s, n


def walk_fix(node, changes):
    if isinstance(node, dict):
        t = node.get('type')
        if t in ('text', 'latex') and isinstance(node.get('content'), str):
            new, n = fix_content(node['content'])
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
            except Exception:
                continue
            changes = []
            walk_fix(c, changes)
            if changes:
                total[fn] = sum(changes)
                if apply:
                    with open(path, 'w', encoding='utf-8') as f:
                        json.dump(c, f, ensure_ascii=False, indent=4)

for fn, n in sorted(total.items(), key=lambda x: -x[1]):
    print(f'  {fn}: {n}')
print(f'\nTotal: {sum(total.values())} entities in {len(total)} files. {"APPLIED" if apply else "DRY-RUN"}')
