r"""
Pre-warm local TikZ cache by compiling all scripts locally (pdflatex + dvisvgm)
and saving as SVG with the same hash key the PHP cache uses.

Avoids hammering VPS with rate-limited bursts after cache clear.
Hash matches TikzRenderService::hashTikzSource (sha256 of normalized source).
"""
import os
import json
import sys
import subprocess
import tempfile
import hashlib
import re
import time
from concurrent.futures import ThreadPoolExecutor, as_completed

ROOTS = ['storage/objects/institutes/106/private/77/eser',
         'storage/objects/institutes/106/private/77/verifiche']
CACHE_DIR = 'storage/cache/tikz/public'


def normalize_tikz(src):
    """Mirror of normalizeTikz JS function for stable hash."""
    s = src.replace('\r\n', '\n').replace('\r', '\n')
    s = re.sub(r'<br\s*/?>', '\n', s, flags=re.I)
    s = re.sub(r'</?(?:p|span|div|b|i|u)\b[^>]*>', '', s, flags=re.I)
    # No HTML entity decode here; contracts shouldn't have them
    s = re.sub(r'[ \t]+\n', '\n', s)
    s = re.sub(r'\n{3,}', '\n\n', s)
    s = s.strip() + '\n'
    if '\\begin{document}' in s and '\\documentclass' not in s and '\\renewcommand{\\familydefault}' not in s:
        font_setup = ('\\usepackage[scaled]{helvet}\n'
                      '\\usepackage[T1]{fontenc}\n'
                      '\\renewcommand{\\familydefault}{\\sfdefault}\n')
        s = s.replace('\\begin{document}', font_setup + '\\begin{document}', 1)
    return s


def wrap_tikz(src, border='2pt'):
    src = src.strip()
    if src.lstrip().startswith(r'\documentclass'):
        return src
    fs = (
        '\\usepackage[scaled]{helvet}\n'
        '\\usepackage[T1]{fontenc}\n'
        '\\renewcommand{\\familydefault}{\\sfdefault}\n'
    )
    if r'\begin{document}' in src:
        return f'\\documentclass[tikz,border={{{border}}}]{{standalone}}\n' + src + '\n'
    body = src
    if r'\begin{tikzpicture}' not in body:
        body = r'\begin{tikzpicture}' + '\n' + body + '\n' + r'\end{tikzpicture}'
    return (
        f'\\documentclass[tikz,border={{{border}}}]{{standalone}}\n'
        '\\usepackage{tikz}\n'
        '\\usepackage{amsmath,amssymb}\n'
        + fs +
        '\\begin{document}\n' + body + '\n\\end{document}\n'
    )


def compile_one(script):
    """Compile script → SVG bytes, or None on failure."""
    normalized = normalize_tikz(script)
    # The PHP cache key uses the normalized source's hash. Match it.
    h = hashlib.sha256(normalized.encode('utf-8')).hexdigest()

    cache_path = os.path.join(CACHE_DIR, h[:2], h + '.svg')
    if os.path.exists(cache_path):
        return ('cached', h)

    wrapped = wrap_tikz(normalized)
    with tempfile.TemporaryDirectory() as tmp:
        tex = os.path.join(tmp, 'doc.tex')
        with open(tex, 'w', encoding='utf-8') as f:
            f.write(wrapped)
        try:
            r1 = subprocess.run(
                ['pdflatex', '-interaction=nonstopmode', '-output-directory=' + tmp, tex],
                capture_output=True, timeout=60,
            )
        except subprocess.TimeoutExpired:
            return ('timeout', h)
        pdf = os.path.join(tmp, 'doc.pdf')
        if not os.path.exists(pdf):
            return ('pdf_fail', h)
        try:
            r2 = subprocess.run(
                ['dvisvgm', '--pdf', '--no-fonts', pdf],
                cwd=tmp, capture_output=True, timeout=60,
            )
        except subprocess.TimeoutExpired:
            return ('svg_timeout', h)
        svg = os.path.join(tmp, 'doc.svg')
        if not os.path.exists(svg):
            return ('svg_fail', h)
        os.makedirs(os.path.dirname(cache_path), exist_ok=True)
        with open(svg, 'rb') as fsrc, open(cache_path, 'wb') as fdst:
            fdst.write(fsrc.read())
        return ('compiled', h)


def walk(node):
    if isinstance(node, dict):
        if node.get('type') == 'tikz':
            yield node.get('script', '')
        for v in node.values():
            if isinstance(v, (dict, list)):
                yield from walk(v)
    elif isinstance(node, list):
        for v in node:
            if isinstance(v, (dict, list)):
                yield from walk(v)


# Collect unique scripts
scripts_seen = set()
scripts = []
for root in ROOTS:
    if not os.path.isdir(root):
        continue
    for dp, _, files in os.walk(root):
        for fn in sorted(files):
            if not fn.endswith('.contract.json'):
                continue
            try:
                with open(os.path.join(dp, fn), 'r', encoding='utf-8') as f:
                    c = json.load(f)
            except:
                continue
            for s in walk(c):
                if not s.strip():
                    continue
                h = hashlib.sha256(s.encode('utf-8')).hexdigest()[:16]
                if h not in scripts_seen:
                    scripts_seen.add(h)
                    scripts.append(s)

print(f'Unique TikZ scripts: {len(scripts)}')
print(f'Cache dir: {CACHE_DIR}')

start = time.time()
results = {'cached': 0, 'compiled': 0, 'pdf_fail': 0, 'svg_fail': 0, 'timeout': 0, 'svg_timeout': 0}
with ThreadPoolExecutor(max_workers=4) as ex:
    futures = [ex.submit(compile_one, s) for s in scripts]
    done = 0
    for fut in as_completed(futures):
        status, h = fut.result()
        results[status] = results.get(status, 0) + 1
        done += 1
        if done % 50 == 0:
            print(f'  {done}/{len(scripts)}  elapsed={time.time()-start:.0f}s')

print(f'\nTotal time: {time.time()-start:.0f}s')
for k, v in results.items():
    print(f'  {k}: {v}')
