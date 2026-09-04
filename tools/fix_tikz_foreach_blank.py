r"""
Fix blank lines inside \foreach \var in {...} argument list.
TeX interpreta \par (blank line) come fine arg → pgffor@normal@list error.

Pattern: \foreach <vars> in {...with...blank...lines...} {body}
"""
import json, os, re, sys

ROOTS = ['storage/objects/institutes/106/private/77/eser',
         'storage/objects/institutes/106/private/77/verifiche']


def fix_script(s: str) -> tuple:
    if not s or '\\foreach' not in s:
        return s, 0
    n = 0
    # Pattern: \foreach ... in { content } — collapse blank lines inside { content }.
    # Brace-matching parser: find \foreach...in, then skip to next {...} as arg list.

    out = []
    i = 0
    while i < len(s):
        # Find next \foreach
        m = re.search(r'\\foreach\s+[\\\w/\s,]*?\s+in\s*\{', s[i:])
        if not m:
            out.append(s[i:])
            break
        start = i + m.start()
        list_open = i + m.end() - 1  # position of '{'
        out.append(s[i:list_open + 1])
        # Find matching '}'
        depth = 1
        j = list_open + 1
        while j < len(s) and depth > 0:
            if s[j] == '{':
                depth += 1
            elif s[j] == '}':
                depth -= 1
            j += 1
        if depth != 0:
            out.append(s[list_open + 1:])
            break
        list_close = j - 1  # position of matching '}'
        list_body = s[list_open + 1:list_close]
        # Collapse blank lines inside list_body
        new_body = re.sub(r'\n\s*\n+', '\n', list_body)
        if new_body != list_body:
            n += 1
        out.append(new_body)
        out.append('}')
        i = j

    return ''.join(out), n


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
print(f'\nTotal: {sum(total.values())} in {len(total)} files. {"APPLIED" if apply else "DRY-RUN"}')
