#!/usr/bin/env python3
"""
I font che vede il browser della suite end-to-end: un insieme dichiarato, non
quello che capita di esserci sulla macchina.

Perché esiste
-------------
Il confronto a pixel (`tests/e2e/qualita/regressione-visiva.spec.js`) confronta
le pagine con immagini generate sul runner l'8 settembre 2026. I runner di casa
girano **nella stessa istanza WSL in cui si sviluppa**. Il 12 settembre alle
20:32 ora italiana (18:32 UTC: `dpkg.log` scrive nell'ora locale), installando
TeX Live per lo sviluppo, sono entrati 48 pacchetti di font e
19 regole di sostituzione in `/etc/fonts/conf.d`: `Helvetica` è diventato
`Nimbus Sans`, `Segoe UI` è diventato `Cantarell`, `Arial` è diventato `Arimo`.
I due giri successivi (18:44 e 20:32 UTC): 31 rossi ciascuno, tutti nel
confronto a pixel, con differenze dal 4 al 20% dell'immagine; nessun giro
precedente ne aveva. L'applicazione non era cambiata di un byte.

Il browser non deve dipendere da che cosa si installa per lavorare. Quindi vede
soltanto i font dei pacchetti elencati qui sotto, e soltanto le regole di
fontconfig che appartengono a quei pacchetti o a `fontconfig-config`.

Perché così e non per cartella
------------------------------
Una cartella non basta: in `/usr/share/fonts/truetype/noto/` c'è il font delle
emoji di `fonts-noto-color-emoji` (che c'era) e 271 file di `fonts-noto-core` e
`fonts-noto-mono` (arrivati il 12). Si sceglie file per file, chiedendo a
`dpkg` quali file installa ciascun pacchetto.

E niente elenchi scritti a mano: i file e le regole si ricavano dai pacchetti al
momento. Verificato il 12 settembre: le regole scelte così sono **esattamente**
le 35 che c'erano prima del 12, nessuna in più e nessuna in meno; i font sono 33
file (i pacchetti ne installano 47, ma 14 sono collegamenti ai font variabili di
fonts-ubuntu). Con questa configurazione `Helvetica` e `Arial` tornano
`Liberation Sans`, `sans-serif` e `Segoe UI` tornano `DejaVu Sans`.

La configurazione si verifica prima di usarla, nei quattro versi provati: quella
generata passa; una che fontconfig non riesce a leggere viene presa (fontconfig
ripiegherebbe in silenzio sui font di sistema: 876 file in più); un solo font in
più viene preso; un pacchetto dichiarato che manca blocca tutto.

Come si usa
-----------
    genera-fonts-conf.py <file di uscita> <cartella della cache>
    genera-fonts-conf.py --pacchetti      # stampa l'elenco, per apt-get

Poi `FONTCONFIG_FILE=<file di uscita>` nell'ambiente del browser.

Esce con errore se un pacchetto dichiarato non è installato: un browser che vede
font diversi da quelli delle immagini attese produce rossi che sembrano difetti
dell'applicazione, ed è meglio fermarsi prima dicendo perché.
"""

import os
import re
import subprocess
import sys
import xml.etree.ElementTree as ET

# L'insieme che c'era quando le immagini attese sono state generate (8/9/2026):
# i tre dell'immagine di base di Ubuntu e i tre installati preparando i runner.
# Se si cambia questo elenco, le immagini attese vanno rigenerate.
PACCHETTI_FONT = [
    "fonts-dejavu-core",
    "fonts-dejavu-mono",
    "fonts-ubuntu",
    "fonts-liberation",
    "fonts-liberation-sans-narrow",
    "fonts-noto-color-emoji",
]
PACCHETTI_REGOLE = ["fontconfig-config"] + PACCHETTI_FONT

ESTENSIONI = (".ttf", ".otf", ".ttc", ".pfb", ".pfa", ".woff", ".woff2")
FONTS_CONF_SISTEMA = "/etc/fonts/fonts.conf"
CONF_D = "/etc/fonts/conf.d"
RADICE_FONT = "/usr/share/fonts"


def esegui(argomenti):
    r = subprocess.run(argomenti, capture_output=True, text=True, check=False)
    return r.returncode, r.stdout


def installato(pacchetto):
    rc, out = esegui(["dpkg-query", "-W", "-f=${db:Status-Status}", pacchetto])
    return rc == 0 and out.strip() == "installed"


def file_di_font(pacchetto):
    rc, out = esegui(["dpkg", "-L", pacchetto])
    if rc != 0:
        return []
    # Senza i collegamenti simbolici: fonts-ubuntu ne installa 14 con i nomi
    # vecchi (`Ubuntu-B.ttf -> Ubuntu[wdth,wght].ttf`), e fontconfig conta il
    # file una volta sola, col nome vero. Contandoli, la verifica segnava 14
    # «mancanti» che il browser in realtà vedeva.
    return [
        riga for riga in out.splitlines()
        if riga.lower().endswith(ESTENSIONI) and os.path.isfile(riga) and not os.path.islink(riga)
    ]


def proprietari(percorsi):
    """percorso -> pacchetto, con una sola chiamata a dpkg-query."""
    if not percorsi:
        return {}
    _, out = esegui(["dpkg-query", "-S"] + percorsi)
    mappa = {}
    for riga in out.splitlines():
        if riga.startswith("diversion") or ": " not in riga:
            continue
        pacchetti, percorso = riga.split(": ", 1)
        # «a, b: /percorso» se lo possiedono in due; «pacchetto:arch» sui multiarch
        mappa[percorso] = [p.strip().split(":")[0] for p in pacchetti.split(",")]
    return mappa


def regole_dichiarate():
    # Solo i file che fontconfig stesso tratta come regole: quando include una
    # cartella carica quelli che cominciano con una cifra e finiscono in
    # `.conf`. La prima stesura prendeva tutto ciò che apparteneva ai pacchetti
    # dichiarati, e dentro c'era anche `conf.d/README`, di fontconfig-config:
    # fontconfig non riusciva a leggerlo, **scartava l'intera configurazione e
    # tornava a quella di sistema senza fermarsi** — 909 file invece di 33,
    # `Helvetica` di nuovo `Nimbus Sans`. Da qui la verifica in fondo.
    nomi = sorted(n for n in os.listdir(CONF_D) if re.match(r"^[0-9].*\.conf$", n))
    bersagli = {n: os.path.realpath(os.path.join(CONF_D, n)) for n in nomi}
    di_chi = proprietari(sorted(set(bersagli.values())))
    return [
        n for n in nomi
        if any(p in PACCHETTI_REGOLE for p in di_chi.get(bersagli[n], []))
    ]


def main():
    if len(sys.argv) == 2 and sys.argv[1] == "--pacchetti":
        print(" ".join(PACCHETTI_FONT))
        return 0
    if len(sys.argv) != 3:
        print(__doc__.strip().split("Come si usa")[1], file=sys.stderr)
        return 2

    uscita, cache = sys.argv[1], sys.argv[2]

    mancanti = [p for p in PACCHETTI_REGOLE if not installato(p)]
    if mancanti:
        print("pacchetti di font dichiarati ma non installati: " + " ".join(mancanti), file=sys.stderr)
        print("il browser vedrebbe font diversi da quelli delle immagini attese.", file=sys.stderr)
        return 1

    file_ammessi = sorted({f for p in PACCHETTI_FONT for f in file_di_font(p)})
    fuori_radice = [f for f in file_ammessi if not f.startswith(RADICE_FONT + "/")]
    if not file_ammessi or fuori_radice:
        print("elenco dei font inatteso: " + (" ".join(fuori_radice) or "vuoto"), file=sys.stderr)
        return 1

    regole = regole_dichiarate()

    # Come si sceglie, e perché così. Tre strade provate, con fontconfig 2.15,
    # contando i file che fontconfig vede davvero:
    #
    #   - «scarta tutto (`/*`), ammetti i 33»: zero file. Un glob che copre una
    #     cartella scarta anche le cartelle mentre fontconfig le visita, quindi
    #     dentro `/usr/share/fonts` non entra e i file ammessi non vengono mai
    #     raggiunti. Stesso esito con `/usr/share/fonts/*`;
    #   - «ammetti per proprietà» (`<pattern><patelt name="file">`): zero file;
    #   - «scarta *.ttf» o «scarta *»: non scartano niente — i glob che non
    #     cominciano con `/` qui non combaciano.
    #
    # Funziona, verificato, il percorso ESATTO — anche con le parentesi quadre
    # che otto font di fonts-ubuntu hanno nel nome (`Ubuntu[wdth,wght].ttf`).
    # Quindi la strada opposta: si chiede a fontconfig che cosa vedrebbe con le
    # stesse cartelle e le stesse regole ma senza filtri, e si scarta per
    # percorso esatto tutto ciò che non è dei pacchetti dichiarati. La
    # configurazione si rigenera a ogni giro, quindi un font installato domani
    # viene scartato domani.
    base = costruisci(regole, cache, rifiutati=[])
    scrivi(uscita, base)
    tutti, errori, rc = visti(uscita)
    if rc != 0 or errori:
        print("fontconfig non legge la configurazione di base:", file=sys.stderr)
        for riga in errori[:5]:
            print("  " + riga, file=sys.stderr)
        return 1

    rifiutati = sorted(tutti - set(file_ammessi))
    scrivi(uscita, costruisci(regole, cache, rifiutati))

    print(f"{len(file_ammessi)} file ammessi, {len(rifiutati)} scartati, {len(regole)} regole -> {uscita}", file=sys.stderr)
    return verifica(uscita, file_ammessi)


def costruisci(regole, cache, rifiutati):
    # Del fonts.conf di sistema si tiene tutto (le <match> di base, i due
    # <selectfont> che scartano i file temporanei di dpkg, <config>) tranne
    # cartelle, cache e inclusioni, che qui si decidono diversamente.
    sistema = ET.parse(FONTS_CONF_SISTEMA).getroot()
    radice = ET.Element("fontconfig")
    ET.SubElement(radice, "dir").text = RADICE_FONT
    ET.SubElement(radice, "cachedir").text = cache
    for figlio in sistema:
        if figlio.tag not in ("dir", "cachedir", "include"):
            radice.append(figlio)
    for nome in regole:
        ET.SubElement(radice, "include", {"ignore_missing": "no"}).text = os.path.join(CONF_D, nome)
    if rifiutati:
        scarta = ET.SubElement(ET.SubElement(radice, "selectfont"), "rejectfont")
        for f in rifiutati:
            ET.SubElement(scarta, "glob").text = f
    return radice


def scrivi(uscita, radice):
    ET.indent(radice)
    with open(uscita, "w", encoding="utf-8") as fh:
        fh.write('<?xml version="1.0"?>\n<!DOCTYPE fontconfig SYSTEM "urn:fontconfig:fonts.dtd">\n')
        fh.write("<!-- Generato da tools/ci/fontconfig/genera-fonts-conf.py: non modificare a mano. -->\n")
        fh.write(ET.tostring(radice, encoding="unicode"))
        fh.write("\n")


def visti(conf):
    """I file di font che fontconfig vede con questa configurazione."""
    ambiente = dict(os.environ, FONTCONFIG_FILE=conf)
    r = subprocess.run(
        ["fc-list", "--format", "%{file}\n"],
        capture_output=True, text=True, check=False, env=ambiente,
    )
    errori = [riga for riga in r.stderr.splitlines() if "Fontconfig error" in riga]
    return set(r.stdout.split("\n")) - {""}, errori, r.returncode


def verifica(conf, attesi):
    """
    Si chiede a fontconfig, con la configurazione appena scritta, quali file
    vede. Devono essere ESATTAMENTE quelli attesi.

    Non è scrupolo: se fontconfig non riesce a leggere la configurazione passata
    in `FONTCONFIG_FILE` non si ferma, ripiega su quella di sistema. Il browser
    vedrebbe di nuovo tutti i font della macchina, e il giro sarebbe rosso per
    la stessa ragione di prima — con in più la certezza, falsa, di averlo
    risolto.
    """
    trovati, errori, rc = visti(conf)
    atteso = set(attesi)
    in_piu, mancanti = sorted(trovati - atteso), sorted(atteso - trovati)
    if rc != 0 or errori or in_piu or mancanti:
        print("VERIFICA FALLITA: il browser non vedrebbe i font dichiarati.", file=sys.stderr)
        for riga in errori[:5]:
            print("  " + riga, file=sys.stderr)
        print(f"  file in più: {len(in_piu)}   mancanti: {len(mancanti)}", file=sys.stderr)
        for f in (in_piu + mancanti)[:10]:
            print("    " + f, file=sys.stderr)
        return 1
    print(f"verifica: fontconfig vede esattamente i {len(trovati)} file dichiarati", file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())
