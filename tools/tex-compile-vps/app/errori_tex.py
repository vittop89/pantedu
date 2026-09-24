"""Gli errori di pdflatex, letti dal suo log (24/9/2026).

pdflatex in `nonstopmode` non si ferma al primo errore: lo scrive nel log,
prova a rimediare e spesso produce lo stesso un PDF, con la figura a metà.
Fino al 24/9/2026 `render_tikz` giudicava solo dall'esistenza del PDF: una
chiave TikZ sbagliata o un `\\foreach` rotto davano `ok=True` e un SVG
parziale, e al docente non arrivava nessun messaggio. Quando invece il PDF
non usciva, il log restituito erano i primi e gli ultimi 2000 caratteri: il
caricamento dei pacchetti, e l'errore solo se stava in fondo.

Qui: quali errori ci sono (ogni riga che comincia con `! `, come li scrive
TeX) e un estratto leggibile che comincia da loro. Solo la libreria standard,
per poterlo provare senza installare il servizio (`tests/tex/`).
"""
from __future__ import annotations

import re

# `l.136 ...testo della riga` — la riga del sorgente a cui TeX si è fermato.
_RIGA = re.compile(r"^l\.(\d+)")

# Le righe di servizio fra il messaggio e `l.NNN`: vuote, i puntini con cui
# TeX abbrevia, l'invito a premere H (che in nonstopmode non si può).
_RUMORE = re.compile(r"^\s*(\.\.\.)?\s*$|^Type\s+H <return>|^See the \S+ package documentation")

# La riga con cui pdflatex chiude dopo un errore fatale: non è un errore in
# più, è la conseguenza del primo.
_CHIUSURE = ("==> Fatal error occurred", "Emergency stop")


def trova_errori(log: str, limite: int = 20) -> list[dict]:
    """Gli errori del log, nell'ordine in cui compaiono.

    Ognuno: `message` (la riga dopo `! `), `line` (il numero dopo `l.`, se
    TeX l'ha scritto entro poche righe; è la riga del documento compilato,
    che può avere qualche riga in più del sorgente del docente) e `context`
    (le righe fra il messaggio e `l.NNN`, più quella dopo: TeX spezza la
    riga del sorgente nel punto esatto in cui si è fermato).
    """
    errori: list[dict] = []
    if not log:
        return errori
    righe = log.splitlines()
    for i, riga in enumerate(righe):
        if not riga.startswith("! "):
            continue
        messaggio = riga[2:].strip()
        if messaggio.startswith(_CHIUSURE) and errori:
            continue
        numero = None
        contesto: list[str] = []
        for j in range(i + 1, min(i + 14, len(righe))):
            seguente = righe[j]
            if seguente.startswith("! "):
                break
            trovata = _RIGA.match(seguente)
            if not trovata and _RUMORE.match(seguente):
                continue
            contesto.append(seguente)
            if trovata:
                numero = int(trovata.group(1))
                # TeX spezza la riga nel punto in cui si è fermato: la
                # seconda metà sta nella riga dopo, rientrata.
                if j + 1 < len(righe) and righe[j + 1].strip():
                    contesto.append(righe[j + 1])
                break
        errori.append({
            "line": numero,
            "message": messaggio[:300],
            "context": "\n".join(contesto).strip("\n")[:800],
        })
        if len(errori) >= limite:
            break
    return errori


def estratto(log: str, errori: list[dict], max_chars: int = 4000) -> str:
    """Il testo da mostrare al docente: prima gli errori con il loro
    contesto, poi la coda del log (dove pdflatex dice come è finita)."""
    if not errori:
        return log if len(log) <= max_chars else "[...]\n" + log[-max_chars:]
    n = len(errori)
    parti = [f"pdflatex ha trovato {n} {'errore' if n == 1 else 'errori'}:"]
    for e in errori:
        blocco = "! " + e["message"]
        if e.get("context"):
            blocco += "\n" + e["context"]
        parti.append(blocco)
    testa = "\n\n".join(parti)
    resto = max_chars - len(testa) - 40
    if resto > 200:
        coda = log[-resto:] if len(log) > resto else log
        testa += "\n\n--- fine del log di pdflatex ---\n" + coda
    return testa[: max_chars + 200]
