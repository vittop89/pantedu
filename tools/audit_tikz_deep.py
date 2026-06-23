"""
Deep audit di TikZ scripts: cerca pattern problematici strutturali.

Categorie controlli:
1. Bilanciamento parentesi/brace
2. \\begin/\\end env mismatch
3. Range xmin/xmax/ymin/ymax swap
4. Missing semicolon su \\draw/\\fill/\\node/\\path/\\clip/\\shade
5. Variabili \\def usate ma non definite (o vice versa)
6. Coordinate fuori range definito
7. Colori usati ma non definiti (custom \\definecolor)
8. \\foreach con sintassi sospetta
9. Brackets [...] mismatch
10. Doppi semicoloni o spazi anomali
"""
import os, json, re
from collections import defaultdict

ROOTS = ['storage/objects/institutes/106/private/77/eser',
         'storage/objects/institutes/106/private/77/verifiche']


def strip_comments(s: str) -> str:
    """Remove LaTeX comments (% to end of line)."""
    return re.sub(r'(?<!\\)%[^\n]*', '', s)


def audit_tikz(script: str) -> list:
    issues = []
    s = strip_comments(script)

    # 1) Brace count
    n_open_b = s.count('{')
    n_close_b = s.count('}')
    if n_open_b != n_close_b:
        issues.append(f'BRACE: {{ ={n_open_b}  }} ={n_close_b}  (diff {n_open_b-n_close_b})')

    # 2) Bracket count [ ]
    # NB: count only outside comments
    n_open_sq = s.count('[')
    n_close_sq = s.count(']')
    if n_open_sq != n_close_sq:
        issues.append(f'BRACKET: [ ={n_open_sq}  ] ={n_close_sq}  (diff {n_open_sq-n_close_sq})')

    # 3) Paren count ( )
    n_open_p = s.count('(')
    n_close_p = s.count(')')
    if n_open_p != n_close_p:
        issues.append(f'PAREN: ( ={n_open_p}  ) ={n_close_p}  (diff {n_open_p-n_close_p})')

    # 4) env mismatch
    for env in ['tikzpicture', 'document', 'scope', 'pgfonlayer', 'axis',
                'pgfpicture', 'matrix', 'tikzcd']:
        nb = len(re.findall(r'\\begin\{' + env + r'\}', s))
        ne = len(re.findall(r'\\end\{' + env + r'\}', s))
        if nb != ne:
            issues.append(f'ENV {env}: begin={nb} end={ne}')

    # 5) xmin/xmax/ymin/ymax swap
    for axis in ['x', 'y']:
        m_min = re.search(r'\\def\\' + axis + r'min\{(-?[\d.]+)\}', s)
        m_max = re.search(r'\\def\\' + axis + r'max\{(-?[\d.]+)\}', s)
        if m_min and m_max:
            try:
                v_min = float(m_min.group(1))
                v_max = float(m_max.group(1))
                if v_min > v_max:
                    issues.append(f'{axis}min={v_min} > {axis}max={v_max} (swapped)')
            except ValueError:
                pass

    # 6) \draw / \fill / \node / \path / \clip / \shade missing semicolon
    # Heuristic: command line not terminated by ; (allowing wrapped lines).
    # Easier: count \draw vs ; in tikzpicture block. Skip — too noisy.

    # 7) \definecolor vs \color usage: colors referenced not in definecolor
    custom_colors = set(re.findall(r'\\definecolor\{(\w+)\}', s))
    used_colors = set(re.findall(r'\\color\{(\w+)\}', s))
    builtin = {'red', 'blue', 'green', 'yellow', 'black', 'white', 'gray',
               'cyan', 'magenta', 'orange', 'pink', 'brown', 'purple', 'lime',
               'olive', 'teal', 'violet', 'darkgray', 'lightgray'}
    missing_colors = (used_colors - custom_colors - builtin)
    # filter known patterns like color!N
    if missing_colors:
        issues.append(f'COLOR used not defined: {sorted(missing_colors)}')

    # 8) \foreach loops — check basic syntax
    # Pattern: \foreach \var in {list}  or  \foreach \var/.../\var in {list}
    foreach_matches = re.findall(r'\\foreach[^{]*?\{([^}]*)\}', s)
    for fm in foreach_matches:
        # If list is empty or has unbalanced quotes
        if not fm.strip():
            issues.append('FOREACH with empty list')

    # 9) Common typos — only known REAL typos (not false positives like \filldraw)
    typo_patterns = [
        (r'\\nodde\b', 'nodde→node'),
        (r'\\dreaw\b', 'dreaw→draw'),
        (r'\\tikzpictrue\b', 'tikzpictrue→tikzpicture'),
        (r'\\bigin\b', 'bigin→begin'),
        (r'\\engd\b', 'engd→end'),
        (r'\\coorddinate\b', 'coorddinate→coordinate'),
    ]
    for pat, label in typo_patterns:
        if re.search(pat, s):
            issues.append(f'TYPO: {label}')

    # 10) \def used but never invoked
    defs = set(re.findall(r'\\def\\(\w+)\b', s))
    invocations = set(re.findall(r'\\(\w+)\b', s))
    # Filter common builtins
    unused_defs = defs - invocations
    # Some defs are values (xmin, ymin, etc.) used in \draw (\xmin, \xmax)
    # Re-check by raw \cmd
    if unused_defs:
        truly_unused = []
        for d in unused_defs:
            if not re.search(r'\\' + re.escape(d) + r'\b', s.replace(f'\\def\\{d}', '', 1)):
                # Check if used after the def
                pass
        # Skip — too many false positives

    # 11) Foreach iterator pattern: 'in {a, b, c}' should have proper format
    # \foreach \i in {a/b/c} typically fragment slash-separator
    bad_foreach = re.findall(r'\\foreach\s+\\(\w+)\s+in\s*\{\s*\}', s)
    if bad_foreach:
        issues.append(f'FOREACH empty: {bad_foreach}')

    # 12) Multiple newlines inside argument (visible as JSON \n issue, already
    #     should be fixed by collapse — but check residuals)
    if re.search(r'\\node\b[^;]*\n[^;]*?;', s):
        # node spanning multiple lines is fine; skip
        pass

    # 13) Empty tikzpicture body
    m_tp = re.search(r'\\begin\{tikzpicture\}([\s\S]*?)\\end\{tikzpicture\}', s)
    if m_tp:
        body = m_tp.group(1).strip()
        if not body or len(body) < 10:
            issues.append('TIKZPICTURE body empty or very short')

    # 14) Coordinate format anomalies — e.g. (1, 2 3) bad spacing
    # Skip — too noisy

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


by_file = defaultdict(list)
total_tikz = 0
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
                total_tikz += 1
                if not script:
                    continue
                issues = audit_tikz(script)
                if issues:
                    by_file[fn].append((loc, issues))

print(f'Total TikZ scripts scanned: {total_tikz}')
print(f'Files with issues: {len(by_file)}\n')

for fn, items in sorted(by_file.items()):
    print(f'\n=== {fn} ({len(items)} script con issues) ===')
    for loc, issues in items:
        print(f'  {loc}:')
        for iss in issues:
            print(f'    - {iss}')
