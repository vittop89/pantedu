#!/bin/bash
#
# Il controllo del TeX guarda davvero: con l'immagine appena costruita, nei due
# versi (19/9/2026).
#
# Dall'8 settembre 2026 il container dell'applicazione cercava il servizio TeX
# sul proprio 127.0.0.1, dove non ascolta nessuno: undici giorni senza una
# compilazione avviata dal sito, e nessun controllo se n'è accorto. Il
# controllo `tex` della diagnostica è nato per questo, e qui si prova che
# scatti quando deve e taccia quando non deve, nel posto in cui gira dopo ogni
# rilascio: dentro il container, come www-data.
#
#   1. /health/tex nel container avviato dal workflow risponde, senza
#      configurazione, `{"tex":"non_configurato"}`: la rotta c'è e il WAF la
#      lascia passare (con la sfida risponderebbe 200 con una pagina HTML);
#   2. il guasto di produzione riprodotto — `TEX_COMPILE_ENDPOINT` sul
#      127.0.0.1 del container — deve dare esito 1 e «connessione rifiutata»;
#   3. con un servizio finto sulla stessa rete (`php -S` dalla stessa immagine,
#      che su /health risponde come quello vero) deve dare esito 0.
#
# Prova il controllo, non la topologia di produzione (il TeX sull'host, il
# gateway del bridge, la regola del firewall): quella la vedono solo il
# rilascio (/health/tex attraverso nginx, e la diagnostica dentro il container
# nuovo) e la diagnostica dell'host.
#
# La configurazione arriva come in produzione: `.env` del workflow e un
# `.env.local` montato sopra, che vince (Dotenv mutabile). L'entrypoint
# dell'immagine si salta: aspetterebbe un database che qui non serve.
#
# Uso (le variabili le imposta il workflow):
#   IMMAGINE=etichetta DATI=cartella-dei-dati ENVFILE=file-env PROVA_TEX=prefisso-unico \
#       [URL_CONTAINER=http://127.0.0.1:porta] bash tools/ci/prova-controllo-tex.sh prova
#   PROVA_TEX=prefisso-unico bash tools/ci/prova-controllo-tex.sh togli
set -euo pipefail

prova() {
    : "${IMMAGINE:?}" "${DATI:?}" "${ENVFILE:?}" "${PROVA_TEX:?}" "${RUNNER_TEMP:?}"
    local cartella="$RUNNER_TEMP/$PROVA_TEX" rete="$PROVA_TEX" finto="$PROVA_TEX-tex-finto"
    local esito=0 uscita codice i
    rm -rf "$cartella"
    mkdir -p "$cartella"
    # Il container gira come www-data (uid 33): i file montati li deve leggere.
    chmod 755 "$cartella"

    echo "── 1. /health/tex nel container del workflow"
    if [ -n "${URL_CONTAINER:-}" ]; then
        uscita=$(curl -s --max-time 10 "$URL_CONTAINER/health/tex" || true)
        echo "    $uscita"
        if [ "$uscita" = '{"tex":"non_configurato"}' ]; then
            echo "    regge: la rotta c'è, il WAF la lascia passare, senza configurazione non è un guasto"
        else
            echo "::error::/health/tex non risponde {\"tex\":\"non_configurato\"} (sfida del WAF? rotta assente?)"
            esito=1
        fi
    else
        echo "    URL_CONTAINER non impostato: salto (lo imposta container-app.sh avvia)"
    fi

    docker network create "$rete" >/dev/null

    # Un `.env.local` come quello di produzione: endpoint e segreto.
    scrivi_env_local() {
        printf 'TEX_COMPILE_ENDPOINT=%s\nTEX_COMPILE_SECRET=%s\n' "$1" "$(openssl rand -hex 32)" \
            > "$cartella/env.local"
        chmod 644 "$cartella/env.local"
    }
    diagnostica() {
        docker run --rm --network "$rete" \
            --entrypoint php -u www-data \
            -v "$DATI":/var/lib/pantedu-data \
            -v "$ENVFILE":/var/www/pantedu/.env:ro \
            -v "$cartella/env.local":/var/www/pantedu/.env.local:ro \
            -e PANTEDU_DATA_PATH=/var/lib/pantedu-data \
            "$IMMAGINE" tools/ops/diagnostica.php --solo=tex
    }

    echo "── 2. il guasto di produzione: il TeX cercato sul 127.0.0.1 del container"
    scrivi_env_local "http://127.0.0.1:8001"
    set +e
    uscita=$(diagnostica 2>&1)
    codice=$?
    set -e
    printf '%s\n' "$uscita" | sed 's/^/    /'
    if [ "$codice" -eq 1 ] && grep -q 'connessione rifiutata' <<<"$uscita"; then
        echo "    scatta: esito 1, connessione rifiutata"
    else
        echo "::error::con il TeX irraggiungibile la diagnostica doveva uscire 1 con «connessione rifiutata» (esito $codice)"
        esito=1
    fi

    echo "── 3. un servizio che risponde come il TeX, sulla stessa rete"
    cat > "$cartella/router.php" <<'PHP'
<?php
header('Content-Type: application/json');
if (parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) === '/health') {
    echo '{"status":"ok","service":"tex-compile-vps","version":"finto"}';
    return true;
}
http_response_code(404);
echo '{"detail":"Not Found"}';
return true;
PHP
    chmod 644 "$cartella/router.php"
    docker run -d --name "$finto" --network "$rete" \
        --entrypoint php -u www-data \
        -v "$cartella/router.php":/prova/router.php:ro \
        "$IMMAGINE" -S 0.0.0.0:8001 /prova/router.php >/dev/null
    for i in $(seq 1 20); do
        if docker run --rm --network "$rete" --entrypoint curl "$IMMAGINE" \
                -fsS --max-time 2 "http://$finto:8001/health" >/dev/null 2>&1; then
            echo "    il servizio finto risponde (tentativo $i)"
            break
        fi
        [ "$i" -eq 20 ] && { echo "::error::il servizio finto non è partito"; docker logs "$finto" 2>&1 | tail -20; return 1; }
        sleep 1
    done
    scrivi_env_local "http://$finto:8001"
    set +e
    uscita=$(diagnostica 2>&1)
    codice=$?
    set -e
    printf '%s\n' "$uscita" | sed 's/^/    /'
    if [ "$codice" -eq 0 ] && grep -q 'risponde dal container' <<<"$uscita"; then
        echo "    tace: esito 0, il servizio risponde dal container"
    else
        echo "::error::con il TeX raggiungibile la diagnostica doveva uscire 0 (esito $codice)"
        esito=1
    fi

    return "$esito"
}

togli() {
    : "${PROVA_TEX:?}"
    docker rm -f "$PROVA_TEX-tex-finto" >/dev/null 2>&1 || true
    docker network rm "$PROVA_TEX" >/dev/null 2>&1 || true
    [ -n "${RUNNER_TEMP:-}" ] && rm -rf "${RUNNER_TEMP:?}/$PROVA_TEX"
    return 0
}

case "${1:-}" in
    prova) prova ;;
    togli) togli ;;
    *) echo "uso: $0 prova|togli" >&2; exit 2 ;;
esac
