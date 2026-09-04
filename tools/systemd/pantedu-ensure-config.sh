#!/bin/bash
# pantedu-ensure-config — garantisce .env + storage dirs prima dell'avvio app.
# Installato su VPS in /usr/local/sbin/, agganciato a pantedu-ensure-config.service.
# Idempotente. Gira a ogni boot (Before=php8.4-fpm). Rimedia perdite da spegnimento
# sporco (incidente 2026-07-07: crash → persi .env + storage/logs + storage/sessions
# → DB_ENABLED tornava false → login HTTP 500).
set -e
REPO=/var/www/pantedu
# 1. .env: se manca o non ha DB_ENABLED=true, ricrea da .env.example (i segreti
#    reali restano in .env.local, caricato con precedenza dal bootstrap).
if [ ! -f "$REPO/.env" ] || ! grep -q '^DB_ENABLED=true' "$REPO/.env"; then
  cp "$REPO/.env.example" "$REPO/.env"
  chown pantedu:www-data "$REPO/.env"; chmod 640 "$REPO/.env"
  logger -t pantedu-ensure 'ricreato .env da .env.example'
fi
# 2. storage dirs critiche
for d in logs sessions cache; do
  if [ ! -d "$REPO/storage/$d" ]; then
    mkdir -p "$REPO/storage/$d"
    chown www-data:www-data "$REPO/storage/$d"; chmod 775 "$REPO/storage/$d"
    logger -t pantedu-ensure "ricreato storage/$d"
  fi
done
exit 0
