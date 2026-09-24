#!/bin/bash
#
# Prove di `tools/ops/aide-spiega.sh`, nei due versi.
#
# Ogni prova del filtro deve dire «attesa» quando la verifica regge e «da
# guardare» quando non regge. Un filtro provato in un verso solo può essere una
# funzione che risponde sempre «va bene»: è esattamente quello che
# aide-spiega.sh faceva, in tre casi su quattro, fino al 13 settembre 2026.
#
# Niente root e niente macchina vera: un repository git in una cartella
# temporanea, i file «installati» sotto una radice finta, e al posto di docker,
# augenrules, dpkg-query e debsums dei comandi finti che rispondono quello che
# serve alla prova. Le bandierine delle righe del rapporto sono copiate da un
# controllo vero, o misurate cambiando un attributo alla volta con AIDE 0.19.
#
# Uso: bash tests/ops/aide-spiega.test.sh
#      SPIEGA=<altro script> bash tests/ops/aide-spiega.test.sh   (per provare una versione vecchia)
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
SPIEGA="${SPIEGA:-$QUI/../../tools/ops/aide-spiega.sh}"
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT

PASSATE=0
FALLITE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

# ── Il repository, con il commit in servizio ──────────────────────────────
git init -q "$T/repo"
mkdir -p "$T/repo/tools/webhook" "$T/repo/tools/systemd"
printf '#!/bin/bash\necho rilascio\n' > "$T/repo/tools/webhook/deploy-container.sh"
printf '[Unit]\nDescription=prova\n' > "$T/repo/tools/systemd/pantedu-prova.service"
git -C "$T/repo" add -A
git -C "$T/repo" -c user.name=prova -c user.email=prova@example.invalid commit -qm prova
COMMIT=$(git -C "$T/repo" rev-parse HEAD)

# ── La radice finta ───────────────────────────────────────────────────────
R="$T/radice"
mkdir -p "$R/usr/local/bin" "$R/etc/systemd/system" "$R/etc/nginx/conf.d" "$R/etc/audit"
cp "$T/repo/tools/webhook/deploy-container.sh" "$R/usr/local/bin/pantedu-deploy-container.sh"
chmod 755 "$R/usr/local/bin/pantedu-deploy-container.sh"
printf '[Unit]\nDescription=cambiata a mano\n' > "$R/etc/systemd/system/pantedu-prova.service"
chmod 644 "$R/etc/systemd/system/pantedu-prova.service"
scrivi_upstream() {
    {
        echo "# Scritto da pantedu-deploy-container.sh il 2026-09-13 11:23:42."
        echo "# Commit in servizio: $COMMIT"
        echo "upstream pantedu_app { server 127.0.0.1:8091; }"
        [ -z "${1:-}" ] || echo "$1"
    } > "$R/etc/nginx/conf.d/pantedu-upstream.conf"
    chmod 644 "$R/etc/nginx/conf.d/pantedu-upstream.conf"
}
scrivi_upstream
printf -- '-e 2\n' > "$R/etc/audit/audit.rules"
chmod 640 "$R/etc/audit/audit.rules"

# ── I comandi finti ───────────────────────────────────────────────────────
BIN="$T/bin"
mkdir -p "$BIN"
for c in awk sort grep head git stat; do
    ln -s "$(command -v "$c")" "$BIN/$c"
done

cat > "$BIN/docker" <<'FINE'
#!/bin/bash
# docker ps … --format '{{.Image}}' oppure '{{.Ports}}'
for a in "$@"; do
    case "$a" in
        '{{.Image}}') printf '%s\n' "${PROVA_IMMAGINI:-}"; exit 0 ;;
        '{{.Ports}}') printf '%s\n' "${PROVA_PORTE:-}"; exit 0 ;;
    esac
done
exit 1
FINE

cat > "$BIN/augenrules" <<'FINE'
#!/bin/bash
if [ "${PROVA_AUGENRULES:-uguale}" = uguale ]; then
    echo "/usr/sbin/augenrules: No change"
    exit 0
fi
echo "/usr/sbin/augenrules: Rules have changed and should be updated"
exit 1
FINE

cat > "$BIN/dpkg-query" <<'FINE'
#!/bin/bash
# dpkg-query -S <percorso>
case "${2:-}" in
    /usr/bin/buono) printf 'diversion by altro from: %s\nprova: %s\n' "$2" "$2" ;;
    /usr/bin/cattivo|/usr/bin/permessi) echo "prova: $2" ;;
    /usr/bin/senza-impronte) echo "senzaimpronte:amd64: $2" ;;
    *) echo "dpkg-query: no path found matching pattern ${2:-}" >&2; exit 1 ;;
esac
FINE

cat > "$BIN/debsums" <<'FINE'
#!/bin/bash
# `debsums pkg…` scrive ogni file con il suo esito; `debsums -c pkg…` solo i
# percorsi che non combaciano. Con PROVA_DEBSUMS_GRANDE=1 l'elenco supera il
# megabyte, come dopo un aggiornamento grosso.
solo_falliti=0
if [ "${1:-}" = -c ]; then solo_falliti=1; shift; fi
for pkg in "$@"; do
    case "$pkg" in
        prova)
            if [ "$solo_falliti" = 1 ]; then
                echo /usr/bin/cattivo
                [ "${PROVA_DEBSUMS_GRANDE:-0}" = 1 ] && awk 'BEGIN { for (i = 1; i <= 200000; i++) print "/usr/share/finto/" i }'
            else
                echo "/usr/bin/cattivo    FAILED"
                [ "${PROVA_DEBSUMS_GRANDE:-0}" = 1 ] && awk 'BEGIN { for (i = 1; i <= 200000; i++) print "/usr/share/finto/" i "    OK" }'
                echo "/usr/bin/buono    OK"
                echo "/usr/bin/permessi    OK"
            fi ;;
        senzaimpronte) echo "debsums: no md5sums for senzaimpronte" >&2 ;;
    esac
done
exit 2
FINE
chmod +x "$BIN/docker" "$BIN/augenrules" "$BIN/dpkg-query" "$BIN/debsums"

senza() {  # una cartella di comandi uguale a $BIN, meno quello nominato
    local d="$T/bin-senza-$1" f
    mkdir -p "$d"
    for f in "$BIN"/*; do
        [ "${f##*/}" = "$1" ] || ln -sf "$f" "$d/"
    done
    printf '%s' "$d"
}

# ── Il rapporto ───────────────────────────────────────────────────────────
#
# Una riga per voce: bandierine, tab, percorso. Le sezioni si decidono dalla
# seconda colonna delle bandierine («+» aggiunta, «-» rimossa).
riga() { printf '%s\t%s\n' "$1" "$2"; }
RIGHE_BASE=$(
    riga 'd =.... mc.. .. .  ' /usr/local/bin                              # solo la data della cartella
    riga 'f =.... mci.... .  ' /var/lib/cloud/data/result.json             # riscritto uguale
    riga 'f >.... mci.H.. .  ' /usr/local/bin/pantedu-deploy-container.sh  # identico al commit
    riga 'f >.... mc..H.. .  ' /etc/systemd/system/pantedu-prova.service   # diverso dal commit
    riga 'f =.... mc..H.. .  ' /etc/nginx/conf.d/pantedu-upstream.conf     # punta al container sano
    riga 'f >.... mc..H.. .  ' /etc/audit/audit.rules                      # augenrules: nessun cambio
    riga 'f >b... mci.H.. .  ' /usr/bin/buono                              # debsums: OK
    riga 'f >b... mci.H.. .  ' /usr/bin/cattivo                            # debsums: FAILED
    riga 'f >b... mci.H.. .  ' /usr/bin/senza-impronte                     # pacchetto senza impronte
    riga 'f >.... mc..H.. .  ' /etc/passwd                                 # sempre da guardare
    riga 'f++++++++++++++++'   /usr/local/bin/sconosciuto                  # aggiunto, fuori elenco
    riga 'f =.p.. .....A. .  ' /usr/bin/permessi                           # permessi cambiati
    riga 'd =.... m.i. .. .  ' /usr/lib/ricreata                           # cartella ricreata
    riga 'f =..u. ....... .  ' /usr/local/bin/pantedu-deploy-trigger.sh    # proprietario cambiato
)
TOTALE_BASE=14

scrivi_rapporto() {  # $1 righe, $2 quante in più dichiara il riassunto
    local righe=$1 extra=${2:-0} aggiunte="" rimosse="" cambiate="" na=0 nr=0 nc=0 b p
    while IFS=$'\t' read -r b p; do
        [ -n "$p" ] || continue
        case "${b:1:1}" in
            '+') aggiunte+="$b: $p"$'\n'; na=$((na + 1)) ;;
            '-') rimosse+="$b: $p"$'\n'; nr=$((nr + 1)) ;;
            *)   cambiate+="$b: $p"$'\n'; nc=$((nc + 1)) ;;
        esac
    done <<< "$righe"
    {
        echo "Start timestamp: 2026-09-13 04:00:01 +0000 (AIDE 0.19.1)"
        echo "AIDE found differences between database and filesystem!!"
        echo
        echo "Summary:"
        printf '  Total number of entries:\t%d\n' 240279
        printf '  Added entries:\t\t%d\n' "$na"
        printf '  Removed entries:\t\t%d\n' "$nr"
        printf '  Changed entries:\t\t%d\n' "$((nc + extra))"
        for sezione in Added Removed Changed; do
            echo
            echo "---------------------------------------------------"
            echo "$sezione entries:"
            echo "---------------------------------------------------"
            echo
            case "$sezione" in
                Added) printf '%s' "$aggiunte" ;;
                Removed) printf '%s' "$rimosse" ;;
                Changed) printf '%s' "$cambiate" ;;
            esac
        done
        echo
        echo "---------------------------------------------------"
        echo "Detailed information about changes:"
        echo "---------------------------------------------------"
        echo
        echo "File: /usr/bin/buono"
        echo " SHA256    : Zm9vOiAv                         | YmFyOiAv"
        echo " Linkname  : altro: /non/un/percorso"
    } > "$T/aide.log"
}

# ── Lanciare il filtro ────────────────────────────────────────────────────
PROVA_IMMAGINI="ghcr.io/prova/pantedu:$COMMIT"
PROVA_PORTE="9000/tcp, 127.0.0.1:8091->8080/tcp"
PROVA_AUGENRULES=uguale
PROVA_DEBSUMS_GRANDE=0

esegui() {  # $1 cartella dei comandi (facoltativa), $2 registro (facoltativo)
    USCITA=$(env -i HOME="$T" PATH="${1:-$BIN}" \
        AIDE_SPIEGA_REPO="$T/repo" AIDE_SPIEGA_RADICE="$R" \
        AIDE_SPIEGA_PROPRIETARIO="$(id -u)" AIDE_SPIEGA_COME="" \
        PROVA_IMMAGINI="$PROVA_IMMAGINI" PROVA_PORTE="$PROVA_PORTE" \
        PROVA_AUGENRULES="$PROVA_AUGENRULES" PROVA_DEBSUMS_GRANDE="$PROVA_DEBSUMS_GRANDE" \
        "$BASH" "$SPIEGA" "${2:-$T/aide.log}" 2>&1)
    ATTESI=""
    INATTESI=""
    local riga
    while IFS= read -r riga; do
        case "$riga" in
            ATTESI=*) ATTESI=${riga#ATTESI=} ;;
            INATTESI=*) INATTESI=${riga#INATTESI=} ;;
        esac
    done <<< "$USCITA"
}

numeri() {  # $1 prova, $2 attese volute, $3 da guardare volute
    if [ "$ATTESI" = "$2" ] && [ "$INATTESI" = "$3" ]; then ok
    else ko "$1: attese $ATTESI (volute $2), da guardare $INATTESI (volute $3)"; fi
}
da_guardare() {  # $1 prova, $2 percorso
    if [[ $'\n'"$USCITA"$'\n' == *$'\n'"      $2"$'\n'* ]]; then ok
    else ko "$1: $2 doveva risultare da guardare"; fi
}
non_da_guardare() {  # $1 prova, $2 percorso
    if [[ $'\n'"$USCITA"$'\n' == *$'\n'"      $2"$'\n'* ]]; then ko "$1: $2 non doveva risultare da guardare"
    else ok; fi
}

# ── Le prove ──────────────────────────────────────────────────────────────

echo "aide-spiega: $SPIEGA"

P="tutto in ordine"
scrivi_rapporto "$RIGHE_BASE"
esegui
numeri "$P" 6 8
for x in /usr/local/bin /var/lib/cloud/data/result.json /usr/local/bin/pantedu-deploy-container.sh \
         /etc/nginx/conf.d/pantedu-upstream.conf /etc/audit/audit.rules /usr/bin/buono; do
    non_da_guardare "$P" "$x"
done
for x in /etc/systemd/system/pantedu-prova.service /usr/bin/cattivo /usr/bin/senza-impronte /etc/passwd \
         /usr/local/bin/sconosciuto /usr/bin/permessi /usr/lib/ricreata /usr/local/bin/pantedu-deploy-trigger.sh; do
    da_guardare "$P" "$x"
done

P="commit in servizio diverso da HEAD"
PROVA_IMMAGINI="ghcr.io/prova/pantedu:$(printf '0%.0s' {1..40})"
esegui
numeri "$P" 5 9
da_guardare "$P" /usr/local/bin/pantedu-deploy-container.sh
if [[ "$USCITA" == *"non si sono potuti confrontare"* ]]; then ok; else ko "$P: il resoconto deve dire perché non ha confrontato"; fi

P="scambio in corso, due immagini sane"
PROVA_IMMAGINI="ghcr.io/prova/pantedu:$COMMIT"$'\n'"ghcr.io/prova/pantedu:$(printf 'a%.0s' {1..40})"
esegui
numeri "$P" 5 9
da_guardare "$P" /usr/local/bin/pantedu-deploy-container.sh
PROVA_IMMAGINI="ghcr.io/prova/pantedu:$COMMIT"

P="upstream verso una porta senza container sano"
PROVA_PORTE="9000/tcp, 127.0.0.1:8090->8080/tcp"
esegui
numeri "$P" 5 9
da_guardare "$P" /etc/nginx/conf.d/pantedu-upstream.conf
PROVA_PORTE="9000/tcp, 127.0.0.1:8091->8080/tcp"

P="upstream con una riga in più"
scrivi_upstream "upstream altro { server 10.0.0.1:80; }"
esegui
numeri "$P" 5 9
da_guardare "$P" /etc/nginx/conf.d/pantedu-upstream.conf
scrivi_upstream

P="audit.rules non è quello che viene da rules.d"
PROVA_AUGENRULES=diverso
esegui
numeri "$P" 5 9
da_guardare "$P" /etc/audit/audit.rules
PROVA_AUGENRULES=uguale

P="script del rilascio identico ma scrivibile da tutti"
chmod 777 "$R/usr/local/bin/pantedu-deploy-container.sh"
esegui
numeri "$P" 5 9
da_guardare "$P" /usr/local/bin/pantedu-deploy-container.sh
chmod 755 "$R/usr/local/bin/pantedu-deploy-container.sh"

P="debsums non installato"
esegui "$(senza debsums)"
numeri "$P" 5 9
da_guardare "$P" /usr/bin/buono

P="dpkg-query non installato: niente sparisce dal conto"
esegui "$(senza dpkg-query)"
numeri "$P" 5 9
if [ $((ATTESI + INATTESI)) -eq "$TOTALE_BASE" ]; then ok; else ko "$P: attese + da guardare = $((ATTESI + INATTESI)), le voci sono $TOTALE_BASE"; fi
da_guardare "$P" /usr/bin/buono

P="elenco di debsums oltre il megabyte"
PROVA_DEBSUMS_GRANDE=1
esegui
numeri "$P" 6 8
da_guardare "$P" /usr/bin/cattivo
non_da_guardare "$P" /usr/bin/buono
PROVA_DEBSUMS_GRANDE=0

P="registro che non si può leggere"
esegui "" "$T/non-esiste.log"
if [ "$INATTESI" = "-1" ]; then ok; else ko "$P: da guardare «$INATTESI», voluto -1 (0 vorrebbe dire «tutto spiegato»)"; fi

P="riassunto che non torna con gli elenchi"
scrivi_rapporto "$RIGHE_BASE" 3
esegui
if [ "$INATTESI" = "-1" ]; then ok; else ko "$P: da guardare «$INATTESI», voluto -1"; fi

P="registro vuoto, o scritto in un formato che non si sa leggere"
: > "$T/vuoto.log"
esegui "" "$T/vuoto.log"
if [ "$INATTESI" = "-1" ]; then ok; else ko "$P: da guardare «$INATTESI», voluto -1"; fi

P="un file rimosso non è mai «identico al commit», anche se una copia è ancora lì"
scrivi_rapporto "$(riga 'f >.... mc..H.. .  ' /etc/shadow; riga 'f----------------' /usr/local/bin/pantedu-deploy-container.sh)"
esegui
numeri "$P" 0 2
da_guardare "$P" /usr/local/bin/pantedu-deploy-container.sh

echo "aide-spiega: $PASSATE verifiche passate, $FALLITE fallite"
[ "$FALLITE" -eq 0 ]
