#!/usr/bin/env bash
#
# Il server incorporato di PHP di un job, su una porta sua (14/9/2026).
#
# Perché. I runner di casa sono quattro, nella stessa WSL: stessa rete, stessa
# /tmp. I workflow avviavano tutti `php -S 127.0.0.1:8000` con il registro in
# /tmp/php-server.log. Il 14 settembre 2026 il controllo di accessibilità di
# una pull request (giro 34846303891) è girato tutto dentro la E2E di `main`
# su un altro runner: il suo server non ha potuto prendere la porta, l'errore è
# finito nel registro, e le prove hanno interrogato il server dell'altro job.
# Verde, senza aver provato il codice della pull request. Il giro dopo, da
# solo, ha dato 500 su ogni pagina.
#
# Quindi:
#   - la porta la sceglie il sistema, libera in quel momento;
#   - il registro sta in RUNNER_TEMP, del job;
#   - «avviato» vuol dire che il NOSTRO processo è vivo e ha preso la porta,
#     non che qualcuno risponde su quella porta: se il bind fallisce il
#     processo muore, e il job si ferma dicendolo.
#
# Uso, in due passi dello stesso job (le variabili passano da GITHUB_ENV):
#   bash tools/ci/server-php.sh scegli
#       scrive PORTA_SERVER, URL_SERVER, REGISTRO_SERVER
#   bash tools/ci/server-php.sh avvia [router.php]
#       avvia `php -S` su PORTA_SERVER con docroot public/, scrive PID_SERVER;
#       PHP_CLI_SERVER_WORKERS passa così com'è
#
# Fuori da GitHub (per le prove) le variabili si passano nell'ambiente, e
# GITHUB_ENV può essere un file qualsiasi.

set -euo pipefail

errore() { printf '::error::%s\n' "$*" >&2; exit 1; }

scrivi_env() {
    if [ -n "${GITHUB_ENV:-}" ]; then
        printf '%s=%s\n' "$1" "$2" >> "$GITHUB_ENV"
    fi
    printf '%s=%s\n' "$1" "$2"
}

porta_libera() {
    # Il sistema assegna una porta libera a un socket legato alla porta 0; la
    # si legge e si chiude. Un altro processo potrebbe prenderla prima di noi:
    # è il caso che `avvia` riconosce e non scambia per un successo.
    php -r '$s = stream_socket_server("tcp://127.0.0.1:0", $n, $m) or exit(1); $n = stream_socket_get_name($s, false); fclose($s); echo substr($n, strrpos($n, ":") + 1);'
}

case "${1:-}" in
    scegli)
        PORTA=$(porta_libera) || errore "non riesco a trovare una porta libera"
        [ -n "$PORTA" ] || errore "porta vuota"
        CARTELLA="${RUNNER_TEMP:-$(mktemp -d)}"
        scrivi_env PORTA_SERVER "$PORTA"
        scrivi_env URL_SERVER "http://127.0.0.1:$PORTA"
        scrivi_env REGISTRO_SERVER "$CARTELLA/php-server-$PORTA.log"
        ;;

    avvia)
        : "${PORTA_SERVER:?PORTA_SERVER non c'è: prima «scegli»}"
        : "${REGISTRO_SERVER:?REGISTRO_SERVER non c'è: prima «scegli»}"
        ROUTER="${2:-}"
        : > "$REGISTRO_SERVER"
        if [ -n "$ROUTER" ]; then
            nohup php -S "127.0.0.1:$PORTA_SERVER" -t public/ "$ROUTER" >> "$REGISTRO_SERVER" 2>&1 &
        else
            nohup php -S "127.0.0.1:$PORTA_SERVER" -t public/ >> "$REGISTRO_SERVER" 2>&1 &
        fi
        PID=$!

        # Il processo deve restare vivo e dire che ha preso la porta. Con la
        # porta già presa, `php -S` scrive «Failed to listen» ed esce.
        for _ in $(seq 1 100); do
            if ! kill -0 "$PID" 2>/dev/null; then
                cat "$REGISTRO_SERVER" >&2 || true
                errore "il server PHP non è partito sulla porta $PORTA_SERVER (presa da un altro processo?): non si interroga il server di qualcun altro"
            fi
            if grep -q "(http://127.0.0.1:$PORTA_SERVER) started" "$REGISTRO_SERVER" 2>/dev/null; then
                break
            fi
            sleep 0.1
        done
        grep -q "(http://127.0.0.1:$PORTA_SERVER) started" "$REGISTRO_SERVER" \
            || { cat "$REGISTRO_SERVER" >&2 || true; errore "il server PHP non dice di aver preso la porta $PORTA_SERVER"; }

        # Un'ultima volta dopo la prima risposta: un processo che muore subito
        # dopo aver scritto «started» non deve passare.
        for _ in $(seq 1 50); do
            curl -fs -o /dev/null "http://127.0.0.1:$PORTA_SERVER/.well-known/security.txt" && break
            sleep 0.2
        done
        kill -0 "$PID" 2>/dev/null || errore "il server PHP è morto dopo l'avvio"
        curl -fs -o /dev/null "http://127.0.0.1:$PORTA_SERVER/.well-known/security.txt" \
            || { cat "$REGISTRO_SERVER" >&2 || true; errore "il server PHP non risponde su /.well-known/security.txt"; }
        scrivi_env PID_SERVER "$PID"
        ;;

    *)
        errore "uso: server-php.sh scegli | avvia [router.php]"
        ;;
esac
