#!/bin/bash
#
# Prove della guardia di WSL in `tools/legal/build_pdf.sh`, nei due versi
# (22/9/2026).
#
# Il difetto che l'ha fatta nascere: lanciato con il bash di WSL, lo script non
# trovava pandoc e suggeriva «winget install --id JohnMacFarlane.Pandoc». In
# WSL è un consiglio sbagliato: il modo giusto è il bash di Git per Windows,
# lanciato dalla sessione in WSL (docs/dev/sviluppo-in-wsl.md, «Che cosa si fa
# ancora da Windows»).
#
# Si prova su cartelle usa e getta, MAI sui PDF del repository. Lo script
# scrive il PDF accanto al .md che riceve: qui i .md stanno in cartelle
# temporanee, e alla fine si controlla che i PDF del repository non si siano
# mossi. pandoc, xelatex, python, perl e pdfinfo sono finti e scrivono in un
# registro se sono stati chiamati: pandoc e xelatex veri non servono (in CI non
# ci sono). L'ambiente si azzera con `env -i`, e WSL_DISTRO_NAME c'è solo dove
# la mette la prova: così l'esito non dipende da dove gira. I runner di casa
# stanno dentro WSL ma non hanno la variabile (misurato il 22/9/2026 su
# /proc/<pid>/environ dei Runner.Listener); una shell Ubuntu sì.
#
#   a) WSL_DISTRO_NAME=Ubuntu (e un altro nome): si ferma con esito 3, rimanda alla
#      guida, niente winget, nessun programma chiamato, nessun file creato o
#      toccato; anche con --all, e anche senza pandoc (il caso del difetto);
#   b) WSL_DISTRO_NAME tolta: la guardia non scatta, pandoc viene chiamato e il
#      PDF esce accanto al .md;
#   c) WSL_DISTRO_NAME vuota: come (b);
#   d) fuori da WSL e senza pandoc: il suggerimento di winget resta. Senza
#      questo, l'assenza di winget in (a) potrebbe voler dire solo che il
#      suggerimento non c'è più.
#
# Controprova: SCRIPT=<copia della versione vecchia> bash tests/ops/build-pdf-wsl.test.sh
#
# Uso: bash tests/ops/build-pdf-wsl.test.sh
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
RADICE_REPO=$(cd "$QUI/../.." && pwd)
SCRIPT="${SCRIPT:-$RADICE_REPO/tools/legal/build_pdf.sh}"
BASH_VERO=$(command -v bash)
# Se mktemp fallisce T resta vuota, e `rm -rf "$T/tmp"` diventerebbe
# `rm -rf /tmp`: ci si ferma.
T=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/build-pdf-wsl.XXXXXX") \
    || { echo "build-pdf-wsl: mktemp non riuscito, niente da provare"; exit 1; }
[ -n "$T" ] && [ -d "$T" ] || { echo "build-pdf-wsl: cartella temporanea mancante"; exit 1; }
trap 'rm -rf "${T:?}"' EXIT

PASSATE=0
FALLITE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

# I PDF del repository, prima: alla fine devono essere gli stessi.
pdf_repo() { find "$RADICE_REPO/docs" -name '*.pdf' -type f -exec stat -c '%n %s %Y' {} + 2>/dev/null | sort; }
PDF_REPO_PRIMA=$(pdf_repo)

# ── I programmi finti ──────────────────────────────────────────────────────
# Ognuno scrive nel registro il proprio nome e gli argomenti.
REGISTRO="$T/chiamate.log"
mkdir -p "$T/bin" "$T/bin-senza-pandoc"
finto() {
    printf '#!/bin/bash\nprintf "%%s %%s\\n" %s "$*" >> "$REGISTRO"\n%s\n' "$1" "$2" > "$T/bin/$1"
    chmod +x "$T/bin/$1"
}
# pandoc scrive un PDF finto dove chiede -o.
finto pandoc 'while [ $# -gt 0 ]; do [ "$1" = "-o" ] && printf "PDF finto\n" > "$2"; shift; done; exit 0'
finto xelatex 'exit 0'
finto python 'exec cat'
finto perl 'for a; do :; done; exec cat "$a"'
finto pdfinfo 'echo "Pages:          2"'
for p in xelatex python perl pdfinfo; do cp "$T/bin/$p" "$T/bin-senza-pandoc/$p"; done

# lancia CARTELLA PATH [VAR=valore ...] -- argomenti: lo script, dalla cartella,
# con l'ambiente azzerato. Uscita in $T/out, errori in $T/err, esito in $ESITO.
lancia() {
    local dove="$1" path="$2"; shift 2
    local vars=()
    while [ "$1" != "--" ]; do vars+=("$1"); shift; done
    shift
    : > "$REGISTRO"
    mkdir -p "$T/tmp"
    ( cd "$dove" && env -i PATH="$path" TMPDIR="$T/tmp" REGISTRO="$REGISTRO" "${vars[@]}" \
        "$BASH_VERO" "$SCRIPT" "$@" ) > "$T/out" 2> "$T/err"
    ESITO=$?
}

# Una cartella di lavoro con un .md e il suo PDF «versionato», datato nel
# passato: se lo script lo tocca, cambiano contenuto o data.
cartella() {
    mkdir -p "$1/docs/privacy"
    printf '# Prova\n\nTesto.\n' > "$1/docs/privacy/$2.md"
    printf 'PDF versionato\n' > "$1/docs/privacy/$2.pdf"
    touch -d '2026-01-01 00:00:00' "$1/docs/privacy/$2.pdf"
}
fotografia() { find "$1" -exec stat -c '%n %s %Y' {} + | sort; }

# ── a) Dentro WSL: si ferma ────────────────────────────────────────────────
cartella "$T/a" prova
PRIMA=$(fotografia "$T/a")
lancia "$T/a" "$T/bin:/usr/bin:/bin" WSL_DISTRO_NAME=Ubuntu -- docs/privacy/prova.md
# 3 e non un esito qualunque: 1 vuol dire «un documento fallito», 2 «uso
# sbagliato» (commento accanto alla guardia).
[ "$ESITO" -eq 3 ] && ok || ko "(a) in WSL l'esito è $ESITO, atteso 3"
grep -q 'docs/dev/sviluppo-in-wsl.md' "$T/err" && grep -q 'Che cosa si fa ancora da Windows' "$T/err" \
    && ok || ko "(a) il messaggio non rimanda alla guida: «$(cat "$T/err")»"
grep -qi winget "$T/out" "$T/err" && ko "(a) in WSL suggerisce winget: «$(cat "$T/err")»" || ok
[ ! -s "$T/out" ] && ok || ko "(a) il messaggio non va su stderr: «$(cat "$T/out")»"
grep -q '^pandoc' "$REGISTRO" && ko "(a) pandoc è stato chiamato: «$(grep '^pandoc' "$REGISTRO")»" || ok
[ ! -s "$REGISTRO" ] && ok || ko "(a) sono stati chiamati programmi: «$(cat "$REGISTRO")»"
[ "$(fotografia "$T/a")" = "$PRIMA" ] && [ "$(cat "$T/a/docs/privacy/prova.pdf")" = "PDF versionato" ] \
    && ok || ko "(a) la cartella è cambiata: PDF creato o toccato"
[ -z "$(ls -A "$T/tmp")" ] && ok || ko "(a) file temporanei creati: «$(ls -A "$T/tmp")»"

# Anche con --all, che prende i percorsi dalla cartella corrente.
cartella "$T/a-tutti" dpia
PRIMA=$(fotografia "$T/a-tutti")
lancia "$T/a-tutti" "$T/bin:/usr/bin:/bin" WSL_DISTRO_NAME=Ubuntu -- --all
[ "$ESITO" -eq 3 ] && [ ! -s "$REGISTRO" ] && [ "$(fotografia "$T/a-tutti")" = "$PRIMA" ] \
    && ok || ko "(a) con --all: esito $ESITO, chiamate «$(cat "$REGISTRO")»"

# Il difetto com'era: in WSL e senza pandoc. Il PATH è SOLO la cartella dei
# finti senza pandoc, senza /usr/bin:/bin: se pandoc un giorno si installa in
# WSL o sui runner, una guardia spostata dopo la ricerca di pandoc deve restare
# visibile. Fino al messaggio di winget lo script usa solo comandi interni di
# bash (`command -v`), e la guardia solo echo ed exit. Il pandoc di Windows nella
# posizione nota lo troverebbe lo stesso: lì il caso non si prova, e lo si dice.
if [ -e "/c/Program Files/Pandoc/pandoc.exe" ]; then
    echo "  ((a) senza pandoc saltato: pandoc di Windows presente in /c/Program Files/Pandoc)"
else
    lancia "$T/a" "$T/bin-senza-pandoc" WSL_DISTRO_NAME=Ubuntu -- docs/privacy/prova.md
    [ "$ESITO" -eq 3 ] && grep -q 'Che cosa si fa ancora da Windows' "$T/err" && ! grep -qi winget "$T/err" \
        && ok || ko "(a) in WSL senza pandoc: esito $ESITO, «$(cat "$T/err")»"
fi

# La guardia vale per ogni distribuzione, non solo per «Ubuntu»: anche per un
# nome che nessun elenco scritto a mano conterrebbe.
for distro in Debian "prova-$$"; do
    lancia "$T/a" "$T/bin:/usr/bin:/bin" WSL_DISTRO_NAME="$distro" -- docs/privacy/prova.md
    [ "$ESITO" -eq 3 ] && [ ! -s "$REGISTRO" ] && grep -q 'Che cosa si fa ancora da Windows' "$T/err" \
        && ok || ko "(a) con WSL_DISTRO_NAME=$distro: esito $ESITO, chiamate «$(cat "$REGISTRO")»"
done

# ── b) e c) Fuori da WSL: la guardia non scatta ────────────────────────────
# (b) senza la variabile, (c) con la variabile vuota.
for caso in b c; do
    rm -rf "${T:?}/$caso" "${T:?}/tmp"
    mkdir -p "$T/$caso/docs/privacy"
    printf '# Prova\n\nTesto.\n' > "$T/$caso/docs/privacy/prova.md"
    if [ "$caso" = b ]; then
        lancia "$T/$caso" "$T/bin:/usr/bin:/bin" -- docs/privacy/prova.md
    else
        lancia "$T/$caso" "$T/bin:/usr/bin:/bin" WSL_DISTRO_NAME= -- docs/privacy/prova.md
    fi
    grep -q '^pandoc ' "$REGISTRO" && ok || ko "($caso) pandoc non è stato chiamato: «$(cat "$T/err")»"
    grep -q 'sviluppo-in-wsl' "$T/err" && ko "($caso) la guardia è scattata fuori da WSL" || ok
    [ "$ESITO" -eq 0 ] && grep -q 'prova.pdf.*ok' "$T/out" \
        && ok || ko "($caso) esito $ESITO, uscita «$(cat "$T/out")», errori «$(cat "$T/err")»"
    [ "$(cat "$T/$caso/docs/privacy/prova.pdf" 2>/dev/null)" = "PDF finto" ] \
        && ok || ko "($caso) il PDF non è uscito accanto al .md"
done

# ── d) Fuori da WSL, senza pandoc: il suggerimento di winget resta ─────────
# Il PATH è una cartella vuota: fino al controllo di pandoc lo script usa solo
# comandi interni. Su una macchina con pandoc nella posizione nota di Windows
# lo script lo troverebbe: lì il caso non si prova, e lo si dice.
if [ -e "/c/Program Files/Pandoc/pandoc.exe" ]; then
    echo "  (d saltato: pandoc di Windows presente in /c/Program Files/Pandoc)"
else
    mkdir -p "$T/vuota"
    lancia "$T/a" "$T/vuota" -- docs/privacy/prova.md
    [ "$ESITO" -ne 0 ] && grep -q 'winget install --id JohnMacFarlane.Pandoc' "$T/err" \
        && ! grep -q 'sviluppo-in-wsl' "$T/err" \
        && ok || ko "(d) fuori da WSL senza pandoc: esito $ESITO, «$(cat "$T/err")»"
fi

# ── I PDF del repository non si sono mossi ─────────────────────────────────
[ "$(pdf_repo)" = "$PDF_REPO_PRIMA" ] && ok || ko "sono cambiati PDF del repository"

echo "build-pdf-wsl: $PASSATE passate, $FALLITE fallite"
[ "$FALLITE" -eq 0 ]
