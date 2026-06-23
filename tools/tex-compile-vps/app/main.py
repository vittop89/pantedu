"""tex-compile-vps — FastAPI app entrypoint.

Endpoints:
  GET  /health         — liveness probe (no auth)
  POST /compile        — compila .tex e ritorna PDF (HMAC required)
  POST /synctex/edit   — reverse sync (page, x, y) → (file, line, col)
                         usando il binario `synctex` di TeX Live
                         (HMAC required, G21.2)

Modi di risposta /compile:
  - default            → application/pdf binario (per integrazione "fire & forget")
  - ?with_artifacts=1  → application/json con {pdf_b64, log, synctex_gz_b64,
                         aux, fls, warnings} per preview modal con SyncTeX

Avvio locale (dev):
  uvicorn app.main:app --host 127.0.0.1 --port 8001 --reload

Avvio prod (gestito da systemd):
  uvicorn app.main:app --host 127.0.0.1 --port 8001 --workers 2
"""
from __future__ import annotations

import asyncio
import base64
import json
import logging
import os
import re
import sys
import tempfile
from pathlib import Path

# Windows: forza ProactorEventLoopPolicy che supporta subprocess.
# Sul VPS Linux di produzione e' un no-op (SelectorEventLoopPolicy
# default supporta subprocess via signalfd). Necessario per dev locale
# su Windows con Python 3.10+ dove uvicorn potrebbe attivare un
# SelectorEventLoop incompatibile con `asyncio.create_subprocess_exec`.
if sys.platform == "win32":
    asyncio.set_event_loop_policy(asyncio.WindowsProactorEventLoopPolicy())

from fastapi import Depends, FastAPI, HTTPException, Query, Response, status
from pydantic import BaseModel, Field

from .auth import verify_request
from .compile import (
    ALLOWED_ENGINES,
    BundleFile,
    CompileError,
    CompileResult,
    compile_bundle,
    compile_tex,
)
from .tikz_render import (
    TikzRenderError,
    render_tikz as render_tikz_impl,
)
from .format_tex import (
    FormatError,
    format_source as format_source_impl,
)
from .svg_render import (
    SvgToPdfError,
    svg_to_pdf as svg_to_pdf_impl,
)


_LOG_LEVEL = os.environ.get("TEX_COMPILE_LOG_LEVEL", "INFO").upper()
logging.basicConfig(
    level=_LOG_LEVEL,
    format="%(asctime)s %(levelname)s %(name)s %(message)s",
)
logger = logging.getLogger("tex-compile")

_MAX_CONCURRENCY = int(os.environ.get("TEX_COMPILE_MAX_CONCURRENCY", "3"))
_DEFAULT_ENGINE = os.environ.get("TEX_COMPILE_DEFAULT_ENGINE", "pdflatex")

app = FastAPI(
    title="tex-compile-vps",
    version="1.4.1",
    description="Microservizio compile LaTeX → PDF per pantedu (single-file + multi-file bundle).",
    docs_url=None,
    redoc_url=None,
    openapi_url=None,
)

_semaphore = asyncio.Semaphore(_MAX_CONCURRENCY)


class CompileRequest(BaseModel):
    tex_b64: str = Field(..., description="Sorgente .tex codificato base64")
    doc_id: str = Field(..., min_length=1, max_length=64,
                        pattern=r"^[A-Za-z0-9_\-.]+$",
                        description="Identificativo opaco per logging")
    engine: str = Field(default=_DEFAULT_ENGINE,
                        description=f"Engine LaTeX. Allowed: {ALLOWED_ENGINES}")
    passes: int = Field(default=2, ge=1, le=4)


@app.get("/health")
async def health() -> dict:
    return {"status": "ok", "service": "tex-compile-vps", "version": "1.4.1"}


# ────────────────── G22.S15 — Format LaTeX endpoint ──────────────────


class FormatTexRequest(BaseModel):
    source_b64: str = Field(..., description="Sorgente LaTeX/TikZ codificato base64")
    doc_id: str = Field(default="format", min_length=1, max_length=80,
        pattern=r"^[A-Za-z0-9_\-.:]+$")


@app.post("/format-tex", dependencies=[Depends(verify_request)])
async def format_tex_endpoint(payload: FormatTexRequest) -> Response:
    """G22.S15 — Formatta una stringa LaTeX/TikZ via latexindent.pl.
    Risposta: 200 + JSON {ok, formatted, duration_ms} o 422 se fallisce."""
    try:
        src_bytes = base64.b64decode(payload.source_b64, validate=True)
    except Exception as e:
        raise HTTPException(status.HTTP_400_BAD_REQUEST, f"source_b64 invalido: {e}")
    if len(src_bytes) > 1 * 1024 * 1024:
        raise HTTPException(status.HTTP_413_REQUEST_ENTITY_TOO_LARGE, "source > 1 MB")
    try:
        source = src_bytes.decode("utf-8")
    except UnicodeDecodeError as e:
        raise HTTPException(status.HTTP_400_BAD_REQUEST, f"non UTF-8: {e}")

    async with _semaphore:
        try:
            result = await format_source_impl(source)
        except FormatError as e:
            raise HTTPException(status.HTTP_400_BAD_REQUEST, str(e))

    payload_out = {
        "ok": result.ok,
        "duration_ms": result.duration_ms,
    }
    if result.ok:
        payload_out["formatted"] = result.formatted or source
    else:
        payload_out["log"] = result.log
    return Response(
        content=json.dumps(payload_out).encode("utf-8"),
        status_code=status.HTTP_200_OK if result.ok else status.HTTP_422_UNPROCESSABLE_ENTITY,
        media_type="application/json",
    )


# ────────────────── G22.S15.bis Fase 4 — SVG → PDF endpoint ──────────────────


class SvgToPdfRequest(BaseModel):
    svg_b64: str = Field(..., description="Sorgente SVG XML codificato base64")
    doc_id: str = Field(default="svg", min_length=1, max_length=80,
                        pattern=r"^[A-Za-z0-9_\-.:]+$",
                        description="Identificativo opaco per logging")
    dpi: int = Field(default=96, ge=72, le=600)


@app.post("/svg-to-pdf", dependencies=[Depends(verify_request)])
async def svg_to_pdf_endpoint(payload: SvgToPdfRequest) -> Response:
    """G22.S15.bis Fase 4 — Converte un SVG in PDF vettoriale via rsvg-convert.

    Usato dal pipeline pdflatex per integrare i grafici GeoGebra (e altri
    SVG embedded) in `\\includegraphics{...}`. rsvg-convert produce PDF
    con cairo, vettoriale puro, testo nativo, niente rasterizzazione.

    Risposta:
      200 + application/pdf (binary) → PDF generato con successo
      422 + application/json {ok:false, log} → conversione fallita
      400/413 errori di validazione/dimensione
    """
    try:
        svg_bytes = base64.b64decode(payload.svg_b64, validate=True)
    except Exception as e:
        raise HTTPException(status.HTTP_400_BAD_REQUEST, f"svg_b64 invalido: {e}")
    if len(svg_bytes) > 4 * 1024 * 1024:
        raise HTTPException(status.HTTP_413_REQUEST_ENTITY_TOO_LARGE, "svg > 4 MB")
    try:
        svg_source = svg_bytes.decode("utf-8")
    except UnicodeDecodeError as e:
        raise HTTPException(status.HTTP_400_BAD_REQUEST, f"svg non UTF-8: {e}")

    async with _semaphore:
        try:
            result = await svg_to_pdf_impl(svg_source, dpi=payload.dpi)
        except SvgToPdfError as e:
            raise HTTPException(status.HTTP_400_BAD_REQUEST, str(e))

    if not result.ok or result.pdf is None:
        return Response(
            content=json.dumps({"ok": False, "log": result.log[:4000], "duration_ms": result.duration_ms}).encode("utf-8"),
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            media_type="application/json",
        )
    return Response(
        content=result.pdf,
        status_code=status.HTTP_200_OK,
        media_type="application/pdf",
        headers={"X-Duration-Ms": str(result.duration_ms)},
    )


# ────────────────── G22.S15 — TikZ → SVG render endpoint ──────────────────


class TikzRenderRequest(BaseModel):
    tikz_b64: str = Field(..., description="Sorgente TikZ (frammento o doc completo) base64")
    libraries: list[str] = Field(default_factory=list,
        description="\\usetikzlibrary{...} (allowlist server-side)")
    pgfplots_libraries: list[str] = Field(default_factory=list,
        description="\\usepgfplotslibrary{...}")
    extra_packages: list[str] = Field(default_factory=list,
        description="Pacchetti aggiuntivi oltre amsmath/amssymb (allowlist)")
    border: str = Field(default="2pt", max_length=12,
        description="Bordo del documento standalone (es. '2pt', '0pt')")
    doc_id: str = Field(default="tikz", min_length=1, max_length=80,
        pattern=r"^[A-Za-z0-9_\-.:]+$",
        description="Identificativo opaco per logging (es. hash sorgente)")


@app.post("/render-tikz", dependencies=[Depends(verify_request)])
async def render_tikz_endpoint(payload: TikzRenderRequest) -> Response:
    """G22.S15 — Compila un frammento TikZ in SVG vettoriale.

    Pipeline server: pdflatex + dvisvgm --pdf --no-fonts.
    Il client (PHP TikzRenderClient) invoca questo endpoint quando una
    pagina richiede un TikZ non presente in cache.

    Risposta:
      200 + image/svg+xml  → SVG generato
      422 + application/json {ok:false, log} → errore di compilazione
    """
    try:
        tikz_bytes = base64.b64decode(payload.tikz_b64, validate=True)
    except Exception as e:
        raise HTTPException(status.HTTP_400_BAD_REQUEST, f"tikz_b64 invalido: {e}")
    if len(tikz_bytes) > 1 * 1024 * 1024:
        raise HTTPException(status.HTTP_413_REQUEST_ENTITY_TOO_LARGE, "tikz > 1 MB")

    try:
        tikz_source = tikz_bytes.decode("utf-8")
    except UnicodeDecodeError as e:
        raise HTTPException(status.HTTP_400_BAD_REQUEST, f"tikz non UTF-8: {e}")

    async with _semaphore:
        try:
            result = await render_tikz_impl(
                tikz_source,
                libraries=payload.libraries,
                pgfplots_libraries=payload.pgfplots_libraries,
                extra_packages=payload.extra_packages,
                border=payload.border,
            )
        except TikzRenderError as e:
            raise HTTPException(status.HTTP_400_BAD_REQUEST, str(e))

    if not result.ok:
        logger.warning(
            "tikz render failed doc_id=%s duration_ms=%d",
            payload.doc_id, result.duration_ms,
        )
        return Response(
            content=json.dumps({
                "ok": False,
                "log": result.log,
                "duration_ms": result.duration_ms,
            }).encode("utf-8"),
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            media_type="application/json",
        )

    logger.info(
        "tikz render ok doc_id=%s duration_ms=%d svg_bytes=%d",
        payload.doc_id, result.duration_ms, len(result.svg or b""),
    )
    return Response(
        content=result.svg or b"",
        status_code=status.HTTP_200_OK,
        media_type="image/svg+xml",
        headers={
            "X-Compile-Duration-Ms": str(result.duration_ms),
            "X-Compile-Engine": "pdflatex+dvisvgm",
        },
    )


# ────────────────── G22.S4.B.3 — Multi-file bundle endpoint ──────────────


class BundleFilePayload(BaseModel):
    path: str = Field(..., min_length=1, max_length=255,
                      description="Path relativo al bundle root (es. versioni/main_NOR.tex)")
    content_b64: str = Field(..., description="Contenuto file codificato base64")


class CompileBundleRequest(BaseModel):
    files: list[BundleFilePayload] = Field(..., min_length=1, max_length=64,
        description="Lista file del bundle (max 64 per evitare abusi)")
    main_path: str = Field(..., min_length=1, max_length=255,
        description="Path relativo del file da compilare (es. versioni/main_NOR.tex)")
    doc_id: str = Field(..., min_length=1, max_length=64,
                        pattern=r"^[A-Za-z0-9_\-.]+$",
                        description="Identificativo opaco per logging")
    engine: str = Field(default=_DEFAULT_ENGINE)
    passes: int = Field(default=2, ge=1, le=4)


@app.post("/compile-bundle", dependencies=[Depends(verify_request)])
async def compile_bundle_endpoint(
    payload: CompileBundleRequest,
    with_artifacts: int = Query(0, ge=0, le=1),
) -> Response:
    """G22.S4.B.3 — Compila un bundle LaTeX multi-file.

    Il client (PHP TexCompileClient::compileBundle) decifra la manifest
    tex_files della verifica, base64-encode ogni file, e POSTa qui. Il
    server materializza in tmpdir + compila il main_path indicato.

    Vantaggio rispetto a /compile (single-file):
      - niente piu' inline-expansion lato app (`flatten()`)
      - .tex piu' pulito leggibile (preservato il \input strutturato)
      - upgrade path per dedup futuro (cache texCommon condivisa).

    Risposta: identica a /compile (PDF binario default, JSON con
    artefatti se ?with_artifacts=1).
    """
    files: list[BundleFile] = []
    for f in payload.files:
        try:
            content = base64.b64decode(f.content_b64, validate=True)
        except Exception as e:
            raise HTTPException(
                status.HTTP_400_BAD_REQUEST,
                f"content_b64 invalido per '{f.path}': {e}",
            )
        files.append(BundleFile(path=f.path, content=content))

    want_artifacts = bool(with_artifacts)

    async with _semaphore:
        try:
            result = await compile_bundle(
                files,
                main_path=payload.main_path,
                engine=payload.engine,
                passes=payload.passes,
                with_artifacts=want_artifacts,
            )
        except CompileError as e:
            raise HTTPException(status.HTTP_400_BAD_REQUEST, str(e))

    if not result.ok:
        logger.warning(
            "compile-bundle failed doc_id=%s engine=%s files=%d duration_ms=%d artifacts=%s",
            payload.doc_id, result.engine, len(payload.files), result.duration_ms, want_artifacts,
        )
        return Response(
            content=_json_error_payload(result, want_artifacts),
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            media_type="application/json",
        )

    logger.info(
        "compile-bundle ok doc_id=%s engine=%s files=%d duration_ms=%d pdf_bytes=%d artifacts=%s",
        payload.doc_id, result.engine, len(payload.files), result.duration_ms,
        len(result.pdf or b""), want_artifacts,
    )

    common_headers = {
        "X-Compile-Duration-Ms": str(result.duration_ms),
        "X-Compile-Engine": result.engine,
        "X-Compile-Passes": str(result.passes),
    }
    if want_artifacts:
        return Response(
            content=_json_artifacts_payload(result),
            status_code=status.HTTP_200_OK,
            media_type="application/json",
            headers=common_headers,
        )
    return Response(
        content=result.pdf or b"",
        status_code=status.HTTP_200_OK,
        media_type="application/pdf",
        headers=common_headers,
    )


@app.post("/compile", dependencies=[Depends(verify_request)])
async def compile_endpoint(
    payload: CompileRequest,
    with_artifacts: int = Query(0, ge=0, le=1,
        description="Se 1: ritorna JSON con pdf_b64 + synctex_gz_b64 + log + aux + fls + warnings parsed (per preview modal)"),
) -> Response:
    try:
        tex_bytes = base64.b64decode(payload.tex_b64, validate=True)
    except Exception as e:
        raise HTTPException(
            status.HTTP_400_BAD_REQUEST,
            f"tex_b64 non valido: {e}",
        )

    if len(tex_bytes) > 5 * 1024 * 1024:
        raise HTTPException(
            status.HTTP_413_REQUEST_ENTITY_TOO_LARGE,
            "Sorgente .tex > 5 MB non supportato",
        )

    want_artifacts = bool(with_artifacts)

    async with _semaphore:
        try:
            result = await compile_tex(
                tex_bytes,
                engine=payload.engine,
                passes=payload.passes,
                with_artifacts=want_artifacts,
            )
        except CompileError as e:
            raise HTTPException(status.HTTP_400_BAD_REQUEST, str(e))

    if not result.ok:
        logger.warning(
            "compile failed doc_id=%s engine=%s duration_ms=%d artifacts=%s",
            payload.doc_id, result.engine, result.duration_ms, want_artifacts,
        )
        # In modalità artifacts ritorniamo JSON anche su errore (con log + warnings).
        return Response(
            content=_json_error_payload(result, want_artifacts),
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            media_type="application/json",
        )

    logger.info(
        "compile ok doc_id=%s engine=%s duration_ms=%d pdf_bytes=%d artifacts=%s",
        payload.doc_id, result.engine, result.duration_ms,
        len(result.pdf or b""), want_artifacts,
    )

    common_headers = {
        "X-Compile-Duration-Ms": str(result.duration_ms),
        "X-Compile-Engine": result.engine,
        "X-Compile-Passes": str(result.passes),
    }

    if want_artifacts:
        return Response(
            content=_json_artifacts_payload(result),
            status_code=status.HTTP_200_OK,
            media_type="application/json",
            headers=common_headers,
        )
    return Response(
        content=result.pdf or b"",
        status_code=status.HTTP_200_OK,
        media_type="application/pdf",
        headers=common_headers,
    )


# ────────────────────────────── helpers ────────────────────────────────

def _json_error_payload(result: CompileResult, with_artifacts: bool) -> bytes:
    payload = {
        "ok": False,
        "engine": result.engine,
        "passes": result.passes,
        "duration_ms": result.duration_ms,
        "log": result.log,
    }
    if with_artifacts:
        payload["warnings"] = parse_latex_warnings(result.log)
    return json.dumps(payload).encode("utf-8")


def _json_artifacts_payload(result: CompileResult) -> bytes:
    """Costruisce JSON con tutti gli artefatti compilation (per preview modal)."""
    # G22.S16 — formatted_files: dict {path: bytes} → b64 encoded (sicuro per JSON).
    formatted_b64: dict[str, str] = {}
    for path, content in (result.formatted_files or {}).items():
        try:
            formatted_b64[path] = base64.b64encode(content).decode("ascii")
        except Exception:
            continue

    payload = {
        "ok": True,
        "engine": result.engine,
        "passes": result.passes,
        "duration_ms": result.duration_ms,
        "pdf_b64":     base64.b64encode(result.pdf or b"").decode("ascii"),
        "log":         result.log,
        "synctex_gz_b64": base64.b64encode(result.synctex_gz or b"").decode("ascii"),
        "aux":         result.aux,
        "fls":         result.fls,
        "warnings":    parse_latex_warnings(result.log),
        "errors":      parse_latex_errors(result.log),
        "pdf_bytes":   len(result.pdf or b""),
        "synctex_bytes": len(result.synctex_gz or b""),
        "formatted_files_b64": formatted_b64,
    }
    return json.dumps(payload).encode("utf-8")


# Pattern LaTeX warnings comuni — match riga + messaggio.
_WARN_PATTERNS = [
    # "Underfull \hbox (badness 10000) in paragraph at lines 327--331"
    re.compile(r"^(Underfull|Overfull) \\(?:hbox|vbox)[^\n]*?at lines? (\d+)(?:--(\d+))?", re.MULTILINE),
    # "LaTeX Warning: Reference `foo' on page 1 undefined on input line 42."
    re.compile(r"^(LaTeX Warning): ([^\n]+?)(?:on input line (\d+))?\.?$", re.MULTILINE),
    # "Package fontspec Warning: ..." (include line ref opzionale)
    re.compile(r"^(Package \S+ Warning): ([^\n]+?)(?:on input line (\d+))?\.?$", re.MULTILINE),
]

_ERROR_PATTERNS = [
    # "! Missing $ inserted." + "l.362 ..."
    re.compile(r"^! ([^\n]+)\nl\.(\d+)\s*([^\n]*)", re.MULTILINE),
    re.compile(r"^! ([^\n]+)$", re.MULTILINE),
]


# ──────────────────────── G21.2 SyncTeX edit ──────────────────────────

class SynctexEditRequest(BaseModel):
    synctex_gz_b64: str = Field(..., description="Synctex .gz blob, base64")
    page: int = Field(..., ge=1, le=999, description="Pagina PDF 1-indexed")
    x:    float = Field(..., description="Coordinata X in pt (PDF space)")
    y:    float = Field(..., description="Coordinata Y in pt (PDF space)")


@app.post("/synctex/edit", dependencies=[Depends(verify_request)])
async def synctex_edit_endpoint(payload: SynctexEditRequest) -> Response:
    """G21.2 — reverse sync nativo via binario `synctex` di TeX Live.

    Scrive il blob .synctex.gz su tmpdir, esegue `synctex edit -o
    page:x:y:doc.pdf` (doc.pdf è solo un identificatore, non serve esistere)
    e parse l'output.

    Output del binario synctex:
        Output:doc.tex
        Line:123
        Column:45
        ... (altri campi)

    Risposta JSON:
        {ok: true, file: "doc.tex", line: 123, column: 45}
    """
    try:
        synctex_bytes = base64.b64decode(payload.synctex_gz_b64, validate=True)
    except Exception as e:
        raise HTTPException(status.HTTP_400_BAD_REQUEST, f"synctex_gz_b64 invalido: {e}")

    if len(synctex_bytes) > 5 * 1024 * 1024:
        raise HTTPException(status.HTTP_413_REQUEST_ENTITY_TOO_LARGE, "synctex > 5 MB")

    workdir = os.environ.get("TEX_COMPILE_WORKDIR", "/tmp/tex-compile")
    Path(workdir).mkdir(parents=True, exist_ok=True)

    with tempfile.TemporaryDirectory(dir=workdir, prefix="synctex_") as tmp:
        tmp_path = Path(tmp)
        synctex_file = tmp_path / "doc.synctex.gz"
        synctex_file.write_bytes(synctex_bytes)

        # synctex CLI cerca <pdf>.synctex.gz nella stessa cartella di <pdf>.
        # Doc.pdf NON deve esistere: synctex usa solo il .synctex.gz.
        # Spec: synctex edit -o page:x:y:pdf_path
        spec = f"{payload.page}:{payload.x}:{payload.y}:{tmp_path}/doc.pdf"

        try:
            proc = await asyncio.create_subprocess_exec(
                "synctex", "edit", "-o", spec,
                stdout=asyncio.subprocess.PIPE,
                stderr=asyncio.subprocess.STDOUT,
                cwd=str(tmp_path),
            )
        except FileNotFoundError:
            raise HTTPException(
                status.HTTP_501_NOT_IMPLEMENTED,
                "Binario `synctex` non trovato sul VPS. Installare TeX Live.",
            )

        try:
            stdout, _ = await asyncio.wait_for(proc.communicate(), timeout=10)
        except asyncio.TimeoutError:
            proc.kill()
            await proc.wait()
            raise HTTPException(status.HTTP_504_GATEWAY_TIMEOUT, "synctex CLI timeout")

        out = (stdout or b"").decode("utf-8", errors="replace")

        # Parse output. synctex edit emette uno o più blocchi:
        #   SyncTeX result begin
        #   Output:doc.tex
        #   Page:1
        #   x:...
        #   y:...
        #   ... fields ...
        #   SyncTeX result end
        #
        # Estraiamo il PRIMO blocco (più rilevante).
        result = _parse_synctex_edit_output(out)
        if not result:
            return Response(
                content=json.dumps({
                    "ok": False,
                    "error": "no_match",
                    "raw": out[:500],
                    "exit_code": proc.returncode,
                }),
                status_code=status.HTTP_200_OK,
                media_type="application/json",
            )

        logger.info(
            "synctex edit ok page=%d x=%.1f y=%.1f → file=%s line=%d col=%d",
            payload.page, payload.x, payload.y,
            result.get("file"), result.get("line", -1), result.get("column", -1),
        )
        return Response(
            content=json.dumps({"ok": True, **result}),
            status_code=status.HTTP_200_OK,
            media_type="application/json",
        )


def _parse_synctex_edit_output(out: str) -> dict | None:
    """Parse output del comando `synctex edit -o ...`.

    Esempio output:
        This is SyncTeX command line utility, version 1.21
        SyncTeX result begin
        Output:doc.tex
        Input:/tmp/tex-compile/job_xyz/doc.tex
        Line:128
        Column:-1
        Offset:0
        Context:Some context
        SyncTeX result end
    """
    lines = out.splitlines()
    in_block = False
    fields: dict = {}
    for ln in lines:
        if ln.strip() == "SyncTeX result begin":
            in_block = True
            fields = {}
            continue
        if ln.strip() == "SyncTeX result end":
            if fields.get("Input") or fields.get("Output"):
                return {
                    "file":   fields.get("Input") or fields.get("Output", ""),
                    "line":   int(fields.get("Line", 0)) if fields.get("Line") else 0,
                    "column": int(fields.get("Column", -1)) if fields.get("Column") else -1,
                    "page":   int(fields.get("Page", 0))   if fields.get("Page")   else 0,
                }
            in_block = False
            continue
        if in_block:
            m = re.match(r"^([A-Za-z]+):\s*(.+)$", ln)
            if m:
                fields[m.group(1)] = m.group(2).strip()
    return None


# ──────────────────────── log parsers ──────────────────────────────

def parse_latex_warnings(log: str, limit: int = 50) -> list[dict]:
    """Estrae warnings strutturati dal log LaTeX per UI."""
    warnings: list[dict] = []
    if not log:
        return warnings
    for pat in _WARN_PATTERNS:
        for m in pat.finditer(log):
            line = None
            kind = m.group(1)
            try:
                # Cerca primo gruppo numerico nel match
                for g in m.groups()[1:]:
                    if g and g.isdigit():
                        line = int(g)
                        break
            except Exception:
                pass
            warnings.append({
                "kind": kind,
                "line": line,
                "message": (m.group(0) or "").strip()[:200],
            })
            if len(warnings) >= limit:
                return warnings
    return warnings


def parse_latex_errors(log: str, limit: int = 20) -> list[dict]:
    """Estrae errori strutturati (! ... + l.XX) dal log LaTeX."""
    errors: list[dict] = []
    if not log:
        return errors
    seen: set[str] = set()
    for pat in _ERROR_PATTERNS:
        for m in pat.finditer(log):
            msg = m.group(1).strip()
            line = None
            if m.lastindex and m.lastindex >= 2:
                g2 = m.group(2)
                if g2 and g2.isdigit():
                    line = int(g2)
            key = f"{line}:{msg}"
            if key in seen:
                continue
            seen.add(key)
            errors.append({"line": line, "message": msg[:300]})
            if len(errors) >= limit:
                return errors
    return errors
