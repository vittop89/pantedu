#!/usr/bin/env bash
# Phase 25.K — Master runner: apply hardening 01-09 in sequence.
# Check HTTPS app health between each step. Stop on first failure.
#
# Usage:
#   sudo bash tools/dev/hardening/00-run-all.sh
#   sudo SKIP_STEPS="06,08" bash tools/dev/hardening/00-run-all.sh  # skip ModSec+Loki

set -euo pipefail

G() { printf "\033[32m%s\033[0m\n" "$*"; }
C() { printf "\033[36m%s\033[0m\n" "$*"; }
R() { printf "\033[31m%s\033[0m\n" "$*"; }
W() { printf "\033[33m%s\033[0m\n" "$*"; }

[[ $EUID -ne 0 ]] && { R "Run come root"; exit 1; }

DIR=$(dirname "$0")
SKIP_STEPS="${SKIP_STEPS:-}"

check_app_health() {
    local code
    code=$(curl -sS -o /dev/null -w '%{http_code}' -m 10 https://beta.pantedu.eu/ 2>&1 || echo "000")
    if [[ "$code" =~ ^(200|301|302)$ ]]; then
        G "  ✓ app HTTPS $code"
        return 0
    else
        R "  ✗ app HTTPS $code — INVESTIGATE!"
        return 1
    fi
}

# Pre-flight: app baseline OK?
C "═══ Pre-flight: app baseline ═══"
check_app_health || { R "App down PRIMA dell'hardening. Aborto."; exit 1; }

for i in 01 02 03 04 05 06 07 08 09; do
    script="$DIR/${i}-"*.sh
    script=$(ls $script 2>/dev/null | head -1)
    [[ -z "$script" || ! -f "$script" ]] && { W "Step $i script missing, skip"; continue; }

    if [[ ",${SKIP_STEPS}," == *",${i},"* ]]; then
        W "═══ Step $i: SKIPPED via SKIP_STEPS env ═══"
        continue
    fi

    echo
    C "═══════════════════════════════════════════"
    C "Step $i: $(basename $script)"
    C "═══════════════════════════════════════════"

    if bash "$script" 2>&1 | tail -30; then
        G "  ✓ Step $i completato"
    else
        R "  ✗ Step $i FAILED. Aborto."
        exit 2
    fi

    # Health check post-step
    sleep 2
    if ! check_app_health; then
        R "App rotta dopo step $i. Investigare PRIMA di proseguire."
        exit 3
    fi
done

echo
G "════════════════════════════════════════════════════"
G "All hardening steps applied successfully"
G "════════════════════════════════════════════════════"
echo "Summary:"
echo "  Step 01: SSH + sysctl + unattended-upgrades"
echo "  Step 02: fail2ban WAF integration"
echo "  Step 03: AIDE + Lynis + CT log monitor"
echo "  Step 04: Backup encrypted infrastructure (config manuale residua)"
echo "  Step 05: PHP-FPM systemd sandboxing"
echo "  Step 06: ModSecurity v3 + OWASP CRS (detection-only)"
echo "  Step 07: Suricata NIDS"
echo "  Step 08: Loki + Promtail + Grafana"
echo "  Step 09: MariaDB server_audit + hardening"
echo
echo "MANUAL TODO post-install:"
echo "  - Edit /etc/pantedu/backup.env (passphrase + remote)"
echo "  - Grafana http://VPS_IP:3000  (cambia password admin)"
echo "  - Monitor /var/log/modsec_audit.log 24-48h, poi switch enforce"
echo "  - Run lynis audit system per nuovo score post-hardening"
