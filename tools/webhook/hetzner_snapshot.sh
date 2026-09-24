#!/bin/bash
# Phase 25.R follow-up — Crea snapshot Hetzner Cloud del VPS app.
# Idempotente, retention automatica. Usato pre-deploy + manuale.
# Costo: ~€0.95/snapshot/mese per VPS 80GB. Default retention=2 → ~€1.90/mese.
set -euo pipefail

CONF=/etc/pantedu/hetzner-api.env
if [[ ! -f $CONF ]]; then
    echo "[snapshot] WARN: $CONF mancante — skip (configura per abilitare)"
    exit 0
fi
source $CONF
if [[ -z "${HETZNER_API_TOKEN:-}" ]]; then
    echo "[snapshot] WARN: HETZNER_API_TOKEN vuoto — skip"
    exit 0
fi

LABEL="${1:-auto-$(date +%Y%m%d-%H%M%S)}"
RETENTION="${SNAPSHOT_RETENTION_COUNT:-2}"

API=https://api.hetzner.cloud/v1
AUTH="Authorization: Bearer $HETZNER_API_TOKEN"

# Trova server ID dal nome
SERVER_ID=$(curl -sf -H "$AUTH" "$API/servers?name=$HETZNER_SERVER_NAME" |     python3 -c "import sys,json; d=json.load(sys.stdin); print(d['servers'][0]['id'] if d['servers'] else '')")
if [[ -z "$SERVER_ID" ]]; then
    echo "[snapshot] ERROR: server $HETZNER_SERVER_NAME non trovato"
    exit 1
fi

# Crea snapshot
echo "[snapshot] Creating snapshot '$LABEL' for server $SERVER_ID..."
curl -sf -X POST -H "$AUTH" -H "Content-Type: application/json"     -d "{\"type\":\"snapshot\",\"description\":\"$LABEL\"}"     "$API/servers/$SERVER_ID/actions/create_image" | python3 -m json.tool | head -5

# Aspetta che la creazione sia indicizzata nell'API (3 sec per essere safe)
sleep 3

# Rotation: cattura snapshot con label che inizia con auto-* o pre-deploy-*.
# Mantieni gli ultimi RETENTION (i più recenti, in ordine created:desc),
# cancella il resto.
echo "[snapshot] Rotation: keeping last $RETENTION snapshots (auto-* + pre-deploy-*)..."
OLD_IDS=$(curl -sf -H "$AUTH" "$API/images?type=snapshot&sort=created:desc" |     RETENTION="$RETENTION" python3 -c "
import sys, json, os
retention = int(os.environ['RETENTION'])
d = json.load(sys.stdin)
managed = [
    i for i in d.get('images', [])
    if (i.get('description') or '').startswith(('auto-', 'pre-deploy-'))
]
to_delete = managed[retention:]
for img in to_delete:
    print(img['id'])
")
for ID in $OLD_IDS; do
    echo "  delete snapshot $ID"
    curl -sf -X DELETE -H "$AUTH" "$API/images/$ID" > /dev/null
done
echo "[snapshot] Done"
