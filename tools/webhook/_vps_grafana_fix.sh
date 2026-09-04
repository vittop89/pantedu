#!/bin/bash
# Fix Grafana "No data": start monitoring services + rebind grafana datasource
# to pantedu DB (was pointing to dropped fismapant database).

set -e
echo "=== DB users ==="
mysql -BN -e "SELECT user, host FROM mysql.user WHERE user LIKE 'grafana%' OR user LIKE 'fismapant%' OR user LIKE 'pantedu%'"

echo "=== Pantedu grants for grafana_ro ==="
mysql -BN -e "SHOW GRANTS FOR 'grafana_ro'@'localhost'" 2>&1 | head -10 || true

echo "=== Ensure grafana_ro has SELECT on pantedu ==="
mysql -e "GRANT SELECT ON pantedu.* TO 'grafana_ro'@'localhost'"
mysql -e "FLUSH PRIVILEGES"

echo "=== Rebind grafana datasource fismapant -> pantedu ==="
SRC=/etc/grafana/provisioning/datasources/fismapant-mysql.yaml
DST=/etc/grafana/provisioning/datasources/pantedu-mysql.yaml
if [ -f "$SRC" ]; then
    sed -e 's/fismapant-mysql/pantedu-mysql/g' \
        -e 's/database: fismapant/database: pantedu/g' \
        "$SRC" > "$DST"
    chown root:grafana "$DST"
    chmod 0640 "$DST"
    rm -f "$SRC"
    echo "  renamed + rewrote $DST"
    cat "$DST"
fi

echo "=== Start monitoring stack ==="
for svc in loki promtail prometheus suricata grafana-server; do
    if systemctl list-unit-files "$svc.service" --no-pager >/dev/null 2>&1; then
        systemctl is-enabled "$svc" >/dev/null 2>&1 || systemctl enable "$svc" 2>/dev/null || true
        systemctl start "$svc" 2>/dev/null || true
        echo "  $svc: $(systemctl is-active $svc)"
    else
        echo "  $svc: NOT INSTALLED"
    fi
done

echo "=== Restart grafana to pickup datasource change ==="
systemctl restart grafana-server
sleep 3
systemctl is-active grafana-server

echo "=== Verify datasource connectivity ==="
sleep 2
curl -s -u "admin:${GRAFANA_ADMIN_PASS:?set GRAFANA_ADMIN_PASS}" http://127.0.0.1:3000/api/datasources 2>&1 | head -c 500 || true
echo ""

echo "=== DONE ==="
