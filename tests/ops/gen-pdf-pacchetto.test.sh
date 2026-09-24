#!/bin/bash
#
# Prove di `docs/dpo/pacchetto-scuola/_gen_pdf.py`, nei due versi (22/9/2026).
#
# Il difetto che l'ha fatta nascere: lo script cancellava il PDF versionato e
# solo dopo lanciava Edge, senza guardare se Edge c'era. Lanciato con il Python
# di WSL e il modulo `markdown` installato, Edge (un percorso C:\...) non
# esiste: subprocess sollevava FileNotFoundError, e il PDF era già sparito.
#
# Qui niente Windows e niente Edge vero: un modulo `markdown` finto in
# PYTHONPATH (viene prima di un eventuale modulo vero), un Edge finto che fa
# quello che gli dice EDGE_FINTO, e un PDF «versionato» finto con contenuto
# noto. Dopo ogni fallimento la sua impronta deve essere la stessa, e nella
# cartella del PDF e in quella temporanea non deve restare niente. Nel verso
# buono il PDF deve cambiare: altrimenti il confronto delle impronte potrebbe
# non vedere niente.
#
# WSL_DISTRO_NAME non conta: lo script non la legge. Edge lo sceglie la prova
# in una COPIA dello script, con la riga `EDGE = ...` sostituita: lo script
# non prende dall'ambiente il programma da lanciare (semgrep lo segnala,
# dangerous-subprocess-use-tainted-env-args). Se la riga non c'è, la prova si
# ferma: altrimenti proverebbe l'Edge predefinito credendo di provare il finto.
#
# Ogni controllo dello script ha un caso che lo mette alla prova DA SOLO: un
# PDF completo ma con Edge in errore (l'esito di Edge), una pagina HTML che
# finisce con %%EOF (il %PDF in testa), un PDF troncato (il %%EOF in coda), un
# PDF che cresce ancora quando Edge è tornato (l'attesa di stabilità), un file
# creato vuoto e riempito dopo (il «non vuoto» dell'attesa). Con un
# Edge finto che sbaglia in più modi insieme, un controllo tolto passerebbe
# inosservato: l'ha misurato la verifica del 23/9/2026.
#
# Controprova: GEN_PDF_SCRIPT punta a un altro _gen_pdf.py, per esempio quello
# di prima della correzione (08b363f1, main prima della #232). Anche lì la riga
# EDGE si sostituisce, quindi fallisce solo dove il difetto c'era:
#   git show 08b363f1:docs/dpo/pacchetto-scuola/_gen_pdf.py > /tmp/vecchio.py
#   GEN_PDF_SCRIPT=/tmp/vecchio.py bash tests/ops/gen-pdf-pacchetto.test.sh
# Deve fallire, fra l'altro, con «Edge assente: il PDF Prova.pdf è sparito».
#
# Uso: bash tests/ops/gen-pdf-pacchetto.test.sh
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
RADICE=$(cd "$QUI/../.." && pwd)
SCRIPT="${GEN_PDF_SCRIPT:-$QUI/../../docs/dpo/pacchetto-scuola/_gen_pdf.py}"
# Se mktemp fallisce T resta vuota, e `rm -rf "$T/tmp"` diventerebbe
# `rm -rf /tmp`: ci si ferma.
T=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/gen-pdf.XXXXXX") \
    || { echo "gen-pdf-pacchetto: mktemp non riuscito, niente da provare"; exit 1; }
[ -n "$T" ] && [ -d "$T" ] || { echo "gen-pdf-pacchetto: cartella temporanea mancante"; exit 1; }
trap 'rm -rf "${T:?}"' EXIT

PASSATE=0
FALLITE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

# Senza python3 non si è provato niente, e non è un successo.
if ! command -v python3 > /dev/null 2>&1; then
    ko "python3 assente: il generatore non è stato provato"
    echo "gen-pdf-pacchetto: $PASSATE passate, $FALLITE fallite"
    exit 1
fi

# Il modulo `markdown` finto: quello vero non serve a ciò che si prova qui.
mkdir -p "$T/pymod" "$T/pymod-assente"
# E un modulo che manca, come nel Python di WSL, qualunque cosa sia installata
# sulla macchina della prova.
echo 'raise ImportError("markdown assente (finto)")' > "$T/pymod-assente/markdown.py"
cat > "$T/pymod/markdown.py" <<'PY'
import html
def markdown(text, extensions=None):
    return "<p>" + html.escape(text) + "</p>"
PY

# L'Edge finto. Registra l'HTML che riceve (l'ultimo argomento, un URL file://)
# e il percorso di --print-to-pdf, e scrive lì secondo EDGE_FINTO. Un PDF
# finito comincia con %PDF e finisce con %%EOF, come quelli dell'Edge vero. Un
# file di uscita con «Rotto» nel nome fallisce sempre: serve alla prova con più
# file.
cat > "$T/edge-finto" <<'EDGE'
#!/bin/bash
out=""
for a in "$@"; do
    case "$a" in --print-to-pdf=*) out="${a#--print-to-pdf=}" ;; esac
done
html=$(python3 -c 'import sys, urllib.parse; print(urllib.parse.unquote(urllib.parse.urlparse(sys.argv[1]).path))' "${!#}")
printf '%s\n' "$html" >> "$REGISTRO_EDGE"
printf '%s\n' "$out" >> "$REGISTRO_EDGE.uscita"
# Come l'Edge vero: se l'HTML non c'è più quando lo legge, stampa la propria
# pagina d'errore, che è un PDF valido (il difetto del 4/9/2026).
stampa() { if [ -f "$html" ]; then echo "%PDF-1.4 nuovo"; cat "$html"; else echo "%PDF-1.4 pagina d'errore di Edge"; fi; echo "%%EOF"; }
case "$out" in *Rotto*) echo "mezzo PDF" > "$out"; exit 3 ;; esac
case "${EDGE_FINTO:-buono}" in
    # Un PDF completo, ma Edge esce con errore: lo deve fermare l'esito.
    errore) stampa > "$out"; exit 1 ;;
    niente) exit 0 ;;
    # Finisce con %%EOF ma non comincia con %PDF: lo deve fermare il %PDF.
    spazzatura) { echo "<html>pagina d'errore</html>"; echo "%%EOF"; } > "$out"; exit 0 ;;
    buono) stampa > "$out"; exit 0 ;;
    # --headless=new torna prima che il figlio abbia letto l'HTML e scritto il
    # PDF: il generatore deve aspettare, e togliere l'HTML solo dopo.
    tardivo) ( sleep 1; stampa > "$out" ) > /dev/null 2>&1 & exit 0 ;;
    # Scrive l'inizio, si ferma più di mezzo secondo e poi finisce: un PDF
    # «che ha smesso di crescere» ma è troncato.
    lento) echo "%PDF-1.4 inizio" > "$out"
           ( sleep 1.6; { echo "resto"; echo "%%EOF"; } >> "$out" ) > /dev/null 2>&1 & exit 0 ;;
    # Torna quando il PDF c'è ma sta ancora crescendo: il generatore deve
    # aspettare che smetta, e allora il PDF è buono.
    # Crea il file vuoto e lo riempie dopo: un file vuoto che «non cresce» non
    # è un PDF finito, si aspetta ancora.
    vuoto) : > "$out"
           ( sleep 1; stampa > "$out" ) > /dev/null 2>&1 & exit 0 ;;
    cresce) echo "%PDF-1.4 nuovo" > "$out"
            ( sleep 0.1; { echo "resto"; echo "%%EOF"; } >> "$out" ) > /dev/null 2>&1 & exit 0 ;;
esac
exit 5
EDGE
chmod +x "$T/edge-finto"

# prepara NOME...: una cartella pulita con NOME.md e il suo PDF «versionato».
# In IMPRONTA_<NOME> l'impronta del PDF di partenza.
prepara() {
    rm -rf "${T:?}/doc" "${T:?}/tmp"
    mkdir -p "$T/doc" "$T/tmp"
    : > "$T/edge.log"
    : > "$T/edge.log.uscita"
    local n
    for n in "$@"; do
        printf -- '---\ntitle: "Titolo di %s"\n---\n\nTesto di %s.\n' "$n" "$n" > "$T/doc/$n.md"
        printf '%%PDF-1.4 versionato %s\n' "$n" > "$T/doc/$n.pdf"
        printf -v "IMPRONTA_$n" '%s' "$(sha256sum < "$T/doc/$n.pdf")"
    done
    ATTESI=$(cd "$T/doc" && ls -A | sort | tr '\n' ' ')
}

# genera EDGE MODO FILE...: il generatore sui file, con l'Edge e il modo dati
# (EDGE vuoto = lo script così com'è, con l'Edge predefinito). Esito in ESITO.
genera() {
    local edge=$1 modo=$2 script=$SCRIPT
    shift 2
    if [ -n "$edge" ]; then
        script="$T/gen_pdf_prova.py"
        sed "s|^EDGE = .*|EDGE = r'$edge'|" "$SCRIPT" > "$script"
        if [ "$(grep -c "^EDGE = r'$edge'\$" "$script")" != 1 ]; then
            ko "nella copia dello script la riga EDGE non è stata sostituita: la prova non prova niente"
            echo "gen-pdf-pacchetto: $PASSATE passate, $FALLITE fallite"
            exit 1
        fi
    fi
    ( cd "$T/doc" && env PYTHONPATH="${PYMOD:-$T/pymod}" PYTHONDONTWRITEBYTECODE=1 TMPDIR="$T/tmp" \
        GEN_PDF_ATTESA="${ATTESA:-2}" EDGE_FINTO="$modo" REGISTRO_EDGE="$T/edge.log" \
        python3 "$script" "$@" ) > "$T/out" 2> "$T/err"
    ESITO=$?
}

# errore: l'ultima riga dello standard error, per i messaggi (di un traceback
# basta l'eccezione).
errore() { tail -1 "$T/err"; }

# intatto CASO NOME: il PDF di NOME c'è ed è quello di partenza.
intatto() {
    local atteso="IMPRONTA_$2"
    if [ -f "$T/doc/$2.pdf" ] && [ "$(sha256sum < "$T/doc/$2.pdf")" = "${!atteso}" ]; then
        ok
    elif [ -f "$T/doc/$2.pdf" ]; then
        ko "$1: il PDF $2.pdf è cambiato: «$(head -c 60 "$T/doc/$2.pdf")»"
    else
        ko "$1: il PDF $2.pdf è sparito"
    fi
}

# pulita CASO: nella cartella del PDF solo i file di partenza, e la cartella
# temporanea (HTML, profilo di Edge) vuota.
pulita() {
    local resto tmp
    resto=$(cd "$T/doc" && ls -A | sort | tr '\n' ' ')
    [ "$resto" = "$ATTESI" ] && ok || ko "$1: nella cartella del PDF c'è «$resto», attesi «$ATTESI»"
    tmp=$(ls -A "$T/tmp")
    [ -z "$tmp" ] && ok || ko "$1: nella cartella temporanea è rimasto «$(printf '%s' "$tmp" | tr '\n' ' ')»"
}

# ── a) Edge assente: ci si ferma senza toccare niente ─────────────────────
prepara Prova
genera "$T/non-esiste/msedge.exe" buono Prova.md
# 3 e non un esito qualunque: 1 è «un file non rigenerato», 2 «uso sbagliato».
[ "$ESITO" -eq 3 ] && ok || ko "Edge assente: esito $ESITO, atteso 3"
intatto "Edge assente" Prova
pulita "Edge assente"
[ ! -s "$T/edge.log" ] && ok || ko "Edge assente: qualcosa è stato lanciato lo stesso"
# e) Il messaggio dice dove guardare.
grep -q 'docs/dev/sviluppo-in-wsl.md' "$T/err" \
    && ok || ko "Edge assente: il messaggio non nomina la guida: «$(errore)»"
grep -q 'Edge non trovato' "$T/err" \
    && ok || ko "Edge assente: il messaggio non dice che manca Edge: «$(errore)»"

# Il caso vero del difetto: il Python di Linux con il percorso predefinito,
# C:\..., che qui non esiste.
prepara Prova
genera "" buono Prova.md
[ "$ESITO" -ne 0 ] && ok || ko "Edge predefinito in Linux: esito 0"
intatto "Edge predefinito in Linux" Prova
pulita "Edge predefinito in Linux"

# Il caso vero di WSL: Edge assente E modulo `markdown` assente. Il messaggio
# dev'essere il rimando alla guida, non un traceback su `import markdown`.
prepara Prova
PYMOD="$T/pymod-assente" genera "" buono Prova.md
[ "$ESITO" -eq 3 ] && ok || ko "senza Edge e senza markdown: esito $ESITO, atteso 3 ($(errore))"
grep -q 'docs/dev/sviluppo-in-wsl.md' "$T/err" && ! grep -q 'Traceback' "$T/err" \
    && ok || ko "senza Edge e senza markdown: «$(errore)»"
intatto "senza Edge e senza markdown" Prova

# ── b) Edge esce con errore, dopo aver scritto mezzo file ─────────────────
prepara Prova
genera "$T/edge-finto" errore Prova.md
[ "$ESITO" -ne 0 ] && ok || ko "Edge in errore: esito 0"
# Edge dev'essere stato lanciato davvero: se no questo caso non prova niente.
[ -s "$T/edge.log" ] && ok || ko "Edge in errore: Edge non è stato lanciato ($(errore))"
intatto "Edge in errore" Prova
pulita "Edge in errore"

# ── c) Edge esce con 0 ma non scrive niente, o scrive qualcosa che non è un PDF
prepara Prova
genera "$T/edge-finto" niente Prova.md
[ "$ESITO" -ne 0 ] && ok || ko "Edge muto: esito 0"
[ -s "$T/edge.log" ] && ok || ko "Edge muto: Edge non è stato lanciato ($(errore))"
intatto "Edge muto" Prova
pulita "Edge muto"

prepara Prova
genera "$T/edge-finto" spazzatura Prova.md
[ "$ESITO" -ne 0 ] && ok || ko "non un PDF: esito 0"
[ -s "$T/edge.log" ] && ok || ko "non un PDF: Edge non è stato lanciato ($(errore))"
intatto "non un PDF" Prova
pulita "non un PDF"

# ── d) Verso buono: il PDF si sostituisce ─────────────────────────────────
prepara Prova
INODE_PRIMA=$(stat -c %i "$T/doc/Prova.pdf")
genera "$T/edge-finto" buono Prova.md
[ "$ESITO" -eq 0 ] && ok || ko "verso buono: esito $ESITO ($(errore))"
# Sostituito con os.replace, non copiato sopra: il file è un altro. Una copia
# sopra il PDF vero lo lascerebbe a metà se si interrompe.
[ "$(stat -c %i "$T/doc/Prova.pdf" 2>/dev/null)" != "$INODE_PRIMA" ] \
    && ok || ko "verso buono: il PDF è stato riscritto sul posto, non sostituito"
[ "$(head -1 "$T/doc/Prova.pdf" 2>/dev/null)" = "%PDF-1.4 nuovo" ] \
    && ok || ko "verso buono: il PDF non è stato sostituito: «$(head -c 60 "$T/doc/Prova.pdf" 2>/dev/null)»"
# Il frontespizio e il testo sono arrivati a Edge.
grep -qs 'Titolo di Prova' "$T/doc/Prova.pdf" && grep -qs 'Testo di Prova' "$T/doc/Prova.pdf" \
    && ok || ko "verso buono: nel PDF manca il titolo o il testo"
grep -q "^OK -> .*Prova.pdf" "$T/out" && ok || ko "verso buono: messaggio «$(cat "$T/out")»"
html=$(head -1 "$T/edge.log")
[ -n "$html" ] && [ ! -e "$html" ] && ok || ko "verso buono: l'HTML temporaneo «$html» non è stato tolto"
# Edge stampa nella cartella del PDF, non in quella temporanea: os.replace è
# atomico solo nello stesso file system, e con il Python di Windows %TEMP% sta
# su C: mentre il repository sta in WSL («Invalid cross-device link»).
uscita=$(head -1 "$T/edge.log.uscita")
[ -n "$uscita" ] && [ "$(dirname "$uscita")" = "$(realpath "$T/doc")" ] \
    && ok || ko "verso buono: Edge ha stampato in «$(dirname "$uscita")», non nella cartella del PDF"
pulita "verso buono"

# ── d2) Edge torna subito e scrive dopo: si aspetta, e l'HTML resta fino ad allora
# Attesa più lunga: il figlio scrive dopo un secondo, più l'avvio di python3
# nell'Edge finto, e con 2 s il margine non c'è (misurato il 23/9/2026).
prepara Prova
ATTESA=6 genera "$T/edge-finto" tardivo Prova.md
[ "$ESITO" -eq 0 ] && ok || ko "Edge tardivo: esito $ESITO ($(errore))"
grep -qs 'Titolo di Prova' "$T/doc/Prova.pdf" \
    && ok || ko "Edge tardivo: il PDF non ha il titolo: «$(head -c 60 "$T/doc/Prova.pdf" 2>/dev/null)»"
pulita "Edge tardivo"

# ── d2bis) Edge torna con il PDF che cresce ancora: si aspetta che smetta ──
prepara Prova
genera "$T/edge-finto" cresce Prova.md
[ "$ESITO" -eq 0 ] && ok || ko "PDF che cresce: esito $ESITO ($(errore))"
grep -qs '^resto$' "$T/doc/Prova.pdf" \
    && ok || ko "PDF che cresce: il PDF non è completo: «$(head -c 60 "$T/doc/Prova.pdf" 2>/dev/null)»"
pulita "PDF che cresce"

# ── d3) Edge si ferma a metà più di mezzo secondo: il PDF troncato non passa
prepara Prova
genera "$T/edge-finto" lento Prova.md
[ "$ESITO" -ne 0 ] && ok || ko "PDF troncato: esito 0"
intatto "PDF troncato" Prova
# Il figlio dell'Edge finto finisce di scrivere dopo che il generatore ha
# rinunciato e tolto il temporaneo: il file rinasce accanto al PDF, come può
# succedere con l'Edge vero. Si aspetta che abbia finito.
sleep 2
residuo=$(cd "$T/doc" && ls -A | grep -E '^\.Prova\..+\.nuovo\.pdf$' | head -1)
# Senza il residuo i due controlli seguenti non proverebbero niente.
[ -n "$residuo" ] && ok || ko "PDF troncato: l'Edge finto tardivo non ha lasciato il residuo atteso"
# Nella cartella versionata git non lo deve vedere.
git -C "$RADICE" check-ignore -q --no-index "docs/dpo/pacchetto-scuola/${residuo:-.Prova.x.nuovo.pdf}" \
    && ok || ko "PDF troncato: il residuo «$residuo» non è ignorato da .gitignore"
# E il giro dopo lo toglie.
genera "$T/edge-finto" buono Prova.md
[ "$ESITO" -eq 0 ] && ok || ko "giro dopo il residuo: esito $ESITO ($(errore))"
[ -n "$residuo" ] && [ ! -e "$T/doc/$residuo" ] \
    && ok || ko "giro dopo il residuo: «$residuo» è ancora lì"
pulita "giro dopo il residuo"

# ── d4) Edge crea il file vuoto e lo riempie dopo: si aspetta il contenuto ─
# Attesa più lunga, come per l'Edge tardivo.
prepara Prova
ATTESA=6 genera "$T/edge-finto" vuoto Prova.md
[ "$ESITO" -eq 0 ] && ok || ko "file vuoto poi riempito: esito $ESITO ($(errore))"
grep -qs 'Titolo di Prova' "$T/doc/Prova.pdf" \
    && ok || ko "file vuoto poi riempito: il PDF non ha il titolo: «$(head -c 60 "$T/doc/Prova.pdf" 2>/dev/null)»"
pulita "file vuoto poi riempito"

# ── f) Più file: uno fallisce, l'altro si fa lo stesso ────────────────────
prepara Rotto Prova
genera "$T/edge-finto" buono Rotto.md Prova.md
[ "$ESITO" -ne 0 ] && ok || ko "più file: esito 0 con un file fallito"
intatto "più file" Rotto
[ "$(head -1 "$T/doc/Prova.pdf" 2>/dev/null)" = "%PDF-1.4 nuovo" ] \
    && ok || ko "più file: dopo il fallimento il file successivo non è stato fatto"
grep -q 'Rotto.md' "$T/err" && ok || ko "più file: l'errore non nomina il file fallito: «$(errore)»"
pulita "più file"

# ── g) Senza argomenti non è un successo silenzioso ───────────────────────
prepara Prova
genera "$T/edge-finto" buono
[ "$ESITO" -ne 0 ] && grep -q '^Uso:' "$T/err" \
    && ok || ko "senza argomenti: esito $ESITO, «$(errore)»"
[ ! -s "$T/edge.log" ] && ok || ko "senza argomenti: Edge è stato lanciato"

echo "gen-pdf-pacchetto: $PASSATE passate, $FALLITE fallite"
[ "$FALLITE" -eq 0 ]
