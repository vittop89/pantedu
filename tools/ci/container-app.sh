#!/bin/bash
#
# Il container dell'applicazione in un job della CI: avvio, attesa, rimozione.
#
# Lo usano `immagine.yml`, che prova l'immagine appena costruita, ed `e2e.yml`,
# che fa girare la suite contro l'immagine del rilascio (14/9/2026). Prima i
# passi stavano scritti dentro `immagine.yml`; con due workflow sarebbero
# diventati due copie, e due copie divergono.
#
# Uso (le variabili le imposta il workflow):
#
#   CONTAINER=nome IMMAGINE=etichetta PORTA_DB=porta-del-servizio \
#   DATI=cartella-dei-dati ENVFILE=file-env [PORTA=porta-sull-host] \
#       bash tools/ci/container-app.sh avvia
#   CONTAINER=nome bash tools/ci/container-app.sh aspetta
#   CONTAINER=nome [IMMAGINE=etichetta] bash tools/ci/container-app.sh togli
#   bash tools/ci/container-app.sh docker      (Docker risponde?)
#
# `avvia` scrive `URL_CONTAINER` in GITHUB_ENV (e lo stampa).
set -euo pipefail

errore() { echo "::error::$*"; exit 1; }

avvia() {
    : "${CONTAINER:?}" "${IMMAGINE:?}" "${PORTA_DB:?}" "${DATI:?}" "${ENVFILE:?}"

    # La rete: quella dei servizi del job, ricavata dal NOSTRO database.
    #
    # `--network host` non va bene sul runner di casa: dentro Docker Desktop su
    # WSL il container entra nella rete della macchina virtuale di Docker, non
    # in quella della distribuzione dove vive il runner. Il container si
    # dichiarava sano (il controllo gira al suo interno) e il runner non lo
    # raggiungeva: sedici rotte tutte a `000`. Sulla rete dei servizi del job il
    # database ha già il suo nome, `mariadb`.
    #
    # E non la prima rete `github_network_*` che capita: con quattro lavori
    # insieme sulla stessa macchina era quella di un altro job, dove `mariadb`
    # non esiste («getaddrinfo for mariadb failed», 9/9/2026). La porta del
    # database sull'host è unica per job: si cerca chi la pubblica.
    local cid rete porta_host url
    cid=$(docker ps --format '{{.ID}} {{.Ports}}' | grep -F ":${PORTA_DB}->" | awk '{print $1}' | head -1 || true)
    [ -n "$cid" ] || errore "non trovo il container del database sulla porta $PORTA_DB"
    rete=$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{println $k}}{{end}}' "$cid" | grep '^github_network' | head -1 || true)
    [ -n "$rete" ] || errore "il database non sta su una rete github_network"
    echo "rete dei servizi: $rete (dal database sulla porta $PORTA_DB)"

    # La porta sull'host: quella chiesta, o una scelta da Docker. Mai una fissa
    # scritta qui: due giri insieme se la contendevano.
    local pubblica="127.0.0.1::8080"
    [ -n "${PORTA:-}" ] && pubblica="127.0.0.1:${PORTA}:8080"

    docker run -d --name "$CONTAINER" \
        --network "$rete" \
        -p "$pubblica" \
        -v "$DATI":/var/lib/pantedu-data \
        -v "$ENVFILE":/var/www/pantedu/.env:ro \
        -e PANTEDU_DATA_PATH=/var/lib/pantedu-data \
        "$IMMAGINE" >/dev/null

    porta_host=$(docker port "$CONTAINER" 8080/tcp | grep -m1 '^127\.0\.0\.1:' | cut -d: -f2 || true)
    [ -n "$porta_host" ] || errore "il container non pubblica la 8080 su 127.0.0.1"
    url="http://127.0.0.1:$porta_host"
    [ -n "${GITHUB_ENV:-}" ] && echo "URL_CONTAINER=$url" >> "$GITHUB_ENV"
    echo "URL_CONTAINER=$url"
    echo "container $CONTAINER su $url"
}

aspetta() {
    : "${CONTAINER:?}"
    local i stato
    for i in $(seq 1 "${TENTATIVI:-30}"); do
        stato=$(docker inspect -f '{{.State.Health.Status}}' "$CONTAINER" 2>/dev/null || echo "assente")
        echo "tentativo $i: $stato"
        [ "$stato" = "healthy" ] && return 0
        if [ "$stato" = "unhealthy" ] || ! docker ps -q -f "name=^${CONTAINER}\$" | grep -q .; then
            echo "::error::il container non è sano"
            docker logs "$CONTAINER" 2>&1 || true
            return 1
        fi
        sleep "${PAUSA:-4}"
    done
    echo "::error::il controllo di salute non è mai passato"
    docker logs "$CONTAINER" 2>&1 || true
    return 1
}

togli() {
    : "${CONTAINER:?}"
    docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
    if [ -n "${IMMAGINE:-}" ]; then
        docker rmi "$IMMAGINE" >/dev/null 2>&1 || true
    fi
}

# Docker c'è davvero? Sul runner di casa lo fornisce Docker Desktop attraverso
# l'integrazione con WSL: se non è avviato il binario non è nemmeno nel
# percorso, e il giro muore dieci passi più in là con «docker: command not
# found» (9/9/2026), che non dice la cosa da fare. Niente apici inversi nei
# messaggi: dentro le doppie virgolette bash li eseguirebbe.
docker_risponde() {
    if ! command -v docker >/dev/null 2>&1; then
        errore 'docker non è nel percorso. Sul runner di casa vuol dire che Docker Desktop non è avviato: aprilo, aspetta «Engine running», e rilancia il giro.'
    fi
    if ! docker info >/dev/null 2>&1; then
        errore 'Docker è installato ma il servizio non risponde. Sul runner di casa: apri Docker Desktop e aspetta «Engine running», poi rilancia.'
    fi
    echo "docker: $(docker --version)"
}

case "${1:-}" in
    docker) docker_risponde ;;
    avvia) avvia ;;
    aspetta) aspetta ;;
    togli) togli ;;
    *) echo "uso: $0 docker|avvia|aspetta|togli" >&2; exit 2 ;;
esac
