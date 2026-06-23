"""
Fix LaTeX commands truncated by JSON tab escape.

Pattern: original \\text serialized as \\t (JSON tab) + ext.
After JSON parse, content has <TAB>ext instead of \\text.

Apply ONLY to text/latex chunks (NOT tikz scripts where tabs are intentional).
Only convert when tab is followed by typical LaTeX command letter.
"""
import json, os, re, sys

ROOTS = [
    'storage/objects/institutes/106/private/77/eser',
    'storage/objects/institutes/106/private/77/verifiche',
]

# Trucated forms after JSON \t (tab) replaces leading "\t" of LaTeX command.
# e.g. \text → <TAB>ext  (5 chars become 4)
TRUNCATED_LATEX = [
    ('ext', 'text'),
    ('extbf', 'textbf'),
    ('extit', 'textit'),
    ('extrm', 'textrm'),
    ('extsf', 'textsf'),
    ('heta', 'theta'),
    ('an', 'tan'),
    ('imes', 'times'),
    ('au', 'tau'),
    ('riangle', 'triangle'),
    ('ilde', 'tilde'),
    ('o', 'to'),
    ('op', 'top'),
    ('frac', 'tfrac'),
    ('hinspace', 'thinspace'),
    ('hickspace', 'thickspace'),
    ('hick', 'thick'),
    ('hin', 'thin'),
]


def fix_chunk_content(s: str) -> tuple:
    """Detect <TAB><truncated> patterns and restore full LaTeX command.
    Longer trunc-forms first to avoid greedy short-form match."""
    if not isinstance(s, str) or '\t' not in s:
        return s, 0
    n = 0
    # Sort by length descending so longer patterns match first
    for trunc, full in sorted(TRUNCATED_LATEX, key=lambda x: -len(x[0])):
        pattern = '\t' + trunc
        # ensure boundary: char after trunc must NOT be a letter (so 'ext' doesn't
        # match inside 'extx')
        import re
        repl = '\\' + full
        new_s, count = re.subn(re.escape(pattern) + r'(?![a-zA-Z])', repl, s)
        if count:
            s = new_s
            n += count
    return s, n


def walk_fix(node, changes):
    if isinstance(node, dict):
        t = node.get('type')
        # Skip tikz scripts (tabs intentional for indent)
        if t == 'tikz':
            return
        content = node.get('content')
        if isinstance(content, str):
            new, n = fix_chunk_content(content)
            if n:
                node['content'] = new
                changes.append(('content', n))
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
                total_n = sum(n for _, n in changes)
                total[fn] = total_n
                if apply:
                    with open(path, 'w', encoding='utf-8') as f:
                        json.dump(c, f, ensure_ascii=False, indent=4)

for fn, n in sorted(total.items(), key=lambda x: -x[1]):
    print(f'  {fn}: {n} fixes')
print(f'\nTotal: {sum(total.values())} in {len(total)} files. Mode: {"APPLIED" if apply else "DRY-RUN"}')
