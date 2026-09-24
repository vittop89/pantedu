"""Gli errori di pdflatex non si perdono più (24/9/2026).

`render_tikz` giudicava solo dall'esistenza del PDF: pdflatex in nonstopmode
rimedia agli errori e il PDF esce quasi sempre, quindi una chiave TikZ
sbagliata dava `ok=True` e una figura a metà, senza messaggio. Da oggi un
errore nel log (`! …`) fa fallire il disegno, con un estratto che comincia
dagli errori (`tools/tex-compile-vps/app/errori_tex.py`).

Due parti:
- `trova_errori` ed `estratto` sui log veri di pdflatex, nei due versi: gli
  errori si trovano, un log pulito non ne ha;
- `render_tikz` vero, se pdflatex e dvisvgm ci sono: un sorgente con errori
  che producono comunque il PDF fallisce, uno pulito passa. In CI il TeX non
  c'è e questa parte si salta DICENDOLO (non è un verde: è un salto).

Solo la libreria standard: il pacchetto del servizio si carica con un nome
suo, `servizio_tex`, perché `app` è anche la cartella del PHP.

Uso, dalla radice del repository:
    python3 -B -m unittest discover -s tests/tex -v
"""
from __future__ import annotations

import asyncio
import importlib
import importlib.util
import shutil
import sys
import tempfile
import unittest
from pathlib import Path

RADICE = Path(__file__).resolve().parents[2]
CARTELLA_APP = RADICE / "tools" / "tex-compile-vps" / "app"
# Gli stessi log li legge la prova del gemello PHP (ErroriTexTest).
LOG = RADICE / "tests" / "fixtures" / "log-pdflatex"


def _carica(modulo: str):
    if "servizio_tex" not in sys.modules:
        spec = importlib.util.spec_from_file_location(
            "servizio_tex",
            CARTELLA_APP / "__init__.py",
            submodule_search_locations=[str(CARTELLA_APP)],
        )
        assert spec is not None and spec.loader is not None
        pacchetto = importlib.util.module_from_spec(spec)
        sys.modules["servizio_tex"] = pacchetto
        spec.loader.exec_module(pacchetto)
    return importlib.import_module(f"servizio_tex.{modulo}")


errori_tex = _carica("errori_tex")

# Il log di pdflatex 3.141592653-2.6-1.40.25 su un disegno con una chiave
# inesistente e un comando non definito: il PDF esce lo stesso (esito 1).
LOG_DUE_ERRORI = (LOG / "due-errori.log").read_text(encoding="utf-8")

# Il modello «grafico di funzione (axis)» come stava in produzione il
# 24/9/2026: un errore fatale dentro un \foreach.
LOG_FATALE = (LOG / "fatale.log").read_text(encoding="utf-8")

# Un log pulito, con gli avvisi che ci sono sempre: non sono errori.
LOG_PULITO = (LOG / "pulito.log").read_text(encoding="utf-8")


class TrovaErrori(unittest.TestCase):
    def test_trova_i_due_errori_anche_se_il_pdf_esce(self):
        errori = errori_tex.trova_errori(LOG_DUE_ERRORI)
        self.assertEqual(len(errori), 2)
        self.assertTrue(errori[0]["message"].startswith("Package pgfkeys Error: I do not know the key '/tikz/kin inesistente'"))
        self.assertEqual(errori[0]["line"], 4)
        self.assertIn("(0,0) -- (1,0);", errori[0]["context"], "con la riga del sorgente dove TeX si è fermato")
        self.assertNotIn("Type  H <return>", errori[0]["context"], "senza le righe di servizio")
        self.assertEqual(errori[1]["message"], "Undefined control sequence.")
        self.assertEqual(errori[1]["line"], 5)
        self.assertIn(r"\comandoinesistente", errori[1]["context"])

    def test_la_chiusura_fatale_non_conta_come_errore_in_piu(self):
        errori = errori_tex.trova_errori(LOG_FATALE)
        self.assertEqual([e["message"] for e in errori], [r"Paragraph ended before \pgffor@normal@list was complete."])
        self.assertEqual(errori[0]["line"], 136)

    def test_un_log_pulito_non_ha_errori(self):
        # Avvisi, «Missing character», Overfull: pdflatex li scrive sempre e
        # non sono errori. Se questa prova fallisce, ogni figura fallirebbe.
        self.assertEqual(errori_tex.trova_errori(LOG_PULITO), [])
        self.assertEqual(errori_tex.trova_errori(""), [])

    def test_l_estratto_comincia_dagli_errori(self):
        errori = errori_tex.trova_errori(LOG_DUE_ERRORI)
        testo = errori_tex.estratto(LOG_DUE_ERRORI, errori)
        self.assertTrue(testo.startswith("pdflatex ha trovato 2 errori:"), testo[:80])
        self.assertLess(testo.index("kin inesistente"), testo.index("Output written"))
        self.assertIn("l.5 \\node at (0,1)", testo)

    def test_l_estratto_di_un_log_lungo_tiene_l_errore(self):
        # Il vecchio troncamento (primi e ultimi 2000 caratteri) perdeva
        # l'errore quando stava in mezzo a un log lungo.
        lungo = ("Package loading line\n" * 2000) + LOG_DUE_ERRORI + ("dopo\n" * 2000)
        errori = errori_tex.trova_errori(lungo)
        testo = errori_tex.estratto(lungo, errori, max_chars=4000)
        self.assertIn("kin inesistente", testo)
        self.assertLessEqual(len(testo), 4200)


SORGENTE_CON_ERRORI = r"""\begin{document}
\begin{tikzpicture}
\draw[kin inesistente] (0,0) -- (1,0);
\draw (0,0) -- (2,2);
\end{tikzpicture}
\end{document}
"""

SORGENTE_PULITO = r"""\begin{document}
\begin{tikzpicture}
\draw[->] (0,0) -- (2,2);
\end{tikzpicture}
\end{document}
"""

HA_IL_TEX = bool(shutil.which("pdflatex") and shutil.which("dvisvgm"))


@unittest.skipUnless(HA_IL_TEX, "pdflatex o dvisvgm assenti: render_tikz vero NON provato qui")
class RenderTikzVero(unittest.TestCase):
    def setUp(self):
        self.tikz_render = _carica("tikz_render")
        self.cartella = tempfile.TemporaryDirectory()
        self.tikz_render.WORKDIR = self.cartella.name

    def tearDown(self):
        self.cartella.cleanup()

    def test_con_errori_fallisce_anche_se_il_pdf_esce(self):
        esito = asyncio.run(self.tikz_render.render_tikz(SORGENTE_CON_ERRORI))
        self.assertFalse(esito.ok, "prima del 24/9/2026 qui c'era ok=True e un SVG a metà")
        self.assertIsNone(esito.svg)
        self.assertEqual(len(esito.errors), 1)
        self.assertIn("kin inesistente", esito.errors[0]["message"])
        self.assertTrue(esito.log.startswith("pdflatex ha trovato 1 errore:"), esito.log[:80])

    def test_pulito_passa(self):
        esito = asyncio.run(self.tikz_render.render_tikz(SORGENTE_PULITO))
        self.assertTrue(esito.ok, esito.log[:500])
        self.assertEqual(esito.errors, [])
        self.assertIn(b"<svg", esito.svg or b"")


if __name__ == "__main__":
    unittest.main()
