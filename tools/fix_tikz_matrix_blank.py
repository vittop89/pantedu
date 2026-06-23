r"""Fix blank line inside tikz \matrix body (before };)."""
import json, os, re, sys

ROOTS = ['storage/objects/institutes/106/private/77/eser',
         'storage/objects/institutes/106/private/77/verifiche']


def fix_script(s: str) -> tuple:
    if not s or '\\matrix' not in s:
        return s, 0
    new_s, n = re.subn(r'\\\\\n\s*\n(\s*\};)', r'\\\\\n\1', s)
    return new_s, n


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

for fn, n in sorted(total.items()):
    print(f'  {fn}: {n} fixes')
print(f'\nTotal: {sum(total.values())} in {len(total)}. {"APPLIED" if apply else "DRY-RUN"}')
