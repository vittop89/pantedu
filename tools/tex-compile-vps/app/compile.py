"""Wrapper subprocess per pdflatex/xelatex/lualatex.

Strategia:
  1. Crea tmpdir isolato sotto WORKDIR
  2. Scrive .tex su disco
  3. Esegue engine N volte (default 2 per risolvere \\ref e TOC)
  4. Legge PDF risultante (se esiste)
  5. Cleanup tmpdir

Sicurezza:
  - `-no-shell-escape` blocca \\write18 → no esecuzione comandi arbitrari
  - `-interaction=batchmode` evita prompt che congelerebbero il subprocess
  - Timeout duro al subprocess (kill se sfora)
  - Engine validato contro allowlist
"""
from __future__ import annotations

import asyncio
import logging
import os
import re
import shutil
import tempfile
from dataclasses import dataclass, field
from pathlib import Path

from .format_tex import latexindent_format, format_all_tex_in_dir

logger = logging.getLogger(__name__)

ALLOWED_ENGINES = ("pdflatex", "xelatex", "lualatex")
WORKDIR = os.environ.get("TEX_COMPILE_WORKDIR", "/var/tmp/tex-compile")
DEFAULT_TIMEOUT = int(os.environ.get("TEX_COMPILE_TIMEOUT", "30"))
DEFAULT_PASSES = int(os.environ.get("TEX_COMPILE_PASSES", "2"))


@dataclass
class CompileResult:
    ok: bool
    pdf: bytes | None
    log: str
    duration_ms: int
    engine: str
    passes: int
    # G21.1 — artefatti opzionali per preview modal (SyncTeX bidirezionale,
    # warnings parsati, .aux per cache referenze). Popolati solo se compile
    # invocato con `with_artifacts=True`.
    synctex_gz: bytes | None = None
    aux: str = ""
    fls: str = ""
    # G22.S16 — file .tex riformattati da latexindent prima del compile.
    # Per single-file: chiave 'doc.tex'. Per bundle: path relativi originali.
    # Vuoto se latexindent non installato/disabilitato (no-op fallback).
    formatted_files: dict = field(default_factory=dict)


class CompileError(Exception):
    pass


# FND-011 (audit 2026-06-15) — comandi LaTeX che leggono file dal disco.
_INCLUDE_RE = re.compile(
    r"\\(?:input|include|InputIfFileExists|openin|import|subimport"
    r"|subfile|lstinputlisting|verbatiminput|includegraphics)\b"
    r"\s*(?:\[[^\]]*\])?\s*\{?\s*([^}\s{][^}\s]*)"
)


def _assert_safe_tex_includes(content: bytes, sandbox_root: Path, *resolve_bases: Path) -> None:
    """FND-011 — rifiuta \\input/\\include/... con path ASSOLUTO o che escono
    dalla sandbox (tmpdir). Con `openin_any=a` pdflatex leggerebbe qualunque
    file (es. \\input{/etc/passwd}); qui blocchiamo i target pericolosi
    mantenendo i `..` legit interni (risolvono dentro sandbox_root).
    Best-effort: TeX permette offuscamento via macro → vedi raccomandazione
    openin_any=p nel report di audit (FND-011)."""
    try:
        text = content.decode("utf-8", "replace")
    except Exception:
        return
    root = sandbox_root.resolve()
    for m in _INCLUDE_RE.finditer(text):
        t = m.group(1).strip().rstrip("}")
        if not t:
            continue
        if re.match(r"^(?:/|\\|[A-Za-z]:|file:|https?:|\|)", t):
            raise CompileError(f"FND-011: include con path assoluto rifiutato: {t}")
        for base in resolve_bases:
            try:
                (base / t).resolve().relative_to(root)
            except ValueError:
                raise CompileError(f"FND-011: include fuori dal bundle rifiutato: {t}")


async def compile_tex(
    tex_source: bytes,
    *,
    engine: str = "pdflatex",
    passes: int = DEFAULT_PASSES,
    timeout: int = DEFAULT_TIMEOUT,
    with_artifacts: bool = False,
) -> CompileResult:
    """Compila .tex e ritorna PDF + (opzionale) artefatti.

    Args:
        with_artifacts: se True, raccoglie anche doc.synctex.gz, doc.aux,
            doc.fls per supportare preview modal con SyncTeX bidirezionale.
            Aggiunge i flag `-synctex=1 -recorder` al comando.
    """
    if engine not in ALLOWED_ENGINES:
        raise CompileError(f"Engine '{engine}' non ammesso. Allowed: {ALLOWED_ENGINES}")
    if passes < 1 or passes > 4:
        raise CompileError("passes deve essere 1..4")

    Path(WORKDIR).mkdir(parents=True, exist_ok=True)
    loop = asyncio.get_event_loop()
    started = loop.time()

    with tempfile.TemporaryDirectory(dir=WORKDIR, prefix="job_") as tmp:
        tmp_path = Path(tmp)
        tex_file = tmp_path / "doc.tex"
        tex_file.write_bytes(tex_source)

        # FND-011 (audit 2026-06-15) — guard contro lettura file arbitraria via
        # \input (openin_any=a). Blocca target con path assoluto o fuori dal
        # tmpdir. Vedi anche compile_bundle().
        _assert_safe_tex_includes(tex_source, tmp_path.resolve(), tmp_path.resolve())

        # G22.S16 — latexindent step (best-effort, no-op se non installato).
        # Modifica `doc.tex` in place. Se ok, leggiamo il content riformattato
        # per restituirlo al client.
        formatted_files: dict[str, bytes] = {}
        if await latexindent_format(tex_file):
            try:
                formatted_files["doc.tex"] = tex_file.read_bytes()
            except OSError:
                pass

        # Strategia "VSCode-compatible":
        #   - nonstopmode: LaTeX non si ferma agli errori, recupera con default
        #     (es. inserisce $ mancante automaticamente)
        #   - NO -halt-on-error: continua fino in fondo, raccoglie tutti i warning
        #   - SUCCESSO = PDF prodotto (anche con exit code != 0, comune con warning)
        #   - FALLIMENTO = nessun PDF prodotto
        #
        # G21.1 — con with_artifacts=True aggiungiamo:
        #   - -synctex=1: produce doc.synctex.gz (mapping bidirezionale TeX↔PDF)
        #   - -recorder:  produce doc.fls (file list dei pacchetti caricati)
        #
        # Equivalente a `latexmk -interaction=nonstopmode -synctex=1` (LaTeX Workshop).
        cmd = [
            engine,
            "-no-shell-escape",
            "-interaction=nonstopmode",
            "-output-directory", str(tmp_path),
        ]
        if with_artifacts:
            cmd.insert(2, "-synctex=1")
            cmd.insert(3, "-recorder")
        cmd.append(str(tex_file))

        last_log = ""
        last_returncode = 0
        pdf_path = tmp_path / "doc.pdf"

        # G22.S15.bis Fase 4 — openin_any=a (any) per accettare path con `..`.
        # Default Debian TeX Live = paranoid → blocca \usepackage{../X} comune
        # nei layout bundle (main in versioni/, deps in texCommon/ root).
        # Sicurezza preservata: i file sono in tmpdir isolato, no escape system.
        compile_env = {**os.environ, "openin_any": "a", "openout_any": "p"}

        for i in range(passes):
            try:
                proc = await asyncio.create_subprocess_exec(
                    *cmd,
                    stdout=asyncio.subprocess.PIPE,
                    stderr=asyncio.subprocess.STDOUT,
                    cwd=str(tmp_path),
                    env=compile_env,
                )
            except FileNotFoundError as e:
                raise CompileError(f"Binario {engine} non trovato. TeX Live installato?") from e

            try:
                stdout, _ = await asyncio.wait_for(proc.communicate(), timeout=timeout)
            except asyncio.TimeoutError:
                proc.kill()
                await proc.wait()
                duration_ms = int((loop.time() - started) * 1000)
                return CompileResult(
                    ok=False,
                    pdf=None,
                    log=f"TIMEOUT dopo {timeout}s alla pass {i+1}/{passes}",
                    duration_ms=duration_ms,
                    engine=engine,
                    passes=i,
                )

            last_returncode = proc.returncode
            last_log = (stdout or b"").decode("utf-8", errors="replace")

            log_file = tmp_path / "doc.log"
            if log_file.exists():
                last_log = log_file.read_text(encoding="utf-8", errors="replace")

            # In nonstopmode, exit code può essere != 0 anche con PDF generato.
            # Se NON c'è PDF e siamo all'ultima passata → fail.
            # Se c'è PDF, continuiamo con le altre passate (per refs/TOC).
            if not pdf_path.exists() and i == passes - 1:
                duration_ms = int((loop.time() - started) * 1000)
                return CompileResult(
                    ok=False,
                    pdf=None,
                    log=_truncate_log(last_log),
                    duration_ms=duration_ms,
                    engine=engine,
                    passes=i + 1,
                )

        # Successo se PDF esiste, indipendentemente dal return code.
        # LaTeX in nonstopmode esce 1 anche con warning innocui (es. font sub).
        if not pdf_path.exists():
            duration_ms = int((loop.time() - started) * 1000)
            return CompileResult(
                ok=False,
                pdf=None,
                log="Nessun PDF prodotto.\n" + _truncate_log(last_log),
                duration_ms=duration_ms,
                engine=engine,
                passes=passes,
            )

        pdf_bytes = pdf_path.read_bytes()
        duration_ms = int((loop.time() - started) * 1000)

        # G21.1 — raccolta artefatti opzionali (SyncTeX, .aux, .fls)
        synctex_bytes: bytes | None = None
        aux_text = ""
        fls_text = ""
        if with_artifacts:
            synctex_path = tmp_path / "doc.synctex.gz"
            if synctex_path.exists():
                synctex_bytes = synctex_path.read_bytes()
            aux_path = tmp_path / "doc.aux"
            if aux_path.exists():
                aux_text = aux_path.read_text(encoding="utf-8", errors="replace")
            fls_path = tmp_path / "doc.fls"
            if fls_path.exists():
                fls_text = fls_path.read_text(encoding="utf-8", errors="replace")

        logger.info(
            "compile ok engine=%s passes=%d duration_ms=%d source_bytes=%d pdf_bytes=%d "
            "returncode=%d synctex=%d aux=%d fls=%d",
            engine, passes, duration_ms, len(tex_source), len(pdf_bytes), last_returncode,
            len(synctex_bytes or b""), len(aux_text), len(fls_text),
        )
        return CompileResult(
            ok=True,
            pdf=pdf_bytes,
            log=_truncate_log(last_log) if not with_artifacts else last_log,
            duration_ms=duration_ms,
            engine=engine,
            passes=passes,
            synctex_gz=synctex_bytes,
            aux=aux_text,
            fls=fls_text,
            formatted_files=formatted_files,
        )


def _truncate_log(log: str, max_chars: int = 8000) -> str:
    if len(log) <= max_chars:
        return log
    head = log[: max_chars // 2]
    tail = log[-max_chars // 2 :]
    return f"{head}\n\n[...log troncato a {max_chars} caratteri...]\n\n{tail}"


# ────────────────── G22.S4.B.3 — Multi-file bundle compile ──────────────


@dataclass
class BundleFile:
    """Singolo file nel bundle multi-file (path relativo + bytes plaintext)."""
    path: str
    content: bytes


async def compile_bundle(
    files: list[BundleFile],
    *,
    main_path: str,
    engine: str = "pdflatex",
    passes: int = DEFAULT_PASSES,
    timeout: int = DEFAULT_TIMEOUT,
    with_artifacts: bool = False,
) -> CompileResult:
    """G22.S4.B.3 — Compila un bundle multi-file LaTeX.

    Materializza la lista di `BundleFile` in un tmpdir isolato (preservando
    la struttura di sottocartelle) e invoca il compiler sul `main_path`
    relativo. Permette compile di documenti che usano `\\input{...}` e
    `\\usepackage{texCommon/...}` senza dover prima inline-espandere
    (flatten) lato app.

    Args:
        files:    lista di file da scrivere su tmpdir.
        main_path: path relativo al tmpdir del file principale da compilare,
                   es. "versioni/main_NOR.tex".
        engine, passes, timeout, with_artifacts: come compile_tex().

    Sicurezza:
        - Validazione path traversal: ogni `path` viene risolto contro tmpdir
          e rifiutato se evade la sandbox.
        - Tutti i file scritti sotto tmpdir (cleanup automatico via TempDir).
        - Compile invocato sul main_path solo (non file arbitrari del bundle).
        - Limite totale 10 MB sul bundle (dimensione tipica < 100KB).
    """
    if engine not in ALLOWED_ENGINES:
        raise CompileError(f"Engine '{engine}' non ammesso. Allowed: {ALLOWED_ENGINES}")
    if passes < 1 or passes > 4:
        raise CompileError("passes deve essere 1..4")
    if not files:
        raise CompileError("bundle vuoto")
    if not main_path:
        raise CompileError("main_path obbligatorio")

    total_bytes = sum(len(f.content) for f in files)
    if total_bytes > 10 * 1024 * 1024:
        raise CompileError(f"bundle > 10 MB ({total_bytes} bytes)")

    # Validazione main_path: deve esistere nel bundle.
    if not any(f.path == main_path for f in files):
        raise CompileError(f"main_path '{main_path}' non presente nel bundle")

    Path(WORKDIR).mkdir(parents=True, exist_ok=True)
    loop = asyncio.get_event_loop()
    started = loop.time()

    with tempfile.TemporaryDirectory(dir=WORKDIR, prefix="bundle_") as tmp:
        tmp_path = Path(tmp).resolve()

        # Materializza tutti i file con validazione anti-traversal.
        for f in files:
            rel = f.path.lstrip("/")
            if ".." in rel.split("/"):
                raise CompileError(f"path traversal rifiutato: {f.path}")
            target = (tmp_path / rel).resolve()
            try:
                target.relative_to(tmp_path)
            except ValueError as e:
                raise CompileError(f"path fuori sandbox: {f.path}") from e
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(f.content)

        # G22.S16 — latexindent step su tutti i .tex del bundle (parallel).
        # Si applica DOPO la materializzazione cosi' multi-file con \input
        # cross-referenced vengono formattati indipendentemente.
        formatted_files = await format_all_tex_in_dir(tmp_path)

        main_file = (tmp_path / main_path).resolve()
        main_dir = main_file.parent  # es. {tmp}/versioni/

        # FND-011 — guard \input/\include con path assoluti o fuori dal bundle
        # (openin_any=a permetterebbe la lettura file arbitraria). I `..` legit
        # interni (../texCommon/X dal main in versioni/) restano consentiti:
        # pdflatex risolve i path dalla cwd = main_dir.
        for _f in files:
            if _f.path.lower().endswith((".tex", ".sty", ".cls")):
                _assert_safe_tex_includes(_f.content, tmp_path, main_dir)

        # Compila dentro main_dir cosi' i \input{texCommon/X} risolvono come
        # ../texCommon/X (layout bundle ZIP-style: main in versioni/,
        # texCommon a parent root).
        cmd = [
            engine,
            "-no-shell-escape",
            "-interaction=nonstopmode",
            "-output-directory", str(main_dir),
        ]
        if with_artifacts:
            cmd.insert(2, "-synctex=1")
            cmd.insert(3, "-recorder")
        cmd.append(main_file.name)  # "main_NOR.tex" relativo a main_dir

        last_log = ""
        last_returncode = 0
        pdf_path = main_dir / (main_file.stem + ".pdf")

        # G22.S15.bis Fase 4 — openin_any=a per layout bundle (main in
        # versioni/, deps in texCommon/ root con \usepackage{../texCommon/...}).
        compile_env = {**os.environ, "openin_any": "a", "openout_any": "p"}

        for i in range(passes):
            try:
                proc = await asyncio.create_subprocess_exec(
                    *cmd,
                    stdout=asyncio.subprocess.PIPE,
                    stderr=asyncio.subprocess.STDOUT,
                    cwd=str(main_dir),
                    env=compile_env,
                )
            except FileNotFoundError as e:
                raise CompileError(f"Binario {engine} non trovato.") from e

            try:
                stdout, _ = await asyncio.wait_for(proc.communicate(), timeout=timeout)
            except asyncio.TimeoutError:
                proc.kill()
                await proc.wait()
                duration_ms = int((loop.time() - started) * 1000)
                return CompileResult(
                    ok=False, pdf=None,
                    log=f"TIMEOUT dopo {timeout}s alla pass {i+1}/{passes}",
                    duration_ms=duration_ms, engine=engine, passes=i,
                )

            last_returncode = proc.returncode
            last_log = (stdout or b"").decode("utf-8", errors="replace")

            log_file = main_dir / (main_file.stem + ".log")
            if log_file.exists():
                last_log = log_file.read_text(encoding="utf-8", errors="replace")

            if not pdf_path.exists() and i == passes - 1:
                duration_ms = int((loop.time() - started) * 1000)
                return CompileResult(
                    ok=False, pdf=None,
                    log=_truncate_log(last_log),
                    duration_ms=duration_ms, engine=engine, passes=i + 1,
                )

        if not pdf_path.exists():
            duration_ms = int((loop.time() - started) * 1000)
            return CompileResult(
                ok=False, pdf=None,
                log="Nessun PDF prodotto.\n" + _truncate_log(last_log),
                duration_ms=duration_ms, engine=engine, passes=passes,
            )

        pdf_bytes = pdf_path.read_bytes()
        duration_ms = int((loop.time() - started) * 1000)

        synctex_bytes: bytes | None = None
        aux_text = ""
        fls_text = ""
        if with_artifacts:
            synctex_path = main_dir / (main_file.stem + ".synctex.gz")
            if synctex_path.exists():
                synctex_bytes = synctex_path.read_bytes()
            aux_path = main_dir / (main_file.stem + ".aux")
            if aux_path.exists():
                aux_text = aux_path.read_text(encoding="utf-8", errors="replace")
            fls_path = main_dir / (main_file.stem + ".fls")
            if fls_path.exists():
                fls_text = fls_path.read_text(encoding="utf-8", errors="replace")

        logger.info(
            "compile_bundle ok engine=%s passes=%d duration_ms=%d files=%d "
            "total_bytes=%d pdf_bytes=%d returncode=%d main=%s",
            engine, passes, duration_ms, len(files), total_bytes,
            len(pdf_bytes), last_returncode, main_path,
        )
        return CompileResult(
            ok=True, pdf=pdf_bytes,
            log=_truncate_log(last_log) if not with_artifacts else last_log,
            duration_ms=duration_ms, engine=engine, passes=passes,
            synctex_gz=synctex_bytes, aux=aux_text, fls=fls_text,
            formatted_files=formatted_files,
        )
