"""G22.S16 — latexindent wrapper per formattare .tex prima del compile.

latexindent (Perl, parte di TeX Live ≥ 2013) è il formatter ufficiale
LaTeX: indenta basandosi su grammatica AST, riconosce environments,
conserva commenti, supporta config YAML.

Strategia integration:
  1. compile.py scrive .tex su tmpdir
  2. PRIMA di pdflatex, chiama latexindent_format() su ogni .tex
  3. Se OK → il file su disco è ora formattato; compile usa quello
  4. Il content formattato viene anche restituito al client (formatted_files
     nella response /compile-bundle?with_artifacts=1)

Best-effort: se latexindent non installato o errore → no-op silenzioso,
fallback al contenuto originale. Compilation non fallisce mai per
problemi di formattazione.

Config opzionale: TEX_COMPILE_LATEXINDENT_OFF=1 disabilita il passo
(per debug o se il config cluster è incompatibile).
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

DISABLE_LATEXINDENT = os.environ.get("TEX_COMPILE_LATEXINDENT_OFF", "") == "1"
LATEXINDENT_TIMEOUT = int(os.environ.get("TEX_COMPILE_LATEXINDENT_TIMEOUT", "5"))


async def latexindent_format(tex_path: Path) -> bool:
    """Esegue `latexindent -m -s -l <file>` in place sul .tex.

    -m: modify in place (overwrite il file)
    -s: silent (no log su stdout/stderr)
    -l: usa localSettings.yaml se presente nel cwd, altrimenti default

    Ritorna True se latexindent ha riformattato con successo (file modificato
    o invariato). False se non installato, timeout, o exit code != 0 (in tal
    caso il file resta col contenuto originale, compile prosegue lo stesso).
    """
    if DISABLE_LATEXINDENT:
        return False
    if not tex_path.exists():
        return False
    try:
        proc = await asyncio.create_subprocess_exec(
            "latexindent",
            "-m",
            "-s",
            "-l",
            str(tex_path),
            stdout=asyncio.subprocess.DEVNULL,
            stderr=asyncio.subprocess.DEVNULL,
            cwd=str(tex_path.parent),
        )
    except FileNotFoundError:
        # latexindent non installato: skip silenzioso (best-effort).
        logger.debug("latexindent not found, skipping format step")
        return False
    try:
        await asyncio.wait_for(proc.communicate(), timeout=LATEXINDENT_TIMEOUT)
    except asyncio.TimeoutError:
        proc.kill()
        await proc.wait()
        logger.warning("latexindent timeout su %s", tex_path.name)
        return False
    if proc.returncode != 0:
        logger.warning("latexindent exit %d su %s", proc.returncode, tex_path.name)
        return False
    return True


async def format_all_tex_in_dir(root: Path) -> dict[str, bytes]:
    """Esegue latexindent su tutti i .tex sotto root (ricorsivo).

    Ritorna dict {relative_path: formatted_content_bytes} per i file
    effettivamente riformattati. Path sono relativi a root (forward slashes).

    Ottimizzazione: parallelizza max 4 latexindent concorrenti per non
    saturare CPU/IO su bundle con molti file.
    """
    formatted: dict[str, bytes] = {}
    if DISABLE_LATEXINDENT:
        return formatted

    tex_files = list(root.rglob("*.tex"))
    if not tex_files:
        return formatted

    sem = asyncio.Semaphore(4)

    async def fmt_one(tex_path: Path) -> tuple[str, bytes] | None:
        async with sem:
            ok = await latexindent_format(tex_path)
        if not ok:
            return None
        try:
            content = tex_path.read_bytes()
        except OSError:
            return None
        rel = tex_path.relative_to(root).as_posix()
        return rel, content

    results = await asyncio.gather(*(fmt_one(p) for p in tex_files))
    for r in results:
        if r is not None:
            formatted[r[0]] = r[1]
    return formatted


# ──────────────────── G22.S15 — endpoint string-based ────────────────────

@dataclass
class FormatStringResult:
    ok: bool
    formatted: str | None
    log: str
    duration_ms: int


class FormatError(Exception):
    pass


async def format_source(source: str, *, timeout: int = LATEXINDENT_TIMEOUT) -> FormatStringResult:
    """Formatta una STRINGA via latexindent. Scrive su tmpfile, chiama
    latexindent in-place via thread (asyncio.to_thread per portabilita
    Windows asyncio limits), legge il risultato.
    Per /format-tex endpoint."""
    if not source:
        return FormatStringResult(ok=False, formatted=None, log="empty source", duration_ms=0)
    if len(source) > 1 * 1024 * 1024:
        raise FormatError("source > 1 MB")
    if DISABLE_LATEXINDENT:
        raise FormatError("latexindent disabilitato (TEX_COMPILE_LATEXINDENT_OFF=1)")

    workdir = os.environ.get("TEX_COMPILE_WORKDIR", "/var/tmp/tex-compile")
    Path(workdir).mkdir(parents=True, exist_ok=True)
    loop = asyncio.get_event_loop()
    started = loop.time()

    with tempfile.TemporaryDirectory(dir=workdir, prefix="fmt_") as tmp:
        tmp_path = Path(tmp)
        tex_file = tmp_path / "doc.tex"
        tex_file.write_text(source, encoding="utf-8")

        # Senza `-l` latexindent usa default config (defaultSettings.yaml in
        # /usr/share/perl5 o equivalente) — indenta correttamente. Stdout
        # contiene il sorgente formattato. `-w` farebbe write-in-place ma
        # creerebbe anche un .bak; preferiamo capture stdout.
        cmd = ["latexindent", str(tex_file)]
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
            return FormatStringResult(
                ok=False, formatted=None,
                log=f"latexindent TIMEOUT dopo {timeout}s",
                duration_ms=int((loop.time() - started) * 1000),
            )
        except FileNotFoundError as e:
            raise FormatError(f"latexindent non trovato: {e}")

        duration_ms = int((loop.time() - started) * 1000)
        if result.returncode != 0:
            log = (result.stderr or b"").decode("utf-8", errors="replace") \
                  + "\n" + (result.stdout or b"").decode("utf-8", errors="replace")
            return FormatStringResult(ok=False, formatted=None, log=log[:4000], duration_ms=duration_ms)

        formatted = (result.stdout or b"").decode("utf-8", errors="replace")
        if not formatted.strip():
            return FormatStringResult(
                ok=False, formatted=None,
                log="latexindent ha prodotto stdout vuoto",
                duration_ms=duration_ms,
            )
        logger.info(
            "format ok duration_ms=%d source_bytes=%d formatted_bytes=%d",
            duration_ms, len(source), len(formatted),
        )
        return FormatStringResult(ok=True, formatted=formatted, log="", duration_ms=duration_ms)
