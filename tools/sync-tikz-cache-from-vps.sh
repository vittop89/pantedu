#!/usr/bin/env bash
# Sync cache TikZ SVG locale dal VPS (per vedere le figure in locale).
#
# Perché serve: su PROD il timer systemd `pantedu-tikz-prewarm` precompila
# ogni notte i TikZ → SVG in storage/cache/tikz/. In LOCALE quel prewarm NON
# gira e non c'è il microservizio tex-compile (:8001) per il compile live →
# GET /tikz/render = cache miss (204) → POST /tikz/render = 422 (compile_failed,
# :8001 down). Questo script copia la cache SVG già pronta dal VPS, così i
# lookup locali fanno HIT e le figure si caricano (niente compile necessario).
#
# Pre-requisiti: SSH `pantedu-vps` configurato (~/.ssh/config). Verrà chiesta
# la passphrase della chiave (interattivo).
#
# Usage:
#   bash tools/sync-tikz-cache-from-vps.sh        (oppure `! bash tools/...` in Claude Code)

set -euo pipefail

REMOTE_BASE=/var/www/pantedu/storage/cache   # dir contenente tikz/
REMOTE_DIR=tikz
LOCAL_BASE="$(cd "$(dirname "$0")/.." && pwd)/storage/cache"

mkdir -p "$LOCAL_BASE"

echo "[1/2] tar+base64 da pantedu-vps:$REMOTE_BASE/$REMOTE_DIR ..."
# base64 per evitare che il banner SSH del VPS corrompa lo stream binario
# (stesso accorgimento di sync-db-from-vps.sh).
ssh -q pantedu-vps "tar -C '$REMOTE_BASE' -czf - '$REMOTE_DIR' 2>/dev/null | base64" \
    | base64 -d | tar -C "$LOCAL_BASE" -xzf -

echo "[2/2] OK — $(find "$LOCAL_BASE/$REMOTE_DIR" -name '*.svg' 2>/dev/null | wc -l) SVG in cache locale."
echo "      Ricarica la pagina esercizio: i GET /tikz/render ora fanno HIT (200), niente POST/422."
