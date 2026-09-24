"""I modelli TikZ versionati compilano senza errori (24/9/2026, ADR-050).

Ogni modello di `storage/templates/tikz/modelli.json` si compila come lo
compila la produzione per l'anteprima: il font che inietta
`TikzRenderService::normalize` prima di `\\begin{document}`, poi
`\\documentclass[tikz,border=2pt]{standalone}` davanti come fa
`build_standalone_tex` del servizio TeX. pdflatex gira con `-halt-on-error`,
e un modello passa solo se l'esito è 0 **e** nel log non c'è nessuna riga
`! `: il servizio in nonstopmode produce un PDF anche con errori, e un
disegno a metà si scambia per quello buono.

Il risultato grafico si misura con le **dimensioni** della figura (la pagina
di `standalone`, che è anche `width`/`height` dell'SVG servito): una curva che
esce dal suo pannello, un'etichetta finita lontano, un pannello che sparisce
le cambiano. Le misure attese stanno in `tests/fixtures/modelli-tikz-misure.json`,
le legge anche la spec `editor/modelli-tikz-compilano.spec.js`. Dopo aver
cambiato un modello **e guardato le immagini**, si rigenerano con
`PANTEDU_MISURE_AGGIORNA=1`.

Controprova dentro la prova: un modello con una chiave TikZ sbagliata deve
fallire, altrimenti la prova non misura niente.

Senza pdflatex (in CI il TeX non c'è) la parte che compila si salta
DICENDOLO. Con `PANTEDU_ANTEPRIME=<cartella>` salva un PNG per modello
(serve pdftoppm), per guardarli.

Uso, dalla radice del repository:
    python3 -B -m unittest discover -s tests/tex -v
"""
from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

RADICE = Path(__file__).resolve().parents[2]
CARTELLA = RADICE / "storage" / "templates" / "tikz"
MISURE = RADICE / "tests" / "fixtures" / "modelli-tikz-misure.json"
# Mezzo punto: le misure vengono dalla stessa TeX Live, e una figura cambiata
# davvero si sposta di molto di più.
TOLLERANZA_PT = 0.5

FONT = (
    "\\usepackage[scaled]{helvet}\n"
    "\\usepackage[T1]{fontenc}\n"
    "\\renewcommand{\\familydefault}{\\sfdefault}\n"
)


def documento(sorgente: str) -> str:
    """Il documento che compila la produzione, per un modello con preambolo
    e `\\begin{document}` (tutti quelli TikZ della biblioteca)."""
    s = re.sub(r"(\\begin\s*\{\s*document\s*\})", lambda m: FONT + m.group(1), sorgente.strip(), count=1)
    return "\\documentclass[tikz,border=2pt]{standalone}\n" + s + "\n"


def compila(sorgente: str, cartella: Path) -> tuple[int, list[str]]:
    (cartella / "doc.tex").write_text(documento(sorgente), encoding="utf-8")
    esito = subprocess.run(
        ["pdflatex", "-no-shell-escape", "-interaction=nonstopmode", "-halt-on-error", "doc.tex"],
        cwd=cartella, capture_output=True, timeout=120, check=False,
    )
    log = (cartella / "doc.log").read_text(encoding="utf-8", errors="replace") if (cartella / "doc.log").exists() else ""
    return esito.returncode, [r for r in log.splitlines() if r.startswith("! ")]


def modelli_tikz() -> list[dict]:
    """Una voce per file: lo stesso modello può stare in più gruppi."""
    dati = json.loads((CARTELLA / "modelli.json").read_text(encoding="utf-8"))
    visti: dict[str, dict] = {}
    for m in dati["modelli"]:
        if m["tipo"] == "tikz":
            visti.setdefault(m["file"], m)
    return list(visti.values())


def dimensioni_pdf(pdf: Path) -> tuple[float, float]:
    uscita = subprocess.run(["pdfinfo", str(pdf)], capture_output=True, text=True, check=True).stdout
    m = re.search(r"Page size:\s+([\d.]+) x ([\d.]+) pts", uscita)
    assert m is not None, uscita
    return float(m.group(1)), float(m.group(2))


@unittest.skipIf(os.environ.get("PANTEDU_MISURE_AGGIORNA") == "1", "si stanno rigenerando le misure: le dichiarate si guardano al giro dopo")
class MisureDichiarate(unittest.TestCase):
    def test_ogni_modello_ha_le_sue_misure_attese(self):
        # Senza TeX: un modello nuovo senza misure la spec lo salterebbe.
        misure = json.loads(MISURE.read_text(encoding="utf-8"))
        file = {m["file"] for m in modelli_tikz()}
        self.assertEqual(sorted(file - set(misure)), [], "modelli senza misure attese")
        self.assertEqual(sorted(set(misure) - file), [], "misure di modelli che non ci sono più")


HA_PDFLATEX = shutil.which("pdflatex") is not None and shutil.which("pdfinfo") is not None


@unittest.skipUnless(HA_PDFLATEX, "pdflatex o pdfinfo assenti: i modelli TikZ NON sono stati compilati qui")
class ModelliCompilano(unittest.TestCase):
    def test_ogni_modello_compila_senza_errori_e_con_le_sue_misure(self):
        anteprime = os.environ.get("PANTEDU_ANTEPRIME")
        aggiorna = os.environ.get("PANTEDU_MISURE_AGGIORNA") == "1"
        attese = json.loads(MISURE.read_text(encoding="utf-8")) if MISURE.exists() else {}
        nuove: dict[str, list[float]] = {}
        modelli = modelli_tikz()
        self.assertGreater(len(modelli), 0)
        for m in modelli:
            with self.subTest(modello=m["file"]), tempfile.TemporaryDirectory() as t:
                sorgente = (CARTELLA / m["file"]).read_text(encoding="utf-8")
                esito, errori = compila(sorgente, Path(t))
                self.assertEqual(errori, [], f"{m['file']}: errori TeX")
                self.assertEqual(esito, 0, f"{m['file']}: pdflatex è uscito {esito}")
                larghezza, altezza = dimensioni_pdf(Path(t) / "doc.pdf")
                nuove[m["file"]] = [round(larghezza, 3), round(altezza, 3)]
                if not aggiorna:
                    self.assertIn(m["file"], attese, "misure attese mancanti: PANTEDU_MISURE_AGGIORNA=1 dopo aver guardato l'immagine")
                    lw, lh = attese[m["file"]]
                    self.assertAlmostEqual(larghezza, lw, delta=TOLLERANZA_PT, msg=f"{m['file']}: larghezza")
                    self.assertAlmostEqual(altezza, lh, delta=TOLLERANZA_PT, msg=f"{m['file']}: altezza")
                if anteprime and shutil.which("pdftoppm"):
                    Path(anteprime).mkdir(parents=True, exist_ok=True)
                    nome = Path(m["file"]).with_suffix("").as_posix().replace("/", "__")
                    subprocess.run(
                        ["pdftoppm", "-png", "-r", "110", "-singlefile", str(Path(t) / "doc.pdf"), str(Path(anteprime) / nome)],
                        check=True,
                    )
        if aggiorna:
            MISURE.write_text(json.dumps(dict(sorted(nuove.items())), indent=4, ensure_ascii=False) + "\n", encoding="utf-8")

    def test_controprova_un_modello_rotto_fallisce(self):
        sorgente = (CARTELLA / modelli_tikz()[0]["file"]).read_text(encoding="utf-8")
        rotto = sorgente.replace("\\begin{tikzpicture}", "\\begin{tikzpicture}\n\\draw[chiave inesistente] (0,0) -- (1,0);", 1)
        self.assertNotEqual(rotto, sorgente)
        with tempfile.TemporaryDirectory() as t:
            esito, errori = compila(rotto, Path(t))
        self.assertNotEqual(esito, 0)
        self.assertTrue(any("chiave inesistente" in e for e in errori), errori)


@unittest.skipUnless(HA_PDFLATEX and shutil.which("pdftotext"), "pdflatex o pdftotext assenti: il vettore nullo NON è stato provato qui")
class CinematicaVettoreNullo(unittest.TestCase):
    """Un vettore con componente 0 non ha freccia, ma la sua etichetta resta
    (segnalazione dell'utente, 24/9/2026: con `B/0///$\\vec{v}$//1`
    l'etichetta spariva). Si misura dal testo del PDF, nei due versi."""

    def _testo(self, sorgente: str) -> str:
        with tempfile.TemporaryDirectory() as t:
            esito, errori = compila(sorgente, Path(t))
            self.assertEqual((esito, errori), (0, []))
            return subprocess.run(["pdftotext", str(Path(t) / "doc.pdf"), "-"],
                                  capture_output=True, text=True, check=True).stdout

    def test_l_etichetta_di_un_vettore_nullo_resta(self):
        sorgente = (CARTELLA / "fisica" / "cinematica-1d.tex").read_text(encoding="utf-8")
        self.assertIn("A/0///{}//1", sorgente, "la riga d'esempio con il vettore nullo è cambiata")
        con = sorgente.replace("A/0///{}//1", "A/0///ETICHETTANULLA//1", 1)
        self.assertIn("ETICHETTANULLA", self._testo(con))
        # E senza etichetta non compare niente al suo posto.
        self.assertNotIn("ETICHETTANULLA", self._testo(sorgente))


if __name__ == "__main__":
    unittest.main()
