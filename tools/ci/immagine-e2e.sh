#!/bin/bash
#
# Quale immagine prova la suite end-to-end (14/9/2026).
#
# La suite gira contro l'immagine che si rilascia,
# `ghcr.io/<proprietario>/pantedu:<commit>`: la stessa che
# `tools/webhook/deploy-container.sh` mette in servizio. Così le differenze fra
# CI e produzione (php.ini, nginx, PHP-FPM, sessioni, permessi di www-data)
# spariscono per costruzione. Fino al 14 settembre la suite girava su `php -S`
# con le impostazioni del runner, e in produzione le sessioni stavano nella
# /tmp del container senza che nessuna prova potesse vederlo (ADR-039).
#
# Dopo un'unione (push) e la domenica (schedule), e a mano da `main`,
# l'immagine del commit la costruisce `immagine.yml`: se non è ancora
# pubblicata si aspetta, come fa il rilascio. Se non arriva il giro si ferma
# dicendolo. Costruirla qui al suo posto darebbe una suite verde su
# un'immagine che non è quella del rilascio: il verde che non ha guardato.
#
# A mano da un altro ramo (workflow_dispatch) l'immagine di quel commit di
# solito non è pubblicata: la si costruisce nel job, e lo si dice.
#
# Ingresso: EVENTO, RAMO, SHA, REGISTRO_IMMAGINE (es. ghcr.io/proprietario/pantedu);
#           ATTESA_SECONDI (900), PAUSA (30);
#           TOKEN_REGISTRO e UTENTE_REGISTRO per entrare nel registro.
# Uscita, in GITHUB_ENV: IMMAGINE_E2E, ORIGINE_IMMAGINE (registro|costruita),
#           DA_COSTRUIRE (1 se va costruita nel job).
set -euo pipefail

: "${EVENTO:?}" "${RAMO:?}" "${SHA:?}" "${REGISTRO_IMMAGINE:?}"
ETICHETTA="$REGISTRO_IMMAGINE:$SHA"
ATTESA="${ATTESA_SECONDI:-900}"
PAUSA="${PAUSA:-30}"

# Le credenziali del registro stanno in una cartella di Docker di questo job
# (14/9/2026). I runner di casa hanno un solo `~/.docker/config.json`. Il primo
# giro dopo l'unione della #98 è morto con «unauthorized» proprio quando
# l'immagine è arrivata: `immagine.yml` l'aveva appena pubblicata e, finendo,
# il suo `docker/login-action` aveva fatto `docker logout ghcr.io`, togliendo le
# credenziali anche a questo job. Al contrario, il logout di questo job poteva
# toglierle a un `immagine.yml` che stava pubblicando. La cartella si butta
# all'uscita: l'immagine scaricata resta nel demone, e i passi dopo non hanno
# bisogno di credenziali.
if [ -n "${TOKEN_REGISTRO:-}" ]; then
    : "${UTENTE_REGISTRO:?UTENTE_REGISTRO serve con TOKEN_REGISTRO}"
    DOCKER_CONFIG=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/docker-registro.XXXXXX")
    export DOCKER_CONFIG
    trap 'rm -rf "$DOCKER_CONFIG"' EXIT
    if ! printf '%s' "$TOKEN_REGISTRO" | docker login "${REGISTRO_IMMAGINE%%/*}" -u "$UTENTE_REGISTRO" --password-stdin >/dev/null; then
        echo "::error::non riesco a entrare nel registro ${REGISTRO_IMMAGINE%%/*}"
        exit 1
    fi
fi

esci() {
    local riga
    for riga in "IMMAGINE_E2E=$1" "ORIGINE_IMMAGINE=$2" "DA_COSTRUIRE=$3"; do
        [ -n "${GITHUB_ENV:-}" ] && echo "$riga" >> "$GITHUB_ENV"
        echo "$riga"
    done
    exit 0
}

# Scarica l'immagine. 0 = c'è; 1 = non c'è (ancora); 2 = il registro rifiuta
# le credenziali, e aspettare non serve.
scarica() {
    local err
    if err=$(docker pull --quiet "$ETICHETTA" 2>&1 >/dev/null); then
        return 0
    fi
    if grep -qiE "denied|unauthorized|authentication required" <<<"$err"; then
        echo "::error::il registro rifiuta l'accesso a $ETICHETTA: $err"
        return 2
    fi
    return 1
}

aspetta=0
case "$EVENTO" in push | schedule) aspetta=1 ;; esac
[ "$RAMO" = "main" ] && aspetta=1

if [ "$aspetta" = 1 ]; then
    inizio=$(date +%s)
    detto=""
    while :; do
        esito=0; scarica || esito=$?
        [ "$esito" -eq 0 ] && { echo "immagine del rilascio: $ETICHETTA, dal registro"; esci "$ETICHETTA" registro 0; }
        [ "$esito" -eq 2 ] && exit 1
        if [ $(($(date +%s) - inizio)) -ge "$ATTESA" ]; then
            echo "::error::l'immagine $ETICHETTA non è arrivata nel registro in $((ATTESA / 60)) minuti. Guarda il giro di immagine.yml di questo commit: se è rosso o annullato, la suite non ha l'immagine del rilascio da provare, e non ne prova un'altra al suo posto."
            exit 1
        fi
        [ -z "$detto" ] && echo "l'immagine non c'è ancora: la costruisce immagine.yml, aspetto (al massimo $((ATTESA / 60)) minuti)"
        detto=1
        sleep "$PAUSA"
    done
fi

esito=0; scarica || esito=$?
[ "$esito" -eq 0 ] && { echo "immagine di $SHA: dal registro"; esci "$ETICHETTA" registro 0; }
[ "$esito" -eq 2 ] && exit 1
echo "l'immagine di $SHA non è nel registro (ramo $RAMO, a mano): la costruisco nel job"
esci "pantedu:e2e-${GITHUB_RUN_ID:-locale}-${GITHUB_RUN_ATTEMPT:-1}" costruita 1
