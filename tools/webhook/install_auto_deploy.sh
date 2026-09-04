#!/bin/bash
# Phase 25.R.21 — Setup auto-deploy via webhook + systemd Path unit.
#
# Esegue (idempotente):
#   1. Crea dir /var/lib/pantedu-deploy (owner www-data, mode 0750)
#   2. Copia systemd unit files da repo a /etc/systemd/system/
#   3. systemctl daemon-reload + enable --now pantedu-deploy.path
#   4. Verifica che la Path unit sia active
#
# Idempotente: può essere chiamato N volte senza side effects.
# Chiamato automaticamente dal deploy.sh se le unit non sono installate.

set -euo pipefail

REPO_DIR="${REPO_DIR:-/var/www/pantedu}"
SYSTEMD_DIR=/etc/systemd/system
TRIGGER_DIR=/var/lib/pantedu-deploy

echo "==> Phase 25.R.21 — Install auto-deploy webhook+path"

echo "==> 1. Crea trigger directory $TRIGGER_DIR"
if [ ! -d "$TRIGGER_DIR" ]; then
    install -d -o www-data -g www-data -m 0750 "$TRIGGER_DIR"
    echo "    OK: dir creata (owner www-data, mode 0750)"
else
    # Assicura ownership/mode correct (defensive)
    chown www-data:www-data "$TRIGGER_DIR"
    chmod 0750 "$TRIGGER_DIR"
    echo "    OK: dir già presente, perms refreshed"
fi

echo "==> 2. Copia systemd unit files"
for unit in pantedu-deploy.path pantedu-deploy.service; do
    SRC="$REPO_DIR/tools/systemd/$unit"
    DST="$SYSTEMD_DIR/$unit"
    if [ ! -f "$SRC" ]; then
        echo "    ✗ ERROR: $SRC missing"
        exit 1
    fi
    if [ -f "$DST" ] && cmp -s "$SRC" "$DST"; then
        echo "    skip: $unit already up-to-date"
    else
        cp "$SRC" "$DST"
        chmod 0644 "$DST"
        echo "    OK: $unit installed"
    fi
done

echo "==> 2b. Install trigger pre-exec script"
TRIG_SCRIPT_SRC="$REPO_DIR/tools/systemd/pantedu-deploy-trigger.sh"
TRIG_SCRIPT_DST=/usr/local/bin/pantedu-deploy-trigger.sh
if [ ! -f "$TRIG_SCRIPT_SRC" ]; then
    echo "    ✗ ERROR: $TRIG_SCRIPT_SRC missing"
    exit 1
fi
if [ -f "$TRIG_SCRIPT_DST" ] && cmp -s "$TRIG_SCRIPT_SRC" "$TRIG_SCRIPT_DST"; then
    echo "    skip: trigger script already up-to-date"
else
    cp "$TRIG_SCRIPT_SRC" "$TRIG_SCRIPT_DST"
    chmod 0755 "$TRIG_SCRIPT_DST"
    chown root:root "$TRIG_SCRIPT_DST"
    echo "    OK: $TRIG_SCRIPT_DST installed"
fi

echo "==> 3. Drop-in PHP-FPM ReadWritePaths whitelist"
PHP_DROPIN_DIR=/etc/systemd/system/php8.4-fpm.service.d
PHP_DROPIN_SRC="$REPO_DIR/tools/systemd/php8.4-fpm.service.d/pantedu-auto-deploy.conf"
PHP_DROPIN_DST="$PHP_DROPIN_DIR/pantedu-auto-deploy.conf"
RESTART_PHP=0
if [ ! -f "$PHP_DROPIN_DST" ] || ! cmp -s "$PHP_DROPIN_SRC" "$PHP_DROPIN_DST"; then
    install -d "$PHP_DROPIN_DIR"
    cp "$PHP_DROPIN_SRC" "$PHP_DROPIN_DST"
    chmod 0644 "$PHP_DROPIN_DST"
    RESTART_PHP=1
    echo "    OK: drop-in installato (PHP-FPM restart required)"
else
    echo "    skip: drop-in PHP-FPM già up-to-date"
fi

echo "==> 4. systemctl daemon-reload + enable Path unit"
systemctl daemon-reload
systemctl enable --now pantedu-deploy.path

if [ "$RESTART_PHP" = 1 ]; then
    echo "==> 4b. Restart PHP-FPM (drop-in nuovo, no reload sufficient)"
    systemctl restart php8.4-fpm
    sleep 2
    systemctl is-active php8.4-fpm
fi
# .service è enabled-by-path, NON enable manualmente (sarebbe orphan).

echo "==> 4. Verifica stato"
echo "--- pantedu-deploy.path ---"
systemctl is-active pantedu-deploy.path
systemctl is-enabled pantedu-deploy.path
echo "--- pantedu-deploy.service ---"
systemctl is-enabled pantedu-deploy.service 2>&1 | grep -E 'static|enabled|disabled' || true

echo
echo "==> ✅ Setup auto-deploy completo."
echo
echo "Test manuale (simula trigger):"
echo "  sudo -u www-data bash -c 'echo \"{\\\"delivery\\\":\\\"manual-test-\$(date +%s)\\\",\\\"head\\\":\\\"\$(git -C $REPO_DIR rev-parse HEAD)\\\"}\" > $TRIGGER_DIR/trigger'"
echo "  → osserva: sudo journalctl -u pantedu-deploy.service -f"
