"""Audit TikZ scripts in contract files for common issues."""
import os, json, re

ROOTS = ['storage/objects/institutes/106/private/77/eser',
         'storage/objects/institutes/106/private/77/verifiche']


def audit_tikz(script: str) -> list:
    issues = []
    # 1. Brace balance
    n_open = script.count('{')
    n_close = script.count('}')
    if n_open != n_close:
        issues.append(f'brace mismatch: {{={n_open} }}={n_close}')
    # 2. \begin/\end matching for common envs
    for env in ['tikzpicture', 'document', 'scope', 'pgfonlayer', 'axis']:
        nb = len(re.findall(r'\\begin\{' + env + r'\}', script))
        ne = len(re.findall(r'\\end\{' + env + r'\}', script))
        if nb != ne:
            issues.append(f'env {env}: begin={nb} end={ne}')
    # 3. xmin > xmax check (specific bug pattern)
    m_xmin = re.search(r'\\def\\xmin\{(-?[\d.]+)\}', script)
    m_xmax = re.search(r'\\def\\xmax\{(-?[\d.]+)\}', script)
    if m_xmin and m_xmax:
        try:
            xmin = float(m_xmin.group(1))
            xmax = float(m_xmax.group(1))
            if xmin > xmax:
                issues.append(f'xmin={xmin} > xmax={xmax} (swapped)')
        except ValueError:
            pass
    # 4. ymin > ymax check
    m_ymin = re.search(r'\\def\\ymin\{(-?[\d.]+)\}', script)
    m_ymax = re.search(r'\\def\\ymax\{(-?[\d.]+)\}', script)
    if m_ymin and m_ymax:
        try:
            ymin = float(m_ymin.group(1))
            ymax = float(m_ymax.group(1))
            if ymin > ymax:
                issues.append(f'ymin={ymin} > ymax={ymax} (swapped)')
        except ValueError:
            pass
    # 5. Missing \begin{document}
    if not re.search(r'\\begin\{document\}', script):
        issues.append('missing \\begin{document}')
    if not re.search(r'\\end\{document\}', script):
        issues.append('missing \\end{document}')
    # 6. Missing \begin{tikzpicture}
    if not re.search(r'\\begin\{tikzpicture\}', script):
        issues.append('missing \\begin{tikzpicture}')
    return issues


def walk(node, path=''):
    if isinstance(node, dict):
        if node.get('type') == 'tikz':
            yield (path, node.get('script', ''))
        for k, v in node.items():
            if isinstance(v, (dict, list)):
                yield from walk(v, f'{path}.{k}')
    elif isinstance(node, list):
        for i, v in enumerate(node):
            if isinstance(v, (dict, list)):
                yield from walk(v, f'{path}[{i}]')


total_files = 0
total_issues = 0
files_with_issues = {}
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
            for loc, script in walk(c):
                if not script:
                    continue
                issues = audit_tikz(script)
                if issues:
                    files_with_issues.setdefault(fn, []).append((loc, issues))

for fn, items in files_with_issues.items():
    print(f'\n{fn}: {len(items)} TikZ con issues')
    for loc, issues in items[:5]:
        print(f'  {loc}:')
        for iss in issues:
            print(f'    - {iss}')

total = sum(len(v) for v in files_with_issues.values())
print(f'\nTotal TikZ with issues: {total} across {len(files_with_issues)} files')
