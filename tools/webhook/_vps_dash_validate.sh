#!/bin/bash
for f in /var/lib/grafana/dashboards/*.json; do
    if python3 -c "import json; json.load(open('$f'))" 2>/dev/null; then
        echo "OK   $f"
    else
        echo "FAIL $f"
        python3 -c "import json; json.load(open('$f'))" 2>&1 | head -3
    fi
done

echo "--- check Grafana logged provisioning details ---"
journalctl -u grafana-server --since '5 min ago' --no-pager | grep -iE 'provision|dashboard.*err|dashboard.*warn' | tail -15
echo "--- force provisioning reload via touch dashboards ---"
touch /var/lib/grafana/dashboards/*.json
sleep 3
echo "--- post-touch dashboard rows ---"
sqlite3 -header -column /var/lib/grafana/grafana.db "SELECT id, uid, title FROM dashboard;"
sqlite3 -header -column /var/lib/grafana/grafana.db "SELECT id, uid, title FROM folder;"
