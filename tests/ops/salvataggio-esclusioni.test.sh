#!/bin/bash
#
# Prove delle esclusioni del salvataggio notturno, nei due versi (20/9/2026).
#
# Il difetto che le ha fatte nascere: il commento del fascicolo diceva che i
# PDF compilati restano fuori perché si rigenerano, e l'esclusione scritta era
# `./tex_pdf` — una cartella dove non scrive nessuno. I PDF veri stanno in
# `./verifiche/tex_pdf` (FileController::saveVerificaPdf), ed entravano tutti
# nel fascicolo di ogni notte: la riga proteggeva la cartella vuota e
# archiviava quella piena.
#
# Qui si costruisce un albero dei dati finto e si esegue `tar` con le
# esclusioni PRESE DAL FILE VERO (`--exclude='…'` letti da
# tools/backup/encrypted_backup.sh): se un giorno l'elenco cambia, questa prova
# misura l'elenco nuovo e non una sua copia invecchiata.
#
# Uso: bash tests/ops/salvataggio-esclusioni.test.sh
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
SCRIPT="${SCRIPT_SALVATAGGIO:-$QUI/../../tools/backup/encrypted_backup.sh}"
T=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/esclusioni.XXXXXX")
trap 'rm -rf "$T"' EXIT

PASSATE=0
FALLITE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

[ -f "$SCRIPT" ] || { echo "manca $SCRIPT"; exit 1; }

# ── L'albero dei dati, come è fatto in produzione ─────────────────────────
STORAGE="$T/storage"
for d in objects data security security/alerts templates logs \
         temp verifiche/temp verifiche/tex_pdf tex_pdf \
         maps_enc verifiche_enc cache sessions backups geoip ca-bundle; do
    mkdir -p "$STORAGE/$d"
    echo contenuto > "$STORAGE/$d/file.txt"
done
echo '{"soglie":1}' > "$STORAGE/security/alerts/config.json"
echo '[]'           > "$STORAGE/security/blocked_ips.json"
echo vecchio        > "$STORAGE/logs/access_log.json.gz"
echo registro       > "$STORAGE/logs/access_log.json"

# ── Le esclusioni vere, lette dallo script ────────────────────────────────
ESCLUSIONI=()
while IFS= read -r riga; do
    ESCLUSIONI+=("$riga")
done < <(grep -oE -- "--exclude='[^']+'" "$SCRIPT" | sed "s/--exclude='//; s/'$//" | sort -u)

[ "${#ESCLUSIONI[@]}" -gt 0 ] || { echo "nessuna --exclude trovata in $SCRIPT"; exit 1; }

OPZIONI=()
for e in "${ESCLUSIONI[@]}"; do
    OPZIONI+=("--exclude=$e")
done

FASCICOLO="$T/fascicolo.tar"
tar "${OPZIONI[@]}" -cf "$FASCICOLO" -C "$STORAGE" . || { echo "tar fallito"; exit 1; }
ELENCO="$T/elenco.txt"
tar -tf "$FASCICOLO" > "$ELENCO"

dentro() { grep -q "^\./$1/" "$ELENCO"; }

# ── Verso 1: quello che si rigenera resta FUORI ───────────────────────────
# `verifiche/tex_pdf` è il motivo di questa prova: è dove finiscono davvero i
# PDF compilati.
for d in temp verifiche/temp verifiche/tex_pdf tex_pdf maps_enc verifiche_enc \
         cache sessions backups geoip ca-bundle; do
    if dentro "$d"; then
        ko "«$d/» si rigenera (o sta su B2) e non deve entrare nel fascicolo"
    else
        ok
    fi
done

# I registri ruotati no, quelli vivi sì.
grep -q '^\./logs/access_log\.json\.gz$' "$ELENCO" \
    && ko "i registri ruotati (.gz) non devono entrare" || ok

# ── Verso 2: quello che NON si rigenera ENTRA ─────────────────────────────
# Senza questa metà, la prova di sopra la supererebbe un fascicolo vuoto.
for d in objects data security templates logs; do
    if dentro "$d"; then
        ok
    else
        ko "«$d/» è un dato, e nel fascicolo ci deve stare"
    fi
done

# Le soglie degli allarmi hanno il file come unica copia: si nominano una per
# una, perché è il caso in cui perderle non si recupera da nessuna parte.
for f in ./security/alerts/config.json ./security/blocked_ips.json ./logs/access_log.json; do
    grep -qx -- "$f" "$ELENCO" && ok || ko "«$f» deve stare nel fascicolo"
done

echo "esclusioni del salvataggio: $PASSATE passate, $FALLITE fallite"
[ "$FALLITE" -eq 0 ]
