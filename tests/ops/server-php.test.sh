#!/bin/bash
#
# Prove di `tools/ci/server-php.sh`, nei due versi (14/9/2026).
#
# Il difetto che l'ha fatto nascere: due job sulla stessa macchina, la stessa
# porta 8000, e il secondo che interroga il server del primo credendolo suo.
# Qui si riproduce: un «altro job» occupa una porta con un suo server, e lo
# script, avviato su quella porta, deve fermarsi invece di dire «avviato». Nel
# verso buono, su una porta libera, deve partire e rispondere con i file suoi.
#
# Uso: bash tests/ops/server-php.test.sh
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
SCRIPT="${SCRIPT:-$QUI/../../tools/ci/server-php.sh}"
T=$(mktemp -d)
PIDS=()
pulisci() {
    for p in "${PIDS[@]:-}"; do [ -n "$p" ] && kill "$p" 2>/dev/null; done
    rm -rf "$T"
}
trap pulisci EXIT

PASSATE=0
FALLITE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

# Due radici con un security.txt diverso: si capisce chi risponde.
mkdir -p "$T/nostro/public/.well-known" "$T/altro/public/.well-known"
echo "nostro" > "$T/nostro/public/.well-known/security.txt"
echo "altro"  > "$T/altro/public/.well-known/security.txt"

leggi() { grep "^$1=" "$2" | tail -1 | cut -d= -f2-; }

# ── Verso buono: porta libera ─────────────────────────────────────────────
ENVF="$T/env-buono"
( cd "$T/nostro" && GITHUB_ENV="$ENVF" RUNNER_TEMP="$T" bash "$SCRIPT" scegli >/dev/null ) \
    && ok || ko "scegli non riesce"
PORTA=$(leggi PORTA_SERVER "$ENVF")
REG=$(leggi REGISTRO_SERVER "$ENVF")
[[ "$PORTA" =~ ^[0-9]+$ ]] && ok || ko "PORTA_SERVER non è un numero: «$PORTA»"
[ "$(leggi URL_SERVER "$ENVF")" = "http://127.0.0.1:$PORTA" ] && ok || ko "URL_SERVER sbagliato"
case "$REG" in "$T"/*) ok ;; *) ko "il registro non sta nella cartella del job: $REG" ;; esac

if ( cd "$T/nostro" && GITHUB_ENV="$ENVF" PORTA_SERVER="$PORTA" REGISTRO_SERVER="$REG" bash "$SCRIPT" avvia >/dev/null 2>"$T/err-buono" ); then
    ok
else
    ko "avvia su una porta libera non riesce: $(cat "$T/err-buono")"
fi
PID=$(leggi PID_SERVER "$ENVF"); PIDS+=("$PID")
[ "$(curl -fs "http://127.0.0.1:$PORTA/.well-known/security.txt")" = "nostro" ] \
    && ok || ko "sulla porta scelta non risponde il nostro server"

# ── Verso cattivo: la porta è di un altro job ─────────────────────────────
ALTRA=$( cd "$T" && RUNNER_TEMP="$T" bash "$SCRIPT" scegli | grep '^PORTA_SERVER=' | cut -d= -f2 )
( cd "$T/altro" && php -S "127.0.0.1:$ALTRA" -t public/ >"$T/altro.log" 2>&1 ) &
PIDS+=("$!")
for _ in $(seq 1 50); do curl -fs -o /dev/null "http://127.0.0.1:$ALTRA/.well-known/security.txt" && break; sleep 0.1; done
[ "$(curl -fs "http://127.0.0.1:$ALTRA/.well-known/security.txt")" = "altro" ] \
    && ok || ko "la prova non è riuscita a mettere l'altro server sulla porta"

# Il controllo di prima (curl sulla porta) si farebbe ingannare: risponde l'altro.
ENVC="$T/env-cattivo"
if ( cd "$T/nostro" && GITHUB_ENV="$ENVC" PORTA_SERVER="$ALTRA" REGISTRO_SERVER="$T/reg-cattivo.log" bash "$SCRIPT" avvia >/dev/null 2>"$T/err-cattivo" ); then
    ko "avvia ha detto «avviato» su una porta già presa da un altro server"
else
    ok
fi
grep -q "non è partito sulla porta $ALTRA" "$T/err-cattivo" && ok || ko "il messaggio non dice perché: $(cat "$T/err-cattivo")"
grep -q '^PID_SERVER=' "$ENVC" 2>/dev/null && ko "ha scritto PID_SERVER per un server che non è suo" || ok

# ── Senza «scegli» si ferma ───────────────────────────────────────────────
( cd "$T/nostro" && env -u PORTA_SERVER -u REGISTRO_SERVER bash "$SCRIPT" avvia >/dev/null 2>&1 ) \
    && ko "avvia senza porta non si è fermato" || ok

echo "server-php.sh: $PASSATE passate, $FALLITE fallite"
[ "$FALLITE" -eq 0 ]
