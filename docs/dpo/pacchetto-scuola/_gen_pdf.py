#!/usr/bin/env python3
# Genera PDF dai .md del pacchetto DPO: MD -> HTML (markdown) -> PDF (Edge headless).
#
# Si lancia con il Python di Windows, dove ci sono Edge e il modulo `markdown`
# (docs/dev/sviluppo-in-wsl.md, «Che cosa si fa ancora da Windows»).
#
# Il PDF versionato si sostituisce solo a stampa riuscita (22/9/2026). Prima lo
# script cancellava il PDF e solo DOPO lanciava Edge, senza guardare se Edge
# c'era: lanciato con il Python di WSL e il modulo `markdown` installato, Edge
# (un percorso C:\...) non esisteva, subprocess sollevava FileNotFoundError e
# il PDF era già sparito dal repository. Ora:
#   - se Edge manca ci si ferma prima di leggere o scrivere qualunque cosa;
#   - Edge stampa su un file temporaneo nella cartella del PDF, e il PDF vero
#     si sostituisce (os.replace, atomico nello stesso file system) solo se
#     Edge è uscito con 0 e il temporaneo è un PDF non vuoto che ha smesso di
#     crescere, comincia con %PDF e ha %%EOF in coda. Altrimenti il
#     temporaneo si cancella e il PDF resta com'era.
#
# Con più file sulla riga di comando ognuno fa per sé: un fallimento lascia
# com'era il suo PDF, si passa al file successivo e alla fine l'esito è 1. Un
# PDF già rigenerato non resta mai a metà, perché ogni sostituzione è atomica.
#
# Esiti: 0 tutto rigenerato; 1 almeno un file non rigenerato; 2 uso sbagliato;
# 3 Edge assente, cioè l'ambiente sbagliato (come build_pdf.sh dentro WSL).
#
# Per le prove (tests/ops/gen-pdf-pacchetto.test.sh):
#   GEN_PDF_ATTESA   secondi di attesa del PDF dopo che Edge è tornato (20).
# L'eseguibile di Edge invece non si prende dall'ambiente: un programma da
# lanciare scelto da una variabile è quello che semgrep segnala
# (dangerous-subprocess-use-tainted-env-args, 23/9/2026). La prova lavora su
# una copia dello script con la riga EDGE sostituita.
import sys, re, subprocess, tempfile, os, pathlib, time, shutil, glob

EDGE = r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
ATTESA = float(os.environ.get('GEN_PDF_ATTESA') or 20)
GUIDA = "docs/dev/sviluppo-in-wsl.md, «Che cosa si fa ancora da Windows»"

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


class Fallito(Exception):
    """La stampa non ha dato un PDF buono: il PDF versionato non si tocca."""


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


def aspetta_pdf(percorso):
    """Vero se il file esiste, non è vuoto e ha smesso di crescere entro ATTESA."""
    # Edge in --headless=new puo' tornare prima che il processo figlio abbia
    # letto l'HTML e scritto il PDF: il 2026-09-04 la Nota risultava "non
    # creata" e un istante dopo era sul disco; cancellando l'HTML subito, Edge
    # stampava la propria pagina d'errore (Nota e Pacchetto identici, 59719
    # byte). Quindi: prima si aspetta che il PDF esista e smetta di crescere,
    # poi si toglie l'HTML.
    deadline = time.time() + ATTESA
    last = -1
    while time.time() < deadline:
        if os.path.exists(percorso):
            size = os.path.getsize(percorso)
            if size > 0 and size == last:
                return True
            last = size
        time.sleep(0.5)
    return False


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
    out_pdf = str(md_path.resolve().with_suffix('.pdf'))

    # Se Edge scrive dopo che un giro precedente ha rinunciato, accanto al PDF
    # resta un «.<nome>.XXXX.nuovo.pdf»: git lo ignora (.gitignore), e lo si
    # toglie qui, al giro dopo.
    for residuo in pathlib.Path(out_pdf).parent.glob(
            '.' + glob.escape(md_path.stem) + '.*.nuovo.pdf'):
        try:
            residuo.unlink()
        except FileNotFoundError:
            pass

    html_tmp = nuovo = udd = None
    try:
        # Il temporaneo sta accanto al PDF vero, perché os.replace sia atomico.
        # Si riserva il nome e si toglie il file: lo crea Edge, e che esista
        # vuol dire che Edge ha scritto.
        fd, nuovo = tempfile.mkstemp(prefix='.' + md_path.stem + '.', suffix='.nuovo.pdf',
                                     dir=os.path.dirname(out_pdf))
        os.close(fd)
        os.unlink(nuovo)
        tmp = tempfile.NamedTemporaryFile(mode='w', suffix='.html', delete=False, encoding='utf-8')
        html_tmp = tmp.name
        tmp.write(html); tmp.close()
        url = pathlib.Path(html_tmp).as_uri()
        udd = tempfile.mkdtemp(prefix='edge-pdf-')
        subprocess.run([EDGE, '--headless=new', '--disable-gpu', '--no-pdf-header-footer',
                        f'--user-data-dir={udd}', '--no-first-run', '--no-default-browser-check',
                        f'--print-to-pdf={nuovo}', url], check=True, timeout=120)
        if not aspetta_pdf(nuovo):
            raise Fallito(f"Edge non ha scritto un PDF completo entro {ATTESA:g} s")
        with open(nuovo, 'rb') as f:
            if f.read(4) != b'%PDF':
                raise Fallito("quello che Edge ha scritto non comincia con %PDF")
            # «Ha smesso di crescere» è un'euristica: una pausa di Edge a metà
            # scrittura la inganna. Un PDF finito ha %%EOF in coda (i quattro
            # PDF di Edge versionati il 23/9/2026 finiscono tutti con «%%EOF\n»).
            f.seek(max(0, os.path.getsize(nuovo) - 1024))
            if b'%%EOF' not in f.read():
                raise Fallito("il PDF di Edge è troncato: manca %%EOF in coda")
        os.replace(nuovo, out_pdf)
    finally:
        # L'HTML si toglie solo qui, dopo l'attesa (vedi aspetta_pdf).
        for p in (html_tmp, nuovo):
            if p:
                try:
                    os.unlink(p)
                except FileNotFoundError:
                    pass
        if udd:
            shutil.rmtree(udd, ignore_errors=True)
    print(f"OK -> {out_pdf} ({os.path.getsize(out_pdf)} bytes)")


if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("Uso: python.exe docs/dpo/pacchetto-scuola/_gen_pdf.py FILE.md [FILE.md ...]",
              file=sys.stderr)
        sys.exit(2)
    # Prima di tutto: senza Edge non si legge e non si scrive niente.
    if not os.path.isfile(EDGE):
        print(f"Edge non trovato: {EDGE}\n"
              "Nessun file toccato. Lo script si lancia con il Python di Windows, dove c'è Edge:\n"
              f"{GUIDA}.", file=sys.stderr)
        sys.exit(3)
    # Solo qui: in WSL il modulo di solito manca, e importarlo in cima darebbe
    # un traceback al posto del rimando alla guida.
    import markdown
    falliti = []
    for p in sys.argv[1:]:
        try:
            main(p)
        except (Fallito, OSError, subprocess.SubprocessError, UnicodeError) as e:
            print(f"FAIL: {p}: {e}", file=sys.stderr)
            falliti.append(p)
    if falliti:
        print(f"{len(falliti)} di {len(sys.argv) - 1} non rigenerati, i loro PDF sono com'erano: "
              + ", ".join(falliti), file=sys.stderr)
        sys.exit(1)
