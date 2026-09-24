#!/bin/bash
#
# Le risposte che non passano dall'applicazione: divieti, librerie del
# browser, alias, PHP che non c'è. Le stesse per il nginx del container
# (`docker/nginx.conf`) e per il router dello sviluppo
# (`tools/ci/router-php-server.php`), con un elenco solo (14/9/2026).
#
# Perché. La suite in CI gira contro l'immagine del rilascio, lo sviluppo su
# `php -S` con il router. Il router diceva di comportarsi come nginx e divergeva:
# serviva `/views/`, passava all'applicazione un `.php` inesistente, serviva
# `/vendor/` mentre in produzione nginx rispondeva 403 e le pagine degli
# esercizi restavano senza editor e senza formule (#97). Una prova verde in un
# posto e rossa nell'altro fa cercare un difetto che non c'è, o nasconde quello
# che c'è.
#
# Uso:
#   bash tests/ops/rotte-come-nginx.test.sh              avvia il router e lo prova
#   URL=http://127.0.0.1:PORTA bash tests/ops/rotte-come-nginx.test.sh
#                                                         prova un server già acceso
#                                                         (il container, in immagine.yml)
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
RADICE=$(cd "$QUI/../.." && pwd)

PASSATE=0
FALLITE=0

# «codice percorso»: niente di tutto questo arriva all'applicazione, quindi non
# serve un database.
ATTESE=(
    # Le librerie del browser in public/vendor; quella di Composer no. Il
    # campione è l'unico file versionato di public/vendor (MathJax lo copia la
    # build): era quill.min.js, tolto il 23/9/2026 (D-2). Se si toglie anche
    # il foglio di stile di Quill, qui serve un altro file versionato lì.
    "200 /vendor/quill/1.3.6/quill.snow.css"
    "404 /vendor/autoload.php"
    "404 /vendor/composer/installed.json"
    # I divieti.
    "403 /.env"
    "403 /views/admin/Elementi_Riservati.html"
    "403 /app/bootstrap.php"
    "403 /composer.json"
    "403 /tools/migrate.php"
    "403 /storage/version.txt"
    # Il PHP che non c'è.
    "404 /eser/sc/eser_sc2s/MAT/2.0_MAT-Sistemi_lineari-sc2s.php"
    "404 /log/auth/login.php"
    # Gli alias delle cartelle fuori da public/, e i file di public/.
    "200 /css/main.css"
    "404 /css/questo-foglio-non-esiste.css"
    "200 /img/close.svg"
    "200 /sw.js"
    "200 /favicon.svg"
)

PID=""
if [ -z "${URL:-}" ]; then
    T=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/rotte.XXXXXX")
    trap '[ -n "$PID" ] && kill "$PID" 2>/dev/null; rm -rf "$T"' EXIT
    cd "$RADICE" || exit 1
    GITHUB_ENV="$T/env" RUNNER_TEMP="$T" bash tools/ci/server-php.sh scegli >/dev/null
    set -a; . "$T/env"; set +a
    GITHUB_ENV="$T/env" bash tools/ci/server-php.sh avvia tools/ci/router-php-server.php >/dev/null \
        || { echo "il router non parte"; cat "$REGISTRO_SERVER"; exit 1; }
    PID=$(grep '^PID_SERVER=' "$T/env" | tail -1 | cut -d= -f2)
    URL="$URL_SERVER"
    echo "router su $URL"
fi

for riga in "${ATTESE[@]}"; do
    atteso=${riga%% *}
    percorso=${riga#* }
    avuto=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$URL$percorso" || echo 000)
    if [ "$avuto" = "$atteso" ]; then
        PASSATE=$((PASSATE + 1))
        printf '  %s  %s\n' "$avuto" "$percorso"
    else
        FALLITE=$((FALLITE + 1))
        printf '  FALLITA: %s risponde %s, doveva %s\n' "$percorso" "$avuto" "$atteso"
    fi
done

echo "rotte come nginx ($URL): $PASSATE passate, $FALLITE fallite"
[ "$FALLITE" -eq 0 ]
