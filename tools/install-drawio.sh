#!/bin/bash
# G22.S15.bis Fase 5 — Self-host drawio webapp
#
# Scarica la build statica drawio (release ufficiale jgraph/drawio) e la
# installa in public/drawio-app/. Idempotente: se la versione corrente
# corrisponde a $DRAWIO_VERSION, skip download.
#
# Su VPS produzione viene chiamato dal webhook deploy.sh dopo git pull.
# In dev: bash tools/install-drawio.sh per (re)installare.

set -euo pipefail

DRAWIO_VERSION="${DRAWIO_VERSION:-v29.7.12}"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="$ROOT_DIR/public/drawio-app"
VERSION_FILE="$DEST/.drawio-version"
PATCHES_DIR="$ROOT_DIR/tools/drawio-patches"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

echo "==> drawio install: target version = $DRAWIO_VERSION"

# Funzione applicazione patches: sempre idempotente.
apply_patches() {
    if [ ! -d "$PATCHES_DIR" ]; then return 0; fi
    echo "==> Apply Pantedu patches"
    if compgen -G "$PATCHES_DIR"/*.js > /dev/null; then
        cp "$PATCHES_DIR"/*.js "$DEST/js/"
        echo "    copied $(ls "$PATCHES_DIR"/*.js | wc -l) patch files to js/"
    fi
    INDEX="$DEST/index.html"
    if [ -f "$INDEX" ] && ! grep -q 'pantedu-library-relay' "$INDEX"; then
        # Inietta script tag prima di </body> (sed-based, portatile).
        # Compatibile macOS/Linux/Git-Bash Windows (no python path issues).
        TMP_INDEX="$INDEX.tmp.$$"
        if grep -q '</body>' "$INDEX"; then
            sed 's|</body>|<script src="js/pantedu-library-relay.js"></script>\n</body>|' "$INDEX" > "$TMP_INDEX"
        elif grep -q '</html>' "$INDEX"; then
            sed 's|</html>|<script src="js/pantedu-library-relay.js"></script>\n</html>|' "$INDEX" > "$TMP_INDEX"
        else
            cat "$INDEX" > "$TMP_INDEX"
            echo '<script src="js/pantedu-library-relay.js"></script>' >> "$TMP_INDEX"
        fi
        mv "$TMP_INDEX" "$INDEX"
        echo "    injected script tag in index.html"
    else
        echo "    index.html gia' patchato (skip)"
    fi
}

# Skip download se gia' presente alla versione richiesta, MA applica
# patches comunque (idempotenti, gestiscono update plugin senza re-download).
if [ -f "$VERSION_FILE" ] && [ "$(cat "$VERSION_FILE")" = "$DRAWIO_VERSION" ]; then
    echo "    OK: $DEST gia' a $DRAWIO_VERSION, skip download"
    apply_patches
    exit 0
fi

# Backup .htaccess locale (custom CSP/cache, non in zip drawio)
HTACCESS_BAK=""
if [ -f "$DEST/.htaccess" ]; then
    HTACCESS_BAK="$TMP_DIR/.htaccess.bak"
    cp "$DEST/.htaccess" "$HTACCESS_BAK"
    echo "==> backup .htaccess locale"
fi

# Download draw.war (release artifact). curl --location segue redirect github.
URL="https://github.com/jgraph/drawio/releases/download/${DRAWIO_VERSION}/draw.war"
echo "==> Download $URL"
if ! curl -fsSL --max-time 120 -o "$TMP_DIR/draw.war" "$URL"; then
    echo "ERROR: download fallito (curl exit=$?)" >&2
    exit 1
fi
SIZE=$(stat -c%s "$TMP_DIR/draw.war" 2>/dev/null || stat -f%z "$TMP_DIR/draw.war" 2>/dev/null || wc -c < "$TMP_DIR/draw.war")
echo "    size: $SIZE bytes"
if [ "$SIZE" -lt 1000000 ]; then
    echo "ERROR: file scaricato troppo piccolo ($SIZE bytes), probabile errore HTTP" >&2
    head -c 500 "$TMP_DIR/draw.war" >&2
    exit 1
fi

# Extract (.war e' uno ZIP standard) — fallback unzip/python3/jar
echo "==> Extract"
mkdir -p "$TMP_DIR/extract"
if command -v unzip >/dev/null 2>&1; then
    echo "    using: unzip"
    unzip -q "$TMP_DIR/draw.war" -d "$TMP_DIR/extract"
elif command -v python3 >/dev/null 2>&1; then
    echo "    using: python3 zipfile"
    python3 -c "
import zipfile, sys
z = zipfile.ZipFile('$TMP_DIR/draw.war')
z.extractall('$TMP_DIR/extract')
print(f'    extracted {len(z.namelist())} entries', file=sys.stderr)
"
elif command -v jar >/dev/null 2>&1; then
    echo "    using: jar (Java)"
    (cd "$TMP_DIR/extract" && jar xf "$TMP_DIR/draw.war")
else
    echo "ERROR: nessun tool di estrazione disponibile (servono: unzip OR python3 OR jar)" >&2
    echo "  Installa con: apt install unzip   (Debian/Ubuntu)" >&2
    exit 1
fi

# Wipe destination + replace
echo "==> Replace $DEST"
rm -rf "$DEST"
mkdir -p "$DEST"
cp -r "$TMP_DIR/extract"/* "$DEST/"

# Rimuovi parti Java-specific (server-side, non servono per static)
rm -rf "$DEST/META-INF" "$DEST/WEB-INF"

# Restore .htaccess locale
if [ -n "$HTACCESS_BAK" ]; then
    cp "$HTACCESS_BAK" "$DEST/.htaccess"
    echo "==> restored .htaccess locale"
fi

apply_patches

# Stamp version
echo "$DRAWIO_VERSION" > "$VERSION_FILE"

# Compute final size
FINAL_SIZE=$(du -sh "$DEST" | cut -f1)
echo "==> OK: drawio $DRAWIO_VERSION installato in $DEST ($FINAL_SIZE)"
