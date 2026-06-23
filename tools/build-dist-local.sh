#!/usr/bin/env bash
# Rigenera artifact build (CSS bundle + JS cache-bust dist) in locale.
# Usage:
#   bash tools/build-dist-local.sh           # genera bundle + dist
#   bash tools/build-dist-local.sh --clean   # rimuove artifact (dev/debug)
#
# Quando usare:
#  - "build": testare local prod-like (assicura che bundle/dist contengano
#    le tue ultime modifiche). Il deploy.sh VPS lo fa automaticamente.
#  - "clean": tornare al modo DEV con file CSS/JS individuali, utile per
#    debug DevTools (sources singoli invece di bundle gigante).
#
# In dev (--clean), head.php usa fallback graceful: main.css con @import
# nested + bootstrap.js (no cache-bust query negli import). I file
# individuali si modificano e refresh browser → cambi immediati.

set -euo pipefail
cd "$(dirname "$0")/.."

if [[ "${1:-}" == "--clean" ]]; then
    rm -f css/main.bundle.css js/modules/bootstrap.dist.js
    echo "[clean] removed bundle/dist (dev mode)"
    exit 0
fi

echo "[build] CSS bundle..."
php tools/build-css-bundle.php
echo "[build] JS cache-bust dist..."
php tools/build-js-cache-bust.php
echo "[build] OK"
