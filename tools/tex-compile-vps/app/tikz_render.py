"""TikZ → SVG renderer per pantedu.

Pipeline:
  1. Riceve un blocco \\begin{tikzpicture}...\\end{tikzpicture} (o un
     ambiente standalone equivalente) + lista librerie + pacchetti
  2. Wrappa in template `standalone` minimo
  3. pdflatex → doc.pdf
  4. dvisvgm --pdf doc.pdf -o doc.svg (font convertiti in path con
     --no-fonts per portabilita massima e zero font-dep lato browser)
  5. Ritorna SVG bytes

Sicurezza: stesso hardening di compile.py (no-shell-escape, nonstopmode,
timeout duro, tmpdir isolato cleanup post-run).
"""
from __future__ import annotations

import asyncio
import logging
import os
import re
import subprocess
import sys
import tempfile
from dataclasses import dataclass
from pathlib import Path

logger = logging.getLogger(__name__)

WORKDIR = os.environ.get("TEX_COMPILE_WORKDIR", "/var/tmp/tex-compile")
DEFAULT_TIMEOUT = int(os.environ.get("TIKZ_RENDER_TIMEOUT", "20"))

# Allowlist di pacchetti / librerie tikz consentite. Riduce attack surface
# (no \usepackage{verbatim} con \write18 ecc.). Estendere se servono nuovi
# casi d'uso scolastici.
ALLOWED_PACKAGES = {
    "tikz", "pgfplots", "amsmath", "amssymb", "amsfonts", "mathtools",
    "physics", "siunitx", "circuitikz", "chemfig", "tkz-euclide",
    "tkz-graph", "xcolor", "color", "graphicx", "fontspec",
    "babel", "polyglossia", "mhchem", "esvect",
}

ALLOWED_TIKZ_LIBRARIES = {
    "arrows", "arrows.meta", "arrows.spaced", "calc", "decorations",
    "decorations.markings", "decorations.pathmorphing",
    "decorations.pathreplacing", "decorations.text", "fit",
    "intersections", "matrix", "patterns", "patterns.meta",
    "positioning", "shapes", "shapes.geometric", "shapes.misc",
    "shapes.callouts", "shapes.arrows", "trees", "graphs",
    "quotes", "angles", "babel", "backgrounds", "calendar",
    "chains", "circuits", "circuits.ee", "circuits.logic.US",
    "external", "lindenmayersystems", "mindmap", "petri",
    "plothandlers", "plotmarks", "snakes", "spy", "topaths",
    "through", "automata", "cd", "math", "datavisualization",
}

ALLOWED_PGFPLOTS_LIBRARIES = {
    "fillbetween", "groupplots", "patchplots", "polar",
    "smithchart", "ternary", "units", "statistics", "dateplot",
}


@dataclass
class TikzRenderResult:
    ok: bool
    svg: bytes | None
    log: str
    duration_ms: int


class TikzRenderError(Exception):
    pass


def _normalize_set(values: list[str] | None, allowlist: set[str]) -> list[str]:
    """Filter values keeping only allowlisted entries."""
    if not values:
        return []
    out: list[str] = []
    seen: set[str] = set()
    for v in values:
        v = (v or "").strip()
        if v in allowlist and v not in seen:
            out.append(v)
            seen.add(v)
    return out


# Pattern per detectare lo "shape" della sorgente fornita:
#   - documento completo (\\documentclass + \\begin{document} + ...)
#   - "preamble + begin{document}" senza \\documentclass (legacy pantedu)
#   - solo blocco tikzpicture / frammento puro
_DOCCLASS_RE = re.compile(r"^\s*\\documentclass\b", re.MULTILINE)
_BEGINDOC_RE = re.compile(r"\\begin\s*\{\s*document\s*\}")


def build_standalone_tex(
    tikz_source: str,
    *,
    libraries: list[str] | None = None,
    pgfplots_libraries: list[str] | None = None,
    extra_packages: list[str] | None = None,
    border: str = "2pt",
) -> str:
    """Avvolge un frammento TikZ in un documento standalone minimal.

    Tre casi (decisi automaticamente):

    1. `\\documentclass` gia' presente → ritorna inalterato.
    2. `\\begin{document}` presente ma senza `\\documentclass` (formato
       legacy pantedu: l'autore scrive direttamente preamble +
       \\usepackage + \\usetikzlibrary + \\begin{document} +
       tikzpicture + \\end{document}). Anteponiamo solo
       `\\documentclass[tikz,border=...]{standalone}` e lasciamo intatto
       il resto. Le libraries/packages passate via JSON vengono
       ignorate per non duplicare quanto gia' nel preamble dell'autore.
    3. Frammento puro (no `\\begin{document}`) → wrap completo con
       documentclass + packages dalla allowlist + body avvolto in
       tikzpicture se necessario.
    """
    src = tikz_source.strip()
    if _DOCCLASS_RE.search(src):
        return src

    if _BEGINDOC_RE.search(src):
        # Caso 2 — preamble dell'autore e' autoritativo. Solo documentclass.
        prefix = f"\\documentclass[tikz,border={border}]{{standalone}}\n"
        return prefix + src + ("\n" if not src.endswith("\n") else "")

    # Caso 3 — frammento puro: wrap completo con allowlist server-side.
    libs = _normalize_set(libraries, ALLOWED_TIKZ_LIBRARIES)
    pgflibs = _normalize_set(pgfplots_libraries, ALLOWED_PGFPLOTS_LIBRARIES)
    extras = _normalize_set(extra_packages, ALLOWED_PACKAGES)

    use_pgfplots = "pgfplots" in extras or bool(pgflibs)

    pkg_lines: list[str] = []
    pkg_lines.append("\\usepackage{amsmath,amssymb}")
    if use_pgfplots:
        pkg_lines.append("\\usepackage{pgfplots}")
        pkg_lines.append("\\pgfplotsset{compat=1.18}")
    for p in extras:
        if p in {"tikz", "pgfplots", "amsmath", "amssymb"}:
            continue
        pkg_lines.append(f"\\usepackage{{{p}}}")

    if libs:
        pkg_lines.append(f"\\usetikzlibrary{{{','.join(libs)}}}")
    if pgflibs:
        pkg_lines.append(f"\\usepgfplotslibrary{{{','.join(pgflibs)}}}")

    body = src
    if "\\begin{tikzpicture}" not in body:
        body = f"\\begin{{tikzpicture}}\n{body}\n\\end{{tikzpicture}}"

    return (
        f"\\documentclass[tikz,border={border}]{{standalone}}\n"
        + "\n".join(pkg_lines)
        + "\n\\begin{document}\n"
        + body
        + "\n\\end{document}\n"
    )


async def render_tikz(
    tikz_source: str,
    *,
    libraries: list[str] | None = None,
    pgfplots_libraries: list[str] | None = None,
    extra_packages: list[str] | None = None,
    border: str = "2pt",
    timeout: int = DEFAULT_TIMEOUT,
) -> TikzRenderResult:
    """Compila TikZ → SVG (vettoriale, font-as-paths)."""
    if not tikz_source or len(tikz_source) > 1 * 1024 * 1024:
        raise TikzRenderError("tikz_source vuoto o > 1 MB")

    full_tex = build_standalone_tex(
        tikz_source,
        libraries=libraries,
        pgfplots_libraries=pgfplots_libraries,
        extra_packages=extra_packages,
        border=border,
    )

    Path(WORKDIR).mkdir(parents=True, exist_ok=True)
    loop = asyncio.get_event_loop()
    started = loop.time()

    with tempfile.TemporaryDirectory(dir=WORKDIR, prefix="tikz_") as tmp:
        tmp_path = Path(tmp).resolve()
        tex_file = tmp_path / "doc.tex"
        tex_file.write_text(full_tex, encoding="utf-8")

        # Step 1: pdflatex → PDF.
        # Usiamo subprocess.run via asyncio.to_thread per portabilita
        # cross-platform (Windows asyncio non sempre supporta subprocess
        # sotto SelectorEventLoop; on Linux il thread pool e' un overhead
        # trascurabile rispetto al compile pdflatex tipico 1-3s).
        pdflatex_cmd = [
            "pdflatex",
            "-no-shell-escape",
            "-interaction=nonstopmode",
            "-output-directory", str(tmp_path),
            str(tex_file),
        ]
        last_log = ""
        try:
            result = await asyncio.to_thread(
                subprocess.run,
                pdflatex_cmd,
                cwd=str(tmp_path),
                capture_output=True,
                timeout=timeout,
                check=False,
            )
            last_log = (result.stdout or b"").decode("utf-8", errors="replace")
            log_path = tmp_path / "doc.log"
            if log_path.exists():
                last_log = log_path.read_text(encoding="utf-8", errors="replace")
        except subprocess.TimeoutExpired:
            return TikzRenderResult(
                ok=False, svg=None,
                log=f"pdflatex TIMEOUT dopo {timeout}s",
                duration_ms=int((loop.time() - started) * 1000),
            )
        except FileNotFoundError as e:
            raise TikzRenderError(f"pdflatex non trovato: {e}")

        pdf_path = tmp_path / "doc.pdf"
        if not pdf_path.exists():
            return TikzRenderResult(
                ok=False, svg=None,
                log=_truncate_log(last_log),
                duration_ms=int((loop.time() - started) * 1000),
            )

        # Step 2: dvisvgm --pdf → SVG.
        # --no-fonts: converti i font in path SVG (no font-deps lato browser).
        # NIENTE --output: il long-opt vuole sintassi `=pattern`, ma con
        # `documento.pdf` come input dvisvgm scrive in `documento.svg` nella
        # CWD di default, che e' esattamente cio' che ci serve.
        svg_path = tmp_path / "doc.svg"
        dvisvgm_cmd = [
            "dvisvgm",
            "--pdf",
            "--no-fonts",
            str(pdf_path),
        ]
        try:
            result2 = await asyncio.to_thread(
                subprocess.run,
                dvisvgm_cmd,
                cwd=str(tmp_path),
                capture_output=True,
                timeout=timeout,
                check=False,
            )
        except subprocess.TimeoutExpired:
            return TikzRenderResult(
                ok=False, svg=None,
                log=last_log + "\n--- dvisvgm TIMEOUT ---",
                duration_ms=int((loop.time() - started) * 1000),
            )
        except FileNotFoundError as e:
            raise TikzRenderError(f"dvisvgm non trovato: {e}")

        if result2.returncode != 0 or not svg_path.exists():
            dvi_stdout = (result2.stdout or b"").decode("utf-8", errors="replace")
            dvi_stderr = (result2.stderr or b"").decode("utf-8", errors="replace")
            return TikzRenderResult(
                ok=False, svg=None,
                log=(
                    last_log
                    + "\n--- dvisvgm stdout ---\n" + _truncate_log(dvi_stdout)
                    + "\n--- dvisvgm stderr ---\n" + _truncate_log(dvi_stderr)
                ),
                duration_ms=int((loop.time() - started) * 1000),
            )

        svg_bytes = svg_path.read_bytes()
        duration_ms = int((loop.time() - started) * 1000)
        logger.info(
            "tikz render ok duration_ms=%d source_bytes=%d svg_bytes=%d",
            duration_ms, len(tikz_source), len(svg_bytes),
        )
        return TikzRenderResult(
            ok=True, svg=svg_bytes, log="", duration_ms=duration_ms,
        )


def _truncate_log(log: str, max_chars: int = 4000) -> str:
    if len(log) <= max_chars:
        return log
    head = log[: max_chars // 2]
    tail = log[-max_chars // 2 :]
    return f"{head}\n\n[...log troncato a {max_chars} caratteri...]\n\n{tail}"
