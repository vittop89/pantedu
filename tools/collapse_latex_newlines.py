"""
Collassa \\n\\n+ → \\n SOLO in latex chunk content (e in text chunk
content che inizia con \\( o \\[ — mixed text+latex chunks).
MathJax ignora whitespace, no impatto rendering. Riduce noise editor.

Preserva:
- \\n\\n in text chunks puri (paragraph breaks)
- Single \\n in latex (per leggibilità righe array)
"""
import os, json, re, sys

ROOTS = [
    'storage/objects/institutes/106/private/77/eser',
    'storage/objects/institutes/106/private/77/verifiche',
]


def collapse_in_latex(s: str) -> str:
    """Collapse runs of 2+ newlines (with any whitespace between) → \\n."""
    return re.sub(r'(\n[ \t]*){2,}', '\n', s)


def collapse_in_mixed(s: str) -> str:
    """For text chunks that contain \\(...\\) inline latex:
    collapse only the \\n inside the \\(...\\) ranges."""
    def replace_latex_block(m):
        body = m.group(0)
        return collapse_in_latex(body)
    # Inline \(...\) or display \[...\]
    s = re.sub(r'\\\([\s\S]*?\\\)', replace_latex_block, s)
    s = re.sub(r'\\\[[\s\S]*?\\\]', replace_latex_block, s)
    return s


def walk_fix(node, changes):
    if isinstance(node, dict):
        t = node.get('type')
        content = node.get('content')
        if isinstance(content, str):
            if t == 'latex':
                new = collapse_in_latex(content)
                if new != content:
                    changes.append((content, new))
                    node['content'] = new
            elif t == 'text' and ('\\(' in content or '\\[' in content):
                new = collapse_in_mixed(content)
                if new != content:
                    changes.append((content, new))
                    node['content'] = new
        for v in node.values():
            if isinstance(v, (dict, list)):
                walk_fix(v, changes)
    elif isinstance(node, list):
        for v in node:
            if isinstance(v, (dict, list)):
                walk_fix(v, changes)


apply = '--apply' in sys.argv

total_files = 0
total_changes = 0
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
                total_files += 1
                total_changes += len(changes)
                if apply:
                    with open(path, 'w', encoding='utf-8') as f:
                        json.dump(c, f, ensure_ascii=False, indent=4)

print(f'Files: {total_files}  chunks changed: {total_changes}  mode: {"APPLIED" if apply else "DRY-RUN"}')
