#!/usr/bin/env bash
# Phase 25.K.7 — Suricata IDS (Network Intrusion Detection).
# Rileva pattern attacchi sul traffic di rete: C2 beacon, exfiltration,
# malware DNS, network scan, exploit attempts.
#
# Mode: IDS (passive, log only) — NON IPS (no inline blocking).
# Ragione: IPS richiede modifiche routing/iptables più invasive.
# IDS è perfetto come HIDS visibility.

set -euo pipefail

G() { printf "\033[32m%s\033[0m\n" "$*"; }
C() { printf "\033[36m%s\033[0m\n" "$*"; }
R() { printf "\033[31m%s\033[0m\n" "$*"; }
W() { printf "\033[33m%s\033[0m\n" "$*"; }

[[ $EUID -ne 0 ]] && { R "Run come root"; exit 1; }

# ──────────────────────────────────────────────────────────────
# 1. Install Suricata + ruleset
# ──────────────────────────────────────────────────────────────
C "=== [1/4] Install Suricata ==="

if ! command -v suricata >/dev/null; then
    DEBIAN_FRONTEND=noninteractive apt-get install -qy suricata suricata-update
fi
G "  ✓ suricata installato: $(suricata --build-info | head -1 | tr -s ' ')"

# ──────────────────────────────────────────────────────────────
# 2. Update ruleset (ET Open free)
# ──────────────────────────────────────────────────────────────
C "=== [2/4] Update Emerging Threats Open ruleset ==="

# Default source = ET Open (free, ~40k rules)
suricata-update update-sources 2>&1 | tail -3
suricata-update enable-source et/open 2>&1 | tail -3
suricata-update 2>&1 | tail -5
G "  ✓ ET Open rules scaricate"

# ──────────────────────────────────────────────────────────────
# 3. Configura interfaces + EVE JSON log
# ──────────────────────────────────────────────────────────────
C "=== [3/4] Configure ==="

# Detect interface principale (quella con default route)
IFACE=$(ip -o -4 route show default | awk '{print $5}' | head -1)
[[ -z "$IFACE" ]] && { R "  ✗ Default interface non trovata"; exit 1; }
C "  Interface: $IFACE"

# Override interface in suricata.yaml (back-up + sed)
[[ ! -f /etc/suricata/suricata.yaml.bak.pantedu ]] && \
    cp -a /etc/suricata/suricata.yaml /etc/suricata/suricata.yaml.bak.pantedu

# Set interface for af-packet (modern, perf)
sed -i "/^af-packet:/,/^[a-z]/ s/interface: .*/interface: $IFACE/" /etc/suricata/suricata.yaml

# Imposta home_net (la propria IP). Per VPS, dynamic detect:
VPS_IP=$(ip -o -4 addr show "$IFACE" | awk '{print $4}' | cut -d/ -f1 | head -1)
[[ -n "$VPS_IP" ]] && sed -i "s|HOME_NET:.*|HOME_NET: \"[${VPS_IP}/32]\"|" /etc/suricata/suricata.yaml
G "  ✓ HOME_NET = $VPS_IP/32"

# Validate config
if suricata -T -c /etc/suricata/suricata.yaml --init-errors-fatal 2>&1 | tail -3; then
    G "  ✓ suricata config valid"
else
    W "  ⚠ suricata config warning (continua)"
fi

# ──────────────────────────────────────────────────────────────
# 4. Enable systemd + auto-update rules
# ──────────────────────────────────────────────────────────────
C "=== [4/4] Enable systemd ==="

systemctl enable --now suricata 2>&1 | head -3
sleep 3
if systemctl is-active --quiet suricata; then
    G "  ✓ suricata attivo"
else
    R "  ✗ suricata non parte. journalctl -u suricata -n 50"
    journalctl -u suricata -n 20 --no-pager | tail -20
    exit 2
fi

# Cron auto-update rules (settimanale)
cat > /etc/cron.weekly/suricata-update-rules <<'EOF'
#!/bin/sh
# Phase 25.K.7 — Auto-update Suricata rules (weekly).
/usr/bin/suricata-update 2>&1 >> /var/log/suricata-update.log
/bin/systemctl reload suricata 2>/dev/null
EOF
chmod 755 /etc/cron.weekly/suricata-update-rules
G "  ✓ cron weekly rules update"

# ──────────────────────────────────────────────────────────────
# Status finale
# ──────────────────────────────────────────────────────────────
echo
G "════════════════════════════════════════"
G "Phase 25.K.7 — Suricata NIDS attivo"
G "════════════════════════════════════════"
echo "Status:"
echo "  systemctl status suricata"
echo
echo "Log alert (EVE JSON):"
echo "  tail -f /var/log/suricata/eve.json | jq 'select(.event_type==\"alert\")'"
echo
echo "Stats:"
echo "  /var/log/suricata/stats.log"
echo
echo "Rules attive:"
echo "  /var/lib/suricata/rules/suricata.rules  (~40k ET Open)"
echo
echo "Cron weekly update:"
echo "  /etc/cron.weekly/suricata-update-rules"
echo
echo "Note: IDS mode (log only). Per IPS attivo:"
echo "  1. Build af-packet IPS mode (richiede 2 interface o copy-mode)"
echo "  2. NFQUEUE integration con iptables"
echo "  Skip per ora — log alerts sufficienti per visibility."
