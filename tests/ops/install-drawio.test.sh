#!/bin/bash
#
# Prove di `tools/install-drawio.sh`, nei due versi (15/9/2026).
#
# Lo script adesso lo lancia la costruzione dell'immagine (stadio `drawio` del
# Dockerfile), che scarica draw.war da GitHub a ogni costruzione senza cache.
# Prima non verificava niente del file scaricato oltre alla dimensione.
#
# Qui niente rete: un draw.war finto (uno zip con un index.html e un file che lo
# porta sopra il minimo di un megabyte) servito con `file://`, e una copia dello
# script in una radice finta. Con l'impronta sbagliata non deve installare
# niente; con quella giusta installa e applica la patch di Pantedu.
#
# Uso: bash tests/ops/install-drawio.test.sh
set -uo pipefail

QUI=$(cd "$(dirname "$0")" && pwd)
RADICE_VERA=$(cd "$QUI/../.." && pwd)
T=$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/install-drawio.XXXXXX")
trap 'rm -rf "$T"' EXIT

PASSATE=0
FALLITE=0
ok() { PASSATE=$((PASSATE + 1)); }
ko() { FALLITE=$((FALLITE + 1)); echo "  FALLITA: $*"; }

# Il draw.war finto. Il byte casuale non si comprime, così lo zip resta sopra il
# minimo che lo script accetta.
python3 - "$T/draw.war" <<'PY'
import os, sys, zipfile
with zipfile.ZipFile(sys.argv[1], "w", zipfile.ZIP_STORED) as z:
    z.writestr("index.html", "<!DOCTYPE html><html><body><p>drawio finto</p></body></html>\n")
    z.writestr("js/app.min.js", "/* drawio finto */\n")
    z.writestr("peso.bin", os.urandom(1_100_000))
    z.writestr("WEB-INF/web.xml", "<web-app/>\n")
PY
GIUSTA=$(sha256sum "$T/draw.war" | cut -d' ' -f1)

# radice NOME: una copia dello script e delle patch, come nello stadio del Dockerfile.
radice() {
    mkdir -p "$T/$1/tools"
    cp "$RADICE_VERA/tools/install-drawio.sh" "$T/$1/tools/install-drawio.sh"
    cp -r "$RADICE_VERA/tools/drawio-patches" "$T/$1/tools/drawio-patches"
}

# ── Verso cattivo: un file con un'altra impronta non si installa ──────────
radice sbagliata
OUT=$(DRAWIO_URL="file://$T/draw.war" DRAWIO_SHA256=$(printf '0%.0s' {1..64}) \
      bash "$T/sbagliata/tools/install-drawio.sh" 2>&1)
ESITO=$?
[ "$ESITO" -ne 0 ] && ok || ko "impronta sbagliata: esito $ESITO, doveva fallire"
case "$OUT" in *"sha256 di draw.war $GIUSTA"*"non si installa"*) ok ;; *) ko "impronta sbagliata: messaggio «$OUT»" ;; esac
[ ! -e "$T/sbagliata/public/drawio-app" ] && ok || ko "impronta sbagliata: public/drawio-app è stato creato"

# Senza DRAWIO_SHA256 vale quella della versione fissata, che il file finto non ha.
radice predefinita
OUT=$(DRAWIO_URL="file://$T/draw.war" bash "$T/predefinita/tools/install-drawio.sh" 2>&1)
[ $? -ne 0 ] && [ ! -e "$T/predefinita/public/drawio-app" ] \
    && ok || ko "impronta predefinita: il file finto è stato installato («$OUT»)"

# ── Verso buono: l'impronta giusta installa, con la patch ─────────────────
radice giusta
OUT=$(DRAWIO_URL="file://$T/draw.war" DRAWIO_SHA256="$GIUSTA" \
      bash "$T/giusta/tools/install-drawio.sh" 2>&1)
ESITO=$?
[ "$ESITO" -eq 0 ] && ok || ko "impronta giusta: esito $ESITO («$OUT»)"
DEST="$T/giusta/public/drawio-app"
grep -q 'pantedu-library-relay' "$DEST/index.html" 2>/dev/null \
    && ok || ko "impronta giusta: index.html senza la patch di Pantedu"
[ -s "$DEST/js/pantedu-library-relay.js" ] && ok || ko "impronta giusta: manca js/pantedu-library-relay.js"
[ ! -e "$DEST/WEB-INF" ] && ok || ko "impronta giusta: è rimasta la parte Java (WEB-INF)"
grep -qx 'v29.7.12' "$DEST/.drawio-version" 2>/dev/null \
    && ok || ko "impronta giusta: .drawio-version dice «$(cat "$DEST/.drawio-version" 2>/dev/null)»"

echo "install-drawio: $PASSATE passate, $FALLITE fallite"
[ "$FALLITE" -eq 0 ]
