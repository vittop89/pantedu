#!/usr/bin/env bash
#
# In sviluppo il limitatore delle richieste è spento: aggiunge
# `RATE_LIMIT_DISABLED=1` a `.env.local`, se la chiave non c'è già.
#
# PERCHÉ (23/9/2026)
#   Fino a quel giorno lo spegneva il `.env` versionato, che il rilascio monta
#   anche nel container di produzione (revisione architetturale del 23/9/2026,
#   A-4). Ora `.env` dice 0, e in sviluppo il bypass sta in `.env.local`: ce
#   lo mette questo script, che `server.sh` chiama a ogni avvio. Gli altri
#   posti dove vale 1: wiki/environment-variables.md.
#
#   Senza, la suite end-to-end in locale gira col limitatore acceso: le
#   richieste dello stesso utente si sommano nella finestra di un minuto, e
#   le spec cominciano a ricevere 429 che non c'entrano con quello che provano.
#
# REGOLE
#   - se la chiave c'è già, con qualunque valore, non tocca niente: è una
#     scelta di chi sviluppa (con 0 si prova il limitatore dal browser);
#   - una riga commentata non conta: l'applicazione non la vede;
#   - se il file non finisce con un a capo, lo aggiunge prima delle righe
#     nuove: altrimenti il commento si incollerebbe all'ultimo valore. Dotenv
#     lo taglierebbe (misurato il 23/9/2026), ma il caricatore di
#     playwright.config.js taglia i commenti solo dopo uno spazio: una
#     password di prova in ultima riga arriverebbe alla suite guastata;
#   - se `.env.local` non c'è, lo crea leggibile solo da chi lo possiede;
#   - se `.env.local` è un collegamento (le copie di lavoro lo collegano a
#     quello di ~/pantedu), scrive nel file a cui punta.
#   Non legge né stampa i valori: cerca solo il nome della chiave.
#
# Uso: bash tools/dev/wsl/limitatore-sviluppo.sh [radice]
#      (senza argomento: la radice del repository che contiene lo script)
# Prova: tests/ops/limitatore-sviluppo.test.sh
set -euo pipefail

RADICE="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"
[ -d "$RADICE" ] || { echo "la cartella $RADICE non esiste" >&2; exit 1; }
FILE="$RADICE/.env.local"

if [ -f "$FILE" ] && grep -qE '^[[:space:]]*(export[[:space:]]+)?RATE_LIMIT_DISABLED[[:space:]]*=' "$FILE"; then
    exit 0
fi

if [ ! -e "$FILE" ]; then
    (umask 077 && : > "$FILE")
elif [ -n "$(tail -c 1 "$FILE")" ]; then
    # La sostituzione di comando toglie gli a capo finali: se resta qualcosa,
    # l'ultimo carattere non è un a capo.
    printf '\n' >> "$FILE"
fi

cat >> "$FILE" <<'RIGHE'
# Aggiunta da tools/dev/wsl/limitatore-sviluppo.sh (lo chiama server.sh): in
# sviluppo il limitatore delle richieste è spento. Il .env versionato lo tiene
# acceso, perché arriva anche in produzione (docs/dev/sviluppo-in-wsl.md).
# Con 0 lo si prova dal browser.
RATE_LIMIT_DISABLED=1
RIGHE
echo "aggiunta RATE_LIMIT_DISABLED=1 a $FILE: in sviluppo il limitatore è spento (docs/dev/sviluppo-in-wsl.md)"
