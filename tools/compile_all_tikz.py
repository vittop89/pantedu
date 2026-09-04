r"""
Compila tutti i TikZ scripts in eser/ + verifiche/ con pdflatex locale.
Cache per content-hash (skip duplicati). Output: lista failures con error.
"""
import json, os, sys, subprocess, tempfile, hashlib, time
from concurrent.futures import ThreadPoolExecutor, as_completed

ROOTS = ['storage/objects/institutes/106/private/77/eser',
         'storage/objects/institutes/106/private/77/verifiche']


def wrap_tikz(src: str, border: str = '2mm') -> str:
    src = src.strip()
    if src.lstrip().startswith(r'\documentclass'):
        return src
    font_setup = (
        '\\usepackage[scaled]{helvet}\n'
        '\\usepackage[T1]{fontenc}\n'
        '\\renewcommand{\\familydefault}{\\sfdefault}\n'
    )
    has_begin_doc = r'\begin{document}' in src
    if has_begin_doc:
        patched = src.replace(r'\begin{document}', font_setup + r'\begin{document}', 1)
        return f'\\documentclass[tikz,border={{{border}}}]{{standalone}}\n' + patched + '\n'
    body = src
    if r'\begin{tikzpicture}' not in body:
        body = r'\begin{tikzpicture}' + '\n' + body + '\n' + r'\end{tikzpicture}'
    return (
        f'\\documentclass[tikz,border={{{border}}}]{{standalone}}\n'
        '\\usepackage{tikz}\n'
        '\\usepackage{amsmath,amssymb}\n'
        + font_setup +
        '\\begin{document}\n' + body + '\n\\end{document}\n'
    )


def compile_one(script: str, label: str) -> tuple:
    """Returns (success, error_excerpt). error_excerpt is short."""
    wrapped = wrap_tikz(script)
    with tempfile.TemporaryDirectory() as tmp:
        tex = os.path.join(tmp, 'doc.tex')
        with open(tex, 'w', encoding='utf-8') as f:
            f.write(wrapped)
        try:
            r = subprocess.run(
                ['pdflatex', '-interaction=nonstopmode', '-output-directory=' + tmp, tex],
                capture_output=True, text=True, encoding='utf-8', errors='replace',
                timeout=60,
            )
        except subprocess.TimeoutExpired:
            return (False, 'TIMEOUT (>60s)')
        out = r.stdout + '\n' + r.stderr
        pdf = os.path.join(tmp, 'doc.pdf')
        if os.path.exists(pdf):
            return (True, '')
        # Find error lines
        err_lines = []
        for ln in out.split('\n'):
            if ln.startswith('!') or 'error' in ln.lower():
                err_lines.append(ln.strip())
                if len(err_lines) >= 3:
                    break
        return (False, ' | '.join(err_lines)[:300])


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


# Collect all scripts with dedup
scripts_by_hash = {}  # hash → (script, [labels])
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
                if not script.strip():
                    continue
                h = hashlib.sha1(script.encode('utf-8')).hexdigest()[:12]
                if h not in scripts_by_hash:
                    scripts_by_hash[h] = (script, [])
                scripts_by_hash[h][1].append(f'{fn}{loc}')

print(f'Total unique TikZ scripts: {len(scripts_by_hash)}')
print(f'Compiling in parallel (4 workers)...\n')

start = time.time()
results = {}
with ThreadPoolExecutor(max_workers=4) as ex:
    futures = {ex.submit(compile_one, s, h): h for h, (s, _) in scripts_by_hash.items()}
    done = 0
    for fut in as_completed(futures):
        h = futures[fut]
        ok, err = fut.result()
        results[h] = (ok, err)
        done += 1
        if done % 50 == 0:
            elapsed = time.time() - start
            print(f'  progress: {done}/{len(scripts_by_hash)}  elapsed={elapsed:.0f}s')

elapsed = time.time() - start
n_ok = sum(1 for ok, _ in results.values() if ok)
n_fail = len(results) - n_ok
print(f'\nDone in {elapsed:.0f}s  OK={n_ok}  FAIL={n_fail}')

if n_fail:
    print('\n=== FAILURES ===')
    for h, (ok, err) in results.items():
        if not ok:
            script, labels = scripts_by_hash[h]
            print(f'\nhash={h}  occurrences={len(labels)}')
            print(f'  first label: {labels[0]}')
            print(f'  error: {err}')
            print(f'  script preview: {script[:200]!r}')
