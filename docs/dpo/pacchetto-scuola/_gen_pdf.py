#!/usr/bin/env python3
# Genera PDF dai .md del pacchetto DPO: MD -> HTML (markdown) -> PDF (Edge headless).
import sys, re, subprocess, tempfile, os, pathlib

EDGE = r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
import markdown

CSS = """
@page { size: A4; margin: 20mm 18mm; }
* { box-sizing: border-box; }
body { font-family: 'Segoe UI', Calibri, Arial, sans-serif; font-size: 10.5pt;
       line-height: 1.45; color: #1a1a1a; max-width: 100%; }
h1 { font-size: 17pt; border-bottom: 2px solid #2c3e50; padding-bottom: 4px;
     margin-top: 18px; color: #1b2b3a; }
h2 { font-size: 13pt; color: #1b2b3a; margin-top: 16px; }
h3 { font-size: 11.5pt; color: #34495e; margin-top: 12px; }
p, li { font-size: 10.5pt; }
blockquote { border-left: 4px solid #b0bec5; background: #f4f6f8; margin: 10px 0;
             padding: 6px 12px; color: #37474f; }
table { border-collapse: collapse; width: 100%; margin: 10px 0; font-size: 9.5pt; }
th, td { border: 1px solid #b0bec5; padding: 5px 7px; text-align: left; vertical-align: top; }
th { background: #eceff1; }
code { background: #eceff1; padding: 1px 4px; border-radius: 3px;
       font-family: Consolas, monospace; font-size: 9pt; }
hr { border: none; border-top: 1px solid #cfd8dc; margin: 16px 0; }
strong { color: #102a43; }
a { color: #1565c0; text-decoration: none; }
"""

def strip_frontmatter(text):
    meta = {}
    if text.startswith('---'):
        m = re.match(r'^---\s*\n(.*?)\n---\s*\n', text, re.S)
        if m:
            fm = m.group(1)
            for key in ('title', 'subtitle', 'author', 'date'):
                mm = re.search(r'^' + key + r':\s*"?(.*?)"?\s*$', fm, re.M)
                if mm:
                    meta[key] = mm.group(1)
            return text[m.end():], meta
    return text, meta

def main(md_path):
    md_path = pathlib.Path(md_path)
    raw = md_path.read_text(encoding='utf-8')
    body, meta = strip_frontmatter(raw)
    html_body = markdown.markdown(body, extensions=['tables', 'fenced_code', 'sane_lists', 'attr_list'])
    head = ""
    if meta.get('title'):
        head += f"<h1 style='border:none;margin-bottom:2px'>{meta['title']}</h1>"
    if meta.get('subtitle'):
        head += f"<p style='font-size:12pt;color:#34495e;margin:0 0 4px'>{meta['subtitle']}</p>"
    byline = " · ".join(x for x in (meta.get('author'), meta.get('date')) if x)
    if byline:
        head += f"<p style='font-size:9.5pt;color:#607d8b;margin:0 0 12px'>{byline}</p>"
    html = f"<!doctype html><html lang='it'><head><meta charset='utf-8'><style>{CSS}</style></head><body>{head}{html_body}</body></html>"
    tmp = tempfile.NamedTemporaryFile(mode='w', suffix='.html', delete=False, encoding='utf-8')
    tmp.write(html); tmp.close()
    out_pdf = str(md_path.resolve().with_suffix('.pdf'))
    if os.path.exists(out_pdf):
        os.unlink(out_pdf)
    url = pathlib.Path(tmp.name).as_uri()
    udd = tempfile.mkdtemp(prefix='edge-pdf-')
    subprocess.run([EDGE, '--headless=new', '--disable-gpu', '--no-pdf-header-footer',
                    f'--user-data-dir={udd}', '--no-first-run', '--no-default-browser-check',
                    f'--print-to-pdf={out_pdf}', url], check=True, timeout=120)
    os.unlink(tmp.name)
    if not os.path.exists(out_pdf):
        raise SystemExit(f"FAIL: {out_pdf} non creato")
    print(f"OK -> {out_pdf} ({os.path.getsize(out_pdf)} bytes)")

if __name__ == '__main__':
    for p in sys.argv[1:]:
        main(p)
