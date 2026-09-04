"""G22.S15.bis Fase 4 — SVG → PDF (vettoriale) via rsvg-convert.

Endpoint /svg-to-pdf usato dal pipeline pdflatex per convertire i blocchi
GeoGebra (`<svg>` HTML inline) in PDF da `\\includegraphics`.

rsvg-convert (librsvg2-bin) è il convertitore SVG→PDF standard di GNOME.
Output cairo: vettoriale puro, no rasterizzazione, mantiene testo nativo.
Più leggero e veloce di inkscape, qualità identica per SVG semplici tipici
di GeoGebra (assi, curve, punti, label).
"""
from __future__ import annotations

import asyncio
import logging
import os
import subprocess
import tempfile
from dataclasses import dataclass
from pathlib import Path

logger = logging.getLogger(__name__)

DEFAULT_TIMEOUT = int(os.environ.get("SVG_TO_PDF_TIMEOUT", "10"))
DEFAULT_DPI = int(os.environ.get("SVG_TO_PDF_DPI", "96"))
MAX_SVG_SIZE = 4 * 1024 * 1024  # 4 MB


@dataclass
class SvgToPdfResult:
    ok: bool
    pdf: bytes | None
    log: str
    duration_ms: int


class SvgToPdfError(Exception):
    pass


async def svg_to_pdf(svg_source: str, *, timeout: int = DEFAULT_TIMEOUT, dpi: int = DEFAULT_DPI) -> SvgToPdfResult:
    """Converte una STRINGA SVG in PDF binary via rsvg-convert.

    Args:
        svg_source: contenuto SVG XML come stringa.
        timeout:    timeout subprocess in secondi.
        dpi:        DPI per il rendering (default 96, equivalente standard web).

    Ritorna PDF in bytes pronti da scrivere su file e includere via pdflatex.
    """
    if not svg_source or not svg_source.strip():
        return SvgToPdfResult(ok=False, pdf=None, log="empty source", duration_ms=0)
    if len(svg_source) > MAX_SVG_SIZE:
        raise SvgToPdfError(f"SVG source too large (>{MAX_SVG_SIZE} bytes)")

    # Sanity: deve almeno iniziare con <svg> o <?xml.
    head = svg_source.lstrip()[:200].lower()
    if not (head.startswith("<?xml") or head.startswith("<svg")):
        raise SvgToPdfError("not_a_valid_svg")

    workdir = os.environ.get("TEX_COMPILE_WORKDIR", "/var/tmp/tex-compile")
    Path(workdir).mkdir(parents=True, exist_ok=True)
    loop = asyncio.get_event_loop()
    started = loop.time()

    with tempfile.TemporaryDirectory(dir=workdir, prefix="svg_") as tmp:
        tmp_path = Path(tmp)
        svg_file = tmp_path / "in.svg"
        pdf_file = tmp_path / "out.pdf"
        svg_file.write_text(svg_source, encoding="utf-8")

        cmd = [
            "rsvg-convert",
            "--format", "pdf",
            "--dpi-x", str(dpi), "--dpi-y", str(dpi),
            "--keep-aspect-ratio",
            "--output", str(pdf_file),
            str(svg_file),
        ]
        try:
            result = await asyncio.to_thread(
                subprocess.run,
                cmd,
                cwd=str(tmp_path),
                capture_output=True,
                timeout=timeout,
                check=False,
            )
        except subprocess.TimeoutExpired:
            return SvgToPdfResult(
                ok=False, pdf=None,
                log=f"rsvg-convert TIMEOUT dopo {timeout}s",
                duration_ms=int((loop.time() - started) * 1000),
            )
        except FileNotFoundError as e:
            raise SvgToPdfError(f"rsvg-convert non trovato: {e}")

        duration_ms = int((loop.time() - started) * 1000)
        if result.returncode != 0:
            log = (result.stderr or b"").decode("utf-8", errors="replace") \
                  + "\n" + (result.stdout or b"").decode("utf-8", errors="replace")
            return SvgToPdfResult(ok=False, pdf=None, log=log[:4000], duration_ms=duration_ms)

        if not pdf_file.exists():
            return SvgToPdfResult(ok=False, pdf=None, log="rsvg-convert non ha prodotto output", duration_ms=duration_ms)
        pdf_bytes = pdf_file.read_bytes()
        if not pdf_bytes.startswith(b"%PDF-"):
            return SvgToPdfResult(ok=False, pdf=None, log="output non e' un PDF valido", duration_ms=duration_ms)

        logger.info(
            "svg-to-pdf ok duration_ms=%d svg_bytes=%d pdf_bytes=%d",
            duration_ms, len(svg_source), len(pdf_bytes),
        )
        return SvgToPdfResult(ok=True, pdf=pdf_bytes, log="", duration_ms=duration_ms)
