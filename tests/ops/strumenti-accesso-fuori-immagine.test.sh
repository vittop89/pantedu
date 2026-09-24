#!/bin/bash
#
# Gli strumenti d'accesso al server che la copia pubblica toglie restano fuori
# anche dall'immagine del container, nei due versi (23/9/2026, A-85).
#
# Il difetto. `tools/publish/sanitize-for-publication.php` toglie dalla copia
# pubblica la console di accesso (`pantedu.exe`, `pantedu.bat`,
# `tools/pantedu`) e la cassetta della postazione (`tools/postazione`), perché
# descrivono come si entra nel server. `.dockerignore` non li escludeva: la
# stessa mappa viaggiava dentro l'immagine, contro il principio scritto in
# testa a `.dockerignore`. Nessuno la serviva via web, ma chi ottiene un
# accesso al container o all'immagine nel registro la trovava.
#
# Che cosa prova. L'elenco lo dà il sanitizer: le voci fra i segni
# `// >>> strumenti d'accesso` e `// <<< strumenti d'accesso`. Ognuna deve
# essere esclusa da `.dockerignore`, con le regole di Docker: il percorso
# relativo alla radice, `*` che non attraversa `/`, `**` che sì, una cartella
# esclusa che esclude quello che contiene, `!` che rimette dentro, l'ultima
# riga che combacia vince. Il lettore delle regole si prova per primo, su un
# `.dockerignore` finto, nei due versi: se rispondesse sempre «escluso», la
# prova vera passerebbe senza aver guardato niente. Nessuna riga `!` deve
# rimettere dentro un pezzo di uno strumento, e ogni file che c'è davvero sotto
# la voce deve risultare escluso. Poi un sanitizer finto con uno strumento in
# più deve far scattare la prova. E il Dockerfile non deve averne bisogno.
#
# Controprove: con il `.dockerignore` di prima deve fallire sulle quattro voci:
#   git show 2a28fdc1:.dockerignore > /tmp/di && DOCKERIGNORE=/tmp/di bash tests/ops/strumenti-accesso-fuori-immagine.test.sh
# e con un'eccezione in coda, che rimette dentro un file della postazione:
#   { cat .dockerignore; echo '!tools/postazione/README.md'; } > /tmp/di && DOCKERIGNORE=/tmp/di bash tests/ops/strumenti-accesso-fuori-immagine.test.sh
#
# Uso: bash tests/ops/strumenti-accesso-fuori-immagine.test.sh
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
RADICE_REPO=$(cd "$QUI/../.." && pwd)
SANITIZER="${SANITIZER:-$RADICE_REPO/tools/publish/sanitize-for-publication.php}"
DOCKERIGNORE="${DOCKERIGNORE:-$RADICE_REPO/.dockerignore}"
DOCKER_DIR="${DOCKER_DIR:-$RADICE_REPO/docker}"
T=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/strumenti-accesso.XXXXXX")
trap 'rm -rf "$T"' EXIT

PASSATE=0
FALLITE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

# Nella copia pubblica il sanitizer non c'è (si cancella da sé): lì non c'è
# niente da confrontare, e lo si dice.
if [ ! -f "$SANITIZER" ]; then
    echo "strumenti-accesso-fuori-immagine: sanitizer assente (copia pubblica), niente da provare"
    exit 0
fi

# Le voci fra i segni del sanitizer, una per riga: la stringa fra apici con
# cui comincia la riga (il commento che segue, se c'è, non conta).
strumenti() {
    sed -n "/\/\/ >>> strumenti d'accesso/,/\/\/ <<< strumenti d'accesso/p" "$1" \
        | sed -n "s/^[[:space:]]*'\([^']*\)'.*/\1/p"
}

# Un modello di .dockerignore come espressione regolare estesa, ancorata.
# Come Docker (moby/patternmatcher): `**/` zero o più cartelle, `**` qualunque
# cosa, `*` e `?` dentro un solo segmento del percorso. Le parentesi quadre
# restano classi.
regex_di() {
    printf '%s' "$1" | sed -e 's/[.+(){}|^$\\]/\\&/g' \
        -e 's/\*\*\//\x02/g' -e 's/\*\*/\x01/g' -e 's/\*/[^\/]*/g' -e 's/?/[^\/]/g' \
        -e 's/\x02/(.*\/)?/g' -e 's/\x01/.*/g' \
        | sed -e 's/^/^/' -e 's/$/$/'
}

# escluso DOCKERIGNORE PERCORSO: esce 0 se Docker lascerebbe fuori il percorso.
# Combacia il percorso o una delle cartelle che lo contengono; l'ultima riga
# che combacia vince, e un `!` la rimette dentro.
escluso() {
    local file="$1" percorso="$2" riga modello negato re pezzo prefisso esito=1
    while IFS= read -r riga || [ -n "$riga" ]; do
        riga="${riga%$'\r'}"
        riga="${riga#"${riga%%[![:space:]]*}"}"
        riga="${riga%"${riga##*[![:space:]]}"}"
        # Righe vuote e commenti. Il `case` su più righe e non su una sola: il
        # lettore di bash di semgrep non regge la forma corta, e il file sarebbe
        # letto solo in parte dalla scansione (cancello di semgrep).
        case "$riga" in
            '' | '#'*) continue ;;
        esac
        negato=0
        if [ "${riga:0:1}" = '!' ]; then
            negato=1
            riga="${riga:1}"
        fi
        modello="${riga#/}"
        modello="${modello%/}"
        re=$(regex_di "$modello")
        prefisso=""
        IFS='/' read -r -a pezzi <<< "$percorso"
        for pezzo in "${pezzi[@]}"; do
            prefisso="${prefisso:+$prefisso/}$pezzo"
            if [[ "$prefisso" =~ $re ]]; then
                esito=$negato
                break
            fi
        done
    done < "$file"
    return "$esito"
}

# eccezioni_sotto DOCKERIGNORE VOCE: stampa le righe `!` che possono rimettere
# dentro qualcosa della voce, in qualunque punto del file stiano. Il controllo
# per percorso qui sopra guarda la voce e un nome inventato; un'eccezione come
# `!tools/postazione/README.md` rimette un file preciso, e lo si vede solo così.
# Per modello: la parte prima del primo carattere jolly (`*`, `?`, `[`). Senza
# jolly, tocca la voce se è la voce, se sta sotto la voce o se è una cartella
# che la contiene; con un jolly, se quella parte e «voce/» cominciano una con
# l'altra (`!**/README.md` e `!tools/*/x` sì, `!docs/*.md` no). Largo di
# proposito: un'eccezione sotto uno strumento d'accesso non serve mai.
# comincia_con TESTO PREFISSO: esce 0 se TESTO comincia con PREFISSO, preso
# alla lettera (niente jolly). Un prefisso vuoto combacia con tutto.
comincia_con() {
    [ -z "$2" ] || [ "${1#"$2"}" != "$1" ]
}

eccezioni_sotto() {
    local file="$1" voce="$2" riga modello fisso rimette
    while IFS= read -r riga || [ -n "$riga" ]; do
        riga="${riga%$'\r'}"
        riga="${riga#"${riga%%[![:space:]]*}"}"
        riga="${riga%"${riga##*[![:space:]]}"}"
        [ "${riga:0:1}" = '!' ] || continue
        modello="${riga:1}"
        modello="${modello#/}"
        modello="${modello%/}"
        fisso="${modello%%[*?[]*}"
        rimette=no
        if [ "$fisso" = "$modello" ]; then
            # Senza jolly: la voce stessa, qualcosa sotto la voce, o una
            # cartella che la contiene.
            comincia_con "$voce/" "$modello/" && rimette=si
            comincia_con "$modello/" "$voce/" && rimette=si
        else
            comincia_con "$voce/" "$fisso" && rimette=si
            comincia_con "$fisso" "$voce/" && rimette=si
        fi
        [ "$rimette" = no ] || printf '%s\n' "$riga"
    done < "$file"
}

# ── 1. Il lettore delle regole, su un .dockerignore finto, nei due versi ───
cat > "$T/finto.dockerignore" <<'FINTO'
# un commento
tools/dev
docs
!docs/legal
*.md
!README.md
cartella/
/radice-assoluta
tools/**/segreto.txt
FINTO
for p in tools/dev tools/dev/wsl/x.sh docs/a.md docs/sotto/b.md NOTE.md cartella/f radice-assoluta \
         tools/a/b/segreto.txt tools/segreto.txt; do
    escluso "$T/finto.dockerignore" "$p" && ok || ko "lettore: «$p» doveva risultare escluso"
done
# Il verso che non deve scattare: senza, un lettore che dice sempre «escluso»
# farebbe passare tutto quello che segue.
for p in docs/legal/termini.md README.md wiki/a.md tools/devx tools/pantedu pantedu.exe \
         altro/tools/dev tools/segreto.txt.bak app/index.php; do
    escluso "$T/finto.dockerignore" "$p" && ko "lettore: «$p» risultava escluso e non doveva" || ok
done

# E il lettore delle eccezioni, nei due versi. Le righe `!` del finto qui sopra
# (`!docs/legal`, `!README.md`) non toccano gli strumenti; queste sì.
for e in '!tools/postazione/README.md' '!/tools/postazione/' '!tools' '!**/README.md' '!tools/*/README.md' \
         '!tools/posta*'; do
    printf 'tools/postazione\n%s\n' "$e" > "$T/eccezione.dockerignore"
    [ -n "$(eccezioni_sotto "$T/eccezione.dockerignore" tools/postazione)" ] \
        && ok || ko "eccezioni: «$e» rimette dentro qualcosa di tools/postazione e non è risultata"
done
for e in '!docs/legal' '!README.md' '!docs/*.md' '!tools/postazione-altro' '!tools/pantedu/x'; do
    printf 'tools/postazione\n%s\n' "$e" > "$T/eccezione.dockerignore"
    [ -z "$(eccezioni_sotto "$T/eccezione.dockerignore" tools/postazione)" ] \
        && ok || ko "eccezioni: «$e» non tocca tools/postazione e risultava"
done

# ── 2. Le voci del sanitizer ci sono, e i segni non sono spariti ──────────
mapfile -t VOCI < <(strumenti "$SANITIZER")
# Quattro al 23/9/2026. Se i segni sparissero l'elenco sarebbe vuoto, e la
# prova qui sotto passerebbe su niente.
if [ "${#VOCI[@]}" -ge 4 ]; then ok; else ko "il sanitizer ha ${#VOCI[@]} voci fra i segni «strumenti d'accesso» (attese almeno 4)"; fi
for atteso in pantedu.exe pantedu.bat tools/pantedu tools/postazione; do
    printf '%s\n' "${VOCI[@]}" | grep -qxF "$atteso" && ok || ko "«$atteso» non è più fra i segni del sanitizer"
done

# ── 3. La prova vera: ognuna è esclusa dall'immagine ──────────────────────
for v in "${VOCI[@]}"; do
    escluso "$DOCKERIGNORE" "$v" \
        && ok || ko "«$v»: la copia pubblica lo toglie, ma .dockerignore lo lascia entrare nell'immagine"
    # Una cartella esclusa esclude anche quello che contiene.
    escluso "$DOCKERIGNORE" "$v/qualcosa" && ok || ko "«$v/qualcosa» entrerebbe nell'immagine"
    # Nessuna eccezione successiva rimette dentro un pezzo della voce.
    rimesse=$(eccezioni_sotto "$DOCKERIGNORE" "$v")
    [ -z "$rimesse" ] && ok || ko "«$v»: .dockerignore ha eccezioni che ne rimettono dentro una parte: $(printf '%s ' "$rimesse" | tr '\n' ' ')"
    # E ogni file che c'è davvero sotto la voce resta fuori: è quello che
    # Docker copierebbe.
    if [ -e "$RADICE_REPO/$v" ]; then
        while IFS= read -r f; do
            f="${f#"$RADICE_REPO"/}"
            escluso "$DOCKERIGNORE" "$f" && ok || ko "«$f» entrerebbe nell'immagine"
        done < <(find "$RADICE_REPO/$v" -type f)
    fi
done

# ── 4. Uno strumento nuovo nel sanitizer, dimenticato in .dockerignore ────
sed "s#^\([[:space:]]*\)// <<< strumenti d'accesso\$#\1'tools/accesso-nuovo',\n&#" "$SANITIZER" > "$T/sanitizer-finto.php"
mancano=0
while IFS= read -r v; do
    escluso "$DOCKERIGNORE" "$v" || mancano=$((mancano + 1))
done < <(strumenti "$T/sanitizer-finto.php")
[ "$mancano" -ge 1 ] && ok || ko "uno strumento aggiunto al sanitizer e non a .dockerignore non fa scattare la prova"

# ── 5. L'immagine non ne ha bisogno ───────────────────────────────────────
for v in "${VOCI[@]}"; do
    if grep -rqF -- "$v" "$DOCKER_DIR"; then
        ko "«$v» è nominato in $DOCKER_DIR: escluderlo romperebbe il build"
    else
        ok
    fi
done

echo "strumenti-accesso-fuori-immagine: $PASSATE passate, $FALLITE fallite"
[ "$FALLITE" -eq 0 ]
