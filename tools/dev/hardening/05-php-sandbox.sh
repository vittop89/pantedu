#!/usr/bin/env bash
# Phase 25.K.5 — Install PHP-FPM systemd sandboxing drop-in.
# Apply: copy → daemon-reload → restart php-fpm → smoke test app.
# Rollback automatico se HTTPS app non risponde 200 post-restart.

set -euo pipefail

G() { printf "\033[32m%s\033[0m\n" "$*"; }
C() { printf "\033[36m%s\033[0m\n" "$*"; }
R() { printf "\033[31m%s\033[0m\n" "$*"; }
W() { printf "\033[33m%s\033[0m\n" "$*"; }

[[ $EUID -ne 0 ]] && { R "Run come root"; exit 1; }

REPO_DIR="/var/www/pantedu"
DROP_DIR="/etc/systemd/system/php8.4-fpm.service.d"
DROP_FILE="$DROP_DIR/pantedu-sandbox.conf"

C "=== [1/3] Install sandbox drop-in ==="
mkdir -p "$DROP_DIR"
install -m 644 "${REPO_DIR}/tools/systemd/php8.4-fpm.service.d/pantedu-sandbox.conf" "$DROP_FILE"
systemctl daemon-reload
G "  ✓ drop-in installato"

C "=== [2/3] Restart php8.4-fpm ==="
systemctl restart php8.4-fpm
sleep 2
if ! systemctl is-active --quiet php8.4-fpm; then
    R "  ✗ php-fpm NON attivo dopo restart → rollback"
    rm -f "$DROP_FILE"
    systemctl daemon-reload
    systemctl restart php8.4-fpm
    R "  ✗ Sandbox FAILED — rollback applicato. Check journalctl -u php8.4-fpm -n 50"
    exit 2
fi
G "  ✓ php8.4-fpm attivo"

C "=== [3/3] Smoke test HTTPS ==="
# Test che l'app risponde 200 (homepage / login)
HTTP_CODE=$(curl -sS -o /dev/null -w '%{http_code}' -m 10 https://beta.pantedu.eu/ 2>&1 || echo "000")
if [[ "$HTTP_CODE" =~ ^(200|302|301)$ ]]; then
    G "  ✓ HTTPS app risponde $HTTP_CODE"
else
    R "  ✗ HTTPS app risponde $HTTP_CODE → SANDBOX ROMPE L'APP"
    R "  Rollback automatico…"
    rm -f "$DROP_FILE"
    systemctl daemon-reload
    systemctl restart php8.4-fpm
    R "  Investigare: journalctl -u php8.4-fpm -n 100 + nginx error.log"
    exit 3
fi

echo
G "════════════════════════════════════════"
G "Phase 25.K.5 — PHP-FPM sandboxing OK"
G "════════════════════════════════════════"
echo "Verify sandbox attivo:"
echo "  systemctl show php8.4-fpm | grep -E 'ProtectSystem|ReadWritePaths|NoNewPrivileges'"
echo
echo "Test compromise simulation (NON eseguire in prod!):"
echo "  sudo -u www-data cat /etc/sudoers   # → Permission denied (atteso)"
echo "  sudo -u www-data ls /root           # → no such file (atteso)"
echo
echo "Rollback (in caso di problemi futuri):"
echo "  sudo rm $DROP_FILE"
echo "  sudo systemctl daemon-reload && sudo systemctl restart php8.4-fpm"
