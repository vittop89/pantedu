#!/bin/bash
#
# Prova del passo 8-quater di `tools/webhook/deploy-container.sh`, nei due
# versi (24/9/2026, ADR-050): il rilascio porta nella biblioteca dell'istanza
# i modelli TikZ versionati, lanciando `tools/tikz/sincronizza_modelli.php
# --apply` dentro il container NUOVO come www-data; se lo script fallisce
# avvisa e lascia una riga fra le anomalie, **senza fermare il rilascio**.
#
# Le regole dell'allineamento (crea, aggiorna, non tocca i cambiati dal
# pannello) le prova tests/Unit/Services/Tikz/ModelliTikzVersionatiTest.php;
# qui si prova che il rilascio lo chiami, dove, come chi, e che cosa fa
# quando non va.
#
# Niente root e niente macchina vera: al posto di docker un comando finto che
# annota come è stato chiamato. La funzione è quella vera, presa dallo script
# fra i segni `>>> host` e `<<< host` (e `>>> tex` / `<<< tex`, dove sta
# `anomalia_rilascio`).
#
# Uso: bash tests/ops/modelli-tikz-rilascio.test.sh
#      SCRIPT=<altro script> bash tests/ops/modelli-tikz-rilascio.test.sh   (per provarne un'altra versione)
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
SCRIPT="${SCRIPT:-$QUI/../../tools/webhook/deploy-container.sh}"
T=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/modelli-tikz.XXXXXX")
trap 'rm -rf "$T"' EXIT

PASSATE=0
FALLITE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

mkdir -p "$T/bin" "$T/stato"
cat > "$T/bin/docker" <<'FINTO'
#!/bin/bash
echo "docker $*" >> "$REGISTRO"
if [ -f "$STATO/sincronizza-rotto" ]; then
    echo "modelli TikZ: manifesto_file_mancante: fisica/cinematica-1d.tex" >&2
    exit 1
fi
echo "[AGGIORNA] gruppo-FISICA / cinematica 1D (fisica/cinematica-1d.tex) — aggiornato"
echo "APPLY — creati: 0, aggiornati: 1, allineati: 0, cambiati dal pannello: 0"
exit 0
FINTO
chmod +x "$T/bin/docker"
export REGISTRO="$T/comandi.log" STATO="$T/stato"

sed -n '/^# >>> tex:/,/^# <<< tex/p; /^# >>> host:/,/^# <<< host/p' "$SCRIPT" > "$T/funzioni.sh"
grep -q '^passo_modelli_tikz()' "$T/funzioni.sh" || echo "  (non trovo passo_modelli_tikz fra i segni >>> host / <<< host in $SCRIPT)"

# Il passo come lo esegue lo script vero, con `set -euo pipefail`.
# Le variabili le leggono le funzioni caricate con `.`, che shellcheck non segue.
# shellcheck disable=SC2034
lancia() {
    : > "$REGISTRO"
    rm -rf "$T/dati"
    mkdir -p "$T/dati/storage/logs"
    (
        set -euo pipefail
        PATH="$T/bin:$PATH"
        DATI="$T/dati"
        ANOMALIE="$T/dati/storage/logs/anomalie.jsonl"
        NUOVO="pantedu-app-b"
        VECCHIO="pantedu-app-a"
        COMMIT="0123456789abcdef0123456789abcdef01234567"
        nota() { echo "NOTA $*"; }
        avviso() { echo "AVVISO $*"; }
        # shellcheck source=/dev/null
        . "$T/funzioni.sh"
        passo_modelli_tikz
        echo "USCITA $?"
    ) 2>&1
}
anomalie() { cat "$T/dati/storage/logs/anomalie.jsonl" 2>/dev/null || true; }
json_valido() { python3 -c 'import json,sys; [json.loads(r) for r in open(sys.argv[1]) if r.strip()]' "$T/dati/storage/logs/anomalie.jsonl" 2>/dev/null; }
ATTESO="docker exec -u www-data pantedu-app-b php /var/www/pantedu/tools/tikz/sincronizza_modelli.php --apply"

echo "── Lo script va: chiamato nel container nuovo, come www-data, con --apply"
rm -f "$STATO/sincronizza-rotto"
USCITA=$(lancia)
if grep -qxF "$ATTESO" "$REGISTRO"; then ok; else ko "chiamata diversa da quella attesa: $(cat "$REGISTRO")"; fi
if ! grep -q 'pantedu-app-a' "$REGISTRO"; then ok; else ko "chiamato nel container VECCHIO: $(cat "$REGISTRO")"; fi
if grep -q '^\[tikz\] \[AGGIORNA\] gruppo-FISICA / cinematica 1D' <<<"$USCITA"; then ok; else ko "le righe dello script non arrivano nel registro come [tikz]: $USCITA"; fi
if grep -q 'modelli TikZ allineati' <<<"$USCITA"; then ok; else ko "$USCITA"; fi
if ! grep -q '^AVVISO' <<<"$USCITA"; then ok; else ko "avviso senza guasto: $USCITA"; fi
if [ -z "$(anomalie)" ]; then ok; else ko "anomalia scritta senza guasto: $(anomalie)"; fi
if grep -q '^USCITA 0' <<<"$USCITA"; then ok; else ko "uscita: $USCITA"; fi

echo "── Lo script fallisce: avviso, riga fra le anomalie, il rilascio prosegue"
touch "$STATO/sincronizza-rotto"
USCITA=$(lancia)
if grep -q '^\[tikz\] modelli TikZ: manifesto_file_mancante' <<<"$USCITA"; then ok; else ko "il motivo del guasto non arriva nel registro: $USCITA"; fi
if grep -q "^AVVISO l'allineamento dei modelli TikZ è fallito (uscita 1)" <<<"$USCITA"; then ok; else ko "nessun avviso con l'uscita dello script: $USCITA"; fi
if ! grep -q 'modelli TikZ allineati' <<<"$USCITA"; then ok; else ko "dice «allineati» dopo un guasto: $USCITA"; fi
if grep -q '"codice":"modelli_tikz"' <<<"$(anomalie)"; then ok; else ko "nessuna riga fra le anomalie: $(anomalie)"; fi
if json_valido; then ok; else ko "la riga fra le anomalie non è JSON: $(anomalie)"; fi
if grep -q '^USCITA 0' <<<"$USCITA"; then ok; else ko "il guasto dei modelli ferma il rilascio: $USCITA"; fi

echo "── Il passo è nel percorso del rilascio, dopo le versioni legali"
RIGA_LEGALI=$(grep -n '^passo_versioni_legali$' "$SCRIPT" | cut -d: -f1)
RIGA_TIKZ=$(grep -n '^passo_modelli_tikz$' "$SCRIPT" | cut -d: -f1)
if [ -n "$RIGA_TIKZ" ] && [ -n "$RIGA_LEGALI" ] && [ "$RIGA_TIKZ" -gt "$RIGA_LEGALI" ]; then ok; else ko "passo_modelli_tikz non è chiamato dopo passo_versioni_legali (righe: legali=$RIGA_LEGALI tikz=$RIGA_TIKZ)"; fi

echo "── Esito: $PASSATE passate, $FALLITE fallite"
[ "$FALLITE" -eq 0 ]
