r"""Compile a TikZ script to SVG locally, mimicking server pipeline."""
import json, os, sys, subprocess, tempfile, shutil

if len(sys.argv) < 6:
    print('usage: compile_tikz_to_svg.py <path> <group> <item> <field> <chunk> [out.svg]')
    sys.exit(1)

path, g, i, field, ci = sys.argv[1:6]
out_svg = sys.argv[6] if len(sys.argv) > 6 else 'tmp_tikz.svg'

with open(path, 'r', encoding='utf-8') as f:
    c = json.load(f)
script = c['groups'][int(g)]['items'][int(i)][field][int(ci)]['script']


def wrap(src, border='2mm'):
    if src.lstrip().startswith(r'\documentclass'):
        return src
    fs = (
        '\\usepackage[scaled]{helvet}\n'
        '\\usepackage[T1]{fontenc}\n'
        '\\renewcommand{\\familydefault}{\\sfdefault}\n'
    )
    if r'\begin{document}' in src:
        patched = src.replace(r'\begin{document}', fs + r'\begin{document}', 1)
        return f'\\documentclass[tikz,border={{{border}}}]{{standalone}}\n' + patched + '\n'
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


tmp = tempfile.mkdtemp()
tex = os.path.join(tmp, 'doc.tex')
with open(tex, 'w', encoding='utf-8') as f:
    f.write(wrap(script))

# Step 1: pdflatex
r1 = subprocess.run(
    ['pdflatex', '-interaction=nonstopmode', '-output-directory=' + tmp, tex],
    capture_output=True, text=True, encoding='utf-8', errors='replace', timeout=60,
)
pdf = os.path.join(tmp, 'doc.pdf')
if not os.path.exists(pdf):
    print('pdflatex FAIL')
    sys.exit(1)
print(f'pdflatex OK: {os.path.getsize(pdf)} bytes PDF')

# Step 2: dvisvgm --pdf --no-fonts
r2 = subprocess.run(
    ['dvisvgm', '--pdf', '--no-fonts', pdf],
    cwd=tmp, capture_output=True, text=True, encoding='utf-8', errors='replace', timeout=60,
)
svg_path = os.path.join(tmp, 'doc.svg')
if not os.path.exists(svg_path):
    print('dvisvgm FAIL')
    print('stdout:', r2.stdout[-500:])
    print('stderr:', r2.stderr[-500:])
    sys.exit(2)

print(f'dvisvgm OK: {os.path.getsize(svg_path)} bytes SVG')
shutil.copy(svg_path, out_svg)
print(f'SVG saved to: {out_svg}')

# Show first 500 chars to inspect
with open(svg_path, 'r', encoding='utf-8') as f:
    svg = f.read()
print('\n=== SVG header ===')
print(svg[:500])
print('\n...')
# Show end
print(svg[-300:])
