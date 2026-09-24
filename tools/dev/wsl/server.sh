#!/usr/bin/env bash
#
# Il server di sviluppo in WSL: il server integrato di PHP col router della CI.
#
# Al posto del vhost di XAMPP (`pantedu.local`) c'è `php -S` con
# `tools/ci/router-php-server.php`, che si comporta come nginx in produzione:
# rotte con un punto nel nome instradate all'applicazione, e /css/, /js/,
# /img/ serviti dalle cartelle del repository. Su Linux
# `PHP_CLI_SERVER_WORKERS` funziona davvero (su Windows no: è solo POSIX),
# quindi le richieste in parallelo di una pagina non si mettono in fila.
#
# Uso:
#   bash tools/dev/wsl/server.sh           avvia su 127.0.0.1:8765
#   bash tools/dev/wsl/server.sh --ferma   lo spegne
#   PORTA=8766 bash tools/dev/wsl/server.sh
#
# Perché 8765. La 8000 era dei runner della CI, che stanno nella stessa istanza
# WSL e ci aprivano i workflow a11y, e2e e lighthouse (dal 14/9/2026 ogni job
# si fa dare una porta effimera, sopra la 32768): il 10/9/2026 un mio server
# di prova rimasto acceso lì ha fatto fallire un job di axe-core. La 8080, la
# scelta ovvia subito dopo, sulla macchina di riferimento era già presa dal
# contenitore di prova dell'immagine (`pantedu-prova`). Una porta poco comune
# collide meno; e se collide, questo script rifiuta di partire invece di far
# finta di essere già acceso.
#
# Il browser di Windows raggiunge http://127.0.0.1:8765 direttamente: WSL
# inoltra le porte della loopback.
set -euo pipefail

PORTA="${PORTA:-8765}"
RADICE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
STATO="${XDG_RUNTIME_DIR:-/tmp}"
LOG="$STATO/pantedu-server-${PORTA}.log"
PIDFILE="$STATO/pantedu-server-${PORTA}.pid"
URL="http://127.0.0.1:${PORTA}"

case "$RADICE" in
    /mnt/*) echo "Il repository sta in $RADICE, cioè sul disco di Windows: da lì è da 8 a 19 volte più lento. Clonalo in ~ (vedi docs/dev/sviluppo-in-wsl.md)."; exit 1 ;;
esac

# Si ferma con il file PID, non con `pkill -f "php -S …"`: lo schema combacia
# anche con la riga di comando di chi lo lancia, e una shell che contiene
# quella stringa si uccide da sola (successo tre volte in una notte).
ferma() {
    if [ -f "$PIDFILE" ]; then
        # Il server è capo del proprio gruppo (setsid, qui sotto): si ferma il
        # gruppo intero, così vanno via anche i processi figli dei worker.
        kill -- "-$(cat "$PIDFILE")" 2>/dev/null || kill "$(cat "$PIDFILE")" 2>/dev/null || true
        rm -f "$PIDFILE"
        echo "server su ${PORTA} fermato"
    else
        echo "nessun server avviato da questo script su ${PORTA}"
    fi
}

if [ "${1:-}" = "--ferma" ]; then
    ferma
    exit 0
fi

cd "$RADICE"

# 2026-09-23 — in sviluppo il limitatore delle richieste lo spegne
# `.env.local`, non più il `.env` versionato, che arriva anche in produzione
# (wiki/environment-variables.md). Se la chiave manca la si aggiunge, e prima
# del controllo «già acceso»: il server integrato rilegge i `.env` a ogni
# richiesta (misurato quel giorno), quindi rilanciare lo script basta anche
# con il server in piedi. Che la chiamata ci sia, e in questo punto, lo prova
# tests/ops/limitatore-sviluppo.test.sh.
bash "$RADICE/tools/dev/wsl/limitatore-sviluppo.sh" "$RADICE" \
    || echo "attenzione: .env.local non aggiornato, il limitatore delle richieste resta acceso (docs/dev/sviluppo-in-wsl.md)"

# «Già acceso» si decide dal file PID, mai dal fatto che qualcuno risponda.
#
# 2026-09-10 — la prima versione chiedeva `/.well-known/security.txt` e, se
# rispondeva 200, concludeva «sono io, già acceso». Sulla macchina di prova su
# 8080 rispondeva un altro server (nginx, nel contenitore `pantedu-prova`): lo
# script ha detto «già in ascolto» ed è uscito, e quattro controlli dopo sono
# passati misurando quell'altro. È lo stesso inganno che ha fatto fallire un
# job della CI sulla porta 8000.
nostro_vivo() { [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; }

if nostro_vivo; then
    echo "già in ascolto su $URL (pid $(cat "$PIDFILE"))"
    exit 0
fi
rm -f "$PIDFILE"

# Se sulla porta risponde qualcuno e non è questo script, è un altro server:
# si rifiuta di partire, invece di far interrogare lui a chi viene dopo. Due
# controlli, perché un servizio pubblicato da Docker Desktop o da Windows può
# rispondere senza comparire fra i processi di `ss`.
if ss -ltn 2>/dev/null | grep -qE "[:.]${PORTA}[[:space:]]" \
    || curl -s -o /dev/null --max-time 2 "$URL/" 2>/dev/null; then
    echo "sulla porta ${PORTA} risponde già un altro server, che non è stato avviato da qui:"
    echo "scegline un'altra con PORTA=… (e allinea APP_URL in .env.local)."
    exit 1
fi

PHP_CLI_SERVER_WORKERS=8 setsid nohup php -S "127.0.0.1:${PORTA}" -t public/ tools/ci/router-php-server.php \
    > "$LOG" 2>&1 &
echo $! > "$PIDFILE"

for _ in $(seq 1 25); do
    curl -fs "$URL/.well-known/security.txt" >/dev/null 2>&1 && break
    sleep 1
done
if nostro_vivo && curl -fs "$URL/.well-known/security.txt" >/dev/null 2>&1; then
    echo "in ascolto su $URL  (pid $(cat "$PIDFILE"), registro: $LOG)"
    echo "per la suite: la usa da sola (default di playwright.config.js)"
else
    echo "il server non risponde; ultime righe del registro:"
    tail -8 "$LOG"
    ferma
    exit 1
fi
