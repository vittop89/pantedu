#!/bin/bash
# Phase 25.R.21 — ExecStartPre del service pantedu-deploy.service.
#
# Replay protection: legge delivery UUID dal trigger file e lo confronta
# con last-uuid. Se identico, exit 3 → SuccessExitStatus=3 nel service
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
    # 2026-09-08 — era `exit 1`, e il service aveva `SuccessExitStatus=1`.
    # Ma `deploy.sh` esce con 1 proprio quando il rilascio e' FALLITO: quella
    # riga faceva passare per riuscito ogni deploy andato male, e l'unita' non
    # poteva mai finire in `failed`. Tutto il meccanismo `DEPLOY_FAILURES`
    # nello script era neutralizzato da una riga di configurazione.
    # Adesso il replay ha un codice suo, e l'1 torna a significare guasto.
    exit 3   # SuccessExitStatus=3 → service salta ExecStart senza errore
fi

# 2026-09-08 — finestra oraria: se siamo dentro l'orario in cui non si
# rilascia, si mette in attesa invece di rilasciare.
#
# Non si consuma l'identificativo: il rilascio differito fara' ripartire
# questo stesso script quando la finestra si apre, e allora proseguira'.
# Rifiutare e basta perderebbe il rilascio, che e' peggio del ritardo.
#
# Senza /etc/pantedu/deploy-window.conf questa parte non fa niente.
# shellcheck source=/dev/null
. /usr/local/bin/pantedu-finestra-rilascio.sh
if finestra_chiusa; then
    echo "[$(date -Iseconds)] [deploy-trigger] finestra chiusa: rilascio in attesa (uuid=$NEW_UUID)"
    : > /var/lib/pantedu-deploy/in-attesa
    exit 3
fi
rm -f /var/lib/pantedu-deploy/in-attesa

# Aggiorna last-uuid (atomic via tempfile)
TMP="$LAST.tmp.$$"
echo "$NEW_UUID" > "$TMP"
chmod 640 "$TMP"
mv "$TMP" "$LAST"

echo "[$(date -Iseconds)] [deploy-trigger] new trigger uuid=$NEW_UUID"
exit 0
