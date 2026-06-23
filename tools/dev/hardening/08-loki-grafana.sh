#!/usr/bin/env bash
# Phase 25.K.8 — Loki + Promtail + Grafana stack (centralized logs).
#
# Stack lightweight: ~200MB RAM, Loki single-binary mode.
# Sources: nginx, php-fpm, auth.log, syslog, suricata eve.json, modsec_audit.
# UI: Grafana su porta 3000 (auth admin/password generato), reverse-proxy
# nginx via /grafana/ (Phase 25.K.8.bis se vuoi public-facing).
#
# Install via Docker compose (cleaner) o systemd (più leggero).
# Faccio systemd binary install: meno layer, meno dipendenze.

set -euo pipefail

G() { printf "\033[32m%s\033[0m\n" "$*"; }
C() { printf "\033[36m%s\033[0m\n" "$*"; }
R() { printf "\033[31m%s\033[0m\n" "$*"; }
W() { printf "\033[33m%s\033[0m\n" "$*"; }

[[ $EUID -ne 0 ]] && { R "Run come root"; exit 1; }

LOKI_VERSION="${LOKI_VERSION:-3.3.2}"
GRAFANA_VERSION="${GRAFANA_VERSION:-11.5.0}"
PROMTAIL_VERSION="${PROMTAIL_VERSION:-3.3.2}"

# ──────────────────────────────────────────────────────────────
# 1. Add Grafana apt repo (più semplice per Grafana)
# ──────────────────────────────────────────────────────────────
C "=== [1/5] Grafana apt repo ==="

apt-get install -qy apt-transport-https software-properties-common wget gnupg 2>&1 | tail -3
mkdir -p /etc/apt/keyrings/
wget -qO - https://apt.grafana.com/gpg.key | gpg --dearmor -o /etc/apt/keyrings/grafana.gpg 2>/dev/null
echo "deb [signed-by=/etc/apt/keyrings/grafana.gpg] https://apt.grafana.com stable main" \
    > /etc/apt/sources.list.d/grafana.list
apt-get update 2>&1 | tail -3
G "  ✓ Grafana repo aggiunto"

# ──────────────────────────────────────────────────────────────
# 2. Install Grafana + Loki + Promtail
# ──────────────────────────────────────────────────────────────
C "=== [2/5] Install Grafana ==="
DEBIAN_FRONTEND=noninteractive apt-get install -qy grafana 2>&1 | tail -3
G "  ✓ Grafana installato"

C "=== [3/5] Install Loki + Promtail binaries ==="
# Loki + Promtail NON sono in apt repo Grafana stabile. Download binari.
INSTALL_DIR="/usr/local/bin"
mkdir -p /var/lib/loki /etc/loki /etc/promtail

if [[ ! -x "$INSTALL_DIR/loki" ]]; then
    wget -qO /tmp/loki.zip "https://github.com/grafana/loki/releases/download/v${LOKI_VERSION}/loki-linux-amd64.zip"
    unzip -p /tmp/loki.zip > "$INSTALL_DIR/loki"
    chmod 755 "$INSTALL_DIR/loki"
    rm /tmp/loki.zip
fi

if [[ ! -x "$INSTALL_DIR/promtail" ]]; then
    wget -qO /tmp/promtail.zip "https://github.com/grafana/loki/releases/download/v${PROMTAIL_VERSION}/promtail-linux-amd64.zip"
    unzip -p /tmp/promtail.zip > "$INSTALL_DIR/promtail"
    chmod 755 "$INSTALL_DIR/promtail"
    rm /tmp/promtail.zip
fi
G "  ✓ Loki + Promtail binaries installati"

# ──────────────────────────────────────────────────────────────
# 4. Config Loki + Promtail
# ──────────────────────────────────────────────────────────────
C "=== [4/5] Config Loki + Promtail ==="

cat > /etc/loki/loki-config.yaml <<'EOF'
# Phase 25.K.8 — Loki single-binary, filesystem-backed.
auth_enabled: false
server:
  http_listen_port: 3100
  grpc_listen_port: 9095
  log_level: warn

common:
  instance_addr: 127.0.0.1
  path_prefix: /var/lib/loki
  storage:
    filesystem:
      chunks_directory: /var/lib/loki/chunks
      rules_directory: /var/lib/loki/rules
  replication_factor: 1
  ring:
    kvstore:
      store: inmemory

schema_config:
  configs:
    - from: 2024-01-01
      store: tsdb
      object_store: filesystem
      schema: v13
      index:
        prefix: index_
        period: 24h

ruler:
  alertmanager_url: http://localhost:9093

limits_config:
  retention_period: 30d            # retention 30 giorni
  max_query_length: 720h           # 30 giorni max query
  max_query_series: 5000
  reject_old_samples: true
  reject_old_samples_max_age: 168h

compactor:
  working_directory: /var/lib/loki/compactor
  retention_enabled: true
  delete_request_store: filesystem
EOF

cat > /etc/promtail/promtail-config.yaml <<'EOF'
# Phase 25.K.8 — Promtail scrape config.
server:
  http_listen_port: 9080
  grpc_listen_port: 0
  log_level: warn

positions:
  filename: /var/lib/promtail/positions.yaml

clients:
  - url: http://127.0.0.1:3100/loki/api/v1/push

scrape_configs:
  - job_name: nginx
    static_configs:
      - targets: [localhost]
        labels:
          job: nginx
          host: pantedu-vps
          __path__: /var/log/nginx/*.log

  - job_name: php-fpm
    static_configs:
      - targets: [localhost]
        labels:
          job: php-fpm
          host: pantedu-vps
          __path__: /var/log/php8.4-fpm.log

  - job_name: syslog
    static_configs:
      - targets: [localhost]
        labels:
          job: syslog
          host: pantedu-vps
          __path__: /var/log/syslog

  - job_name: auth
    static_configs:
      - targets: [localhost]
        labels:
          job: auth
          host: pantedu-vps
          __path__: /var/log/auth.log

  - job_name: waf-blocked
    static_configs:
      - targets: [localhost]
        labels:
          job: waf-blocked
          host: pantedu-vps
          __path__: /var/log/pantedu-waf-blocked.log

  - job_name: suricata
    static_configs:
      - targets: [localhost]
        labels:
          job: suricata
          host: pantedu-vps
          __path__: /var/log/suricata/eve.json
    pipeline_stages:
      - json:
          expressions:
            event_type: event_type
            severity: alert.severity
      - labels:
          event_type:

  - job_name: pantedu-deploy
    static_configs:
      - targets: [localhost]
        labels:
          job: deploy
          host: pantedu-vps
          __path__: /var/log/pantedu-deploy.log

  - job_name: aide
    static_configs:
      - targets: [localhost]
        labels:
          job: aide
          host: pantedu-vps
          __path__: /var/log/aide-check.log

  - job_name: fail2ban
    static_configs:
      - targets: [localhost]
        labels:
          job: fail2ban
          host: pantedu-vps
          __path__: /var/log/fail2ban.log
EOF

mkdir -p /var/lib/promtail
chown -R loki:loki /var/lib/loki 2>/dev/null || useradd -r -s /usr/sbin/nologin loki && mkdir -p /var/lib/loki && chown -R loki:loki /var/lib/loki
chown -R nobody:nogroup /var/lib/promtail 2>/dev/null || true
G "  ✓ config Loki + Promtail"

# Systemd units
cat > /etc/systemd/system/loki.service <<'EOF'
[Unit]
Description=Loki log aggregation (Phase 25.K.8)
After=network.target

[Service]
Type=simple
User=loki
Group=loki
ExecStart=/usr/local/bin/loki -config.file=/etc/loki/loki-config.yaml
Restart=on-failure
RestartSec=5
LimitNOFILE=65536

[Install]
WantedBy=multi-user.target
EOF

cat > /etc/systemd/system/promtail.service <<'EOF'
[Unit]
Description=Promtail log shipper (Phase 25.K.8)
After=loki.service

[Service]
Type=simple
User=root
ExecStart=/usr/local/bin/promtail -config.file=/etc/promtail/promtail-config.yaml
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now loki promtail grafana-server
sleep 5

# ──────────────────────────────────────────────────────────────
# 5. Status check
# ──────────────────────────────────────────────────────────────
C "=== [5/5] Status check ==="
for svc in loki promtail grafana-server; do
    if systemctl is-active --quiet "$svc"; then
        G "  ✓ $svc active"
    else
        R "  ✗ $svc NOT active"
        journalctl -u "$svc" -n 10 --no-pager | tail -10
    fi
done

# Test Loki health
if curl -sf -m 5 "http://127.0.0.1:3100/ready" >/dev/null 2>&1; then
    G "  ✓ Loki ready"
fi

# Auto-config Loki datasource in Grafana (REST API)
GRAFANA_PASS=$(cat /etc/grafana/grafana.ini | grep -E "^admin_password" | awk '{print $3}' 2>/dev/null || echo "admin")
sleep 5
curl -sS -u "admin:${GRAFANA_PASS}" -H "Content-Type: application/json" \
    -X POST "http://127.0.0.1:3000/api/datasources" \
    -d '{"name":"Loki","type":"loki","url":"http://127.0.0.1:3100","access":"proxy","isDefault":true}' 2>&1 | head -3

echo
G "════════════════════════════════════════"
G "Phase 25.K.8 — Loki+Promtail+Grafana DONE"
G "════════════════════════════════════════"
echo "Grafana UI: http://127.0.0.1:3000  (admin/admin — CAMBIA password!)"
echo "Loki:       http://127.0.0.1:3100"
echo
echo "PROSSIMI STEP:"
echo "1. Login Grafana http://VPS_IP:3000 + cambia password admin"
echo "   Per public: configurare nginx reverse-proxy /grafana/ (Phase 25.K.8.bis)"
echo "2. Datasource Loki auto-configurato. Test Explore con query:"
echo "   {job=\"nginx\"} |= \"403\""
echo "   {job=\"waf-blocked\"} | json"
echo "   {job=\"suricata\"} | json | event_type=\"alert\""
echo "3. Importa dashboard nginx (id 12559) o crea custom"
echo
echo "Retention: 30 giorni (limits_config.retention_period in /etc/loki/loki-config.yaml)"
