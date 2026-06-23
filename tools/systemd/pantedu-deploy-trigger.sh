#!/bin/bash
# Phase 25.R.21 — ExecStartPre del service pantedu-deploy.service.
#
# Replay protection: legge delivery UUID dal trigger file e lo confronta
# con last-uuid. Se identico, exit 1 → SuccessExitStatus=1 nel service
# unit fa skip del deploy senza errore.
#
# Separato da systemd unit inline per evitare hell di quoting bash/systemd.
# Owned root:root, mode 0755, eseguito dal service unit (root context).

set -uo pipefail

TRIG=/var/lib/pantedu-deploy/trigger
LAST=/var/lib/pantedu-deploy/last-uuid

if [ ! -r "$TRIG" ]; then
    echo "[$(date -Iseconds)] [deploy-trigger] no trigger file, skip"
    exit 0
fi

# Estrai delivery UUID dal JSON. Pattern semplice (NO Perl regex) per
# evitare escaping nightmare:
NEW_UUID=$(grep -E '"delivery"' "$TRIG" | sed -E 's/.*"delivery"[[:space:]]*:[[:space:]]*"([^"]+)".*/\1/')

if [ -z "$NEW_UUID" ]; then
    NEW_UUID=unknown
fi

OLD_UUID=$(cat "$LAST" 2>/dev/null || echo none)

if [ "$NEW_UUID" = "$OLD_UUID" ] && [ "$NEW_UUID" != "unknown" ]; then
    echo "[$(date -Iseconds)] [deploy-trigger] replay ignored: uuid=$NEW_UUID"
    exit 1   # SuccessExitStatus=1 → service skips ExecStart senza error
fi

# Aggiorna last-uuid (atomic via tempfile)
TMP="$LAST.tmp.$$"
echo "$NEW_UUID" > "$TMP"
chmod 640 "$TMP"
mv "$TMP" "$LAST"

echo "[$(date -Iseconds)] [deploy-trigger] new trigger uuid=$NEW_UUID"
exit 0
